<?php
/**
 * Enchilada Comal — Select Reactor (stream_select fallback)
 *
 * Pure PHP event loop using stream_select(). Works everywhere without
 * PECL extensions. Timer precision is limited to ~10ms (select granularity).
 *
 * This is the fallback when neither ext-ev nor ext-event is available.
 *
 * @package    Enchilada\Comal
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

namespace Enchilada\Comal;

class SelectReactor implements ReactorInterface
{
	/** @var array<string, array{stream: resource, callback: callable}> */
	private array $readers = [];

	/** @var array<string, array{stream: resource, callback: callable}> */
	private array $writers = [];

	/** @var array<string, array{due: float, interval: float|null, callback: callable}> */
	private array $timers = [];

	/** @var array<string, array{signal: int, callback: callable}> */
	private array $signals = [];

	/** @var int Monotonic counter for watcher IDs */
	private int $nextId = 1;

	/** @var bool Whether the loop is currently running */
	private bool $running = false;

	public function onReadable($stream, callable $callback): string
	{
		$id = $this->generateId();
		$this->readers[$id] = ['stream' => $stream, 'callback' => $callback];
		return $id;
	}

	public function onWritable($stream, callable $callback): string
	{
		$id = $this->generateId();
		$this->writers[$id] = ['stream' => $stream, 'callback' => $callback];
		return $id;
	}

	public function delay(float $seconds, callable $callback): string
	{
		$id = $this->generateId();
		$this->timers[$id] = [
			'due' => microtime(true) + $seconds,
			'interval' => null,
			'callback' => $callback,
		];
		return $id;
	}

	public function repeat(float $interval, callable $callback): string
	{
		$id = $this->generateId();
		$this->timers[$id] = [
			'due' => microtime(true) + $interval,
			'interval' => $interval,
			'callback' => $callback,
		];
		return $id;
	}

	public function onSignal(int $signal, callable $callback): string
	{
		$id = $this->generateId();
		$this->signals[$id] = ['signal' => $signal, 'callback' => $callback];

		if (function_exists('pcntl_signal')) {
			pcntl_signal($signal, function ($signo) {
				$this->dispatchSignal($signo);
			});
		}

		return $id;
	}

	public function cancel(string $watcherId): void
	{
		unset($this->readers[$watcherId]);
		unset($this->writers[$watcherId]);
		unset($this->timers[$watcherId]);
		unset($this->signals[$watcherId]);
	}

	public function run(): void
	{
		$this->running = true;

		while ($this->running && $this->hasWatchers()) {
			$this->tick();
		}
	}

	public function stop(): void
	{
		$this->running = false;
	}

	public function tick(): void
	{
		// Dispatch pending signals
		if (function_exists('pcntl_signal_dispatch')) {
			pcntl_signal_dispatch();
		}

		// Fire due timers
		$this->processTimers();

		// Build stream arrays for select
		$readStreams = [];
		$readMap = [];
		foreach ($this->readers as $id => $entry) {
			if (is_resource($entry['stream'])) {
				$readStreams[] = $entry['stream'];
				$readMap[(int) $entry['stream']] = $id;
			}
		}

		$writeStreams = [];
		$writeMap = [];
		foreach ($this->writers as $id => $entry) {
			if (is_resource($entry['stream'])) {
				$writeStreams[] = $entry['stream'];
				$writeMap[(int) $entry['stream']] = $id;
			}
		}

		// Calculate timeout: sleep until the next timer or 100ms max
		$timeout = $this->calculateTimeout();

		// Nothing to select on and no timers? Brief sleep to avoid spin.
		if (empty($readStreams) && empty($writeStreams)) {
			if ($timeout > 0) {
				usleep((int) min($timeout * 1000000, 100000));
			}
			return;
		}

		$except = null;
		$timeoutSec = (int) $timeout;
		$timeoutUsec = (int) (($timeout - $timeoutSec) * 1000000);

		$changed = @stream_select($readStreams, $writeStreams, $except, $timeoutSec, $timeoutUsec);

		if ($changed === false) {
			// Interrupted by signal — that's fine
			return;
		}

		// Dispatch readable callbacks
		foreach ($readStreams as $stream) {
			$key = (int) $stream;
			if (isset($readMap[$key]) && isset($this->readers[$readMap[$key]])) {
				$id = $readMap[$key];
				($this->readers[$id]['callback'])($stream);
			}
		}

		// Dispatch writable callbacks
		foreach ($writeStreams as $stream) {
			$key = (int) $stream;
			if (isset($writeMap[$key]) && isset($this->writers[$writeMap[$key]])) {
				$id = $writeMap[$key];
				($this->writers[$id]['callback'])($stream);
			}
		}
	}

	/**
	 * Process timers that are due.
	 */
	private function processTimers(): void
	{
		$now = microtime(true);

		// foreach iterates a snapshot, so a callback may cancel this or
		// any other timer while we are mid-pass. Every access is
		// therefore re-checked against the live array: rescheduling a
		// cancelled repeating timer would resurrect it as a partial
		// entry (no callback), fataling on the next pass.
		foreach ($this->timers as $id => $timer) {
			if (!isset($this->timers[$id]) || $now < $timer['due']) {
				continue;
			}

			($timer['callback'])();

			if (!isset($this->timers[$id])) {
				// Cancelled from inside its own callback.
				continue;
			}

			if ($timer['interval'] !== null) {
				// Repeating: reschedule from completion time so a slow
				// callback cannot make the timer fire back-to-back.
				$this->timers[$id]['due'] = microtime(true) + $timer['interval'];
			} else {
				// One-shot: remove
				unset($this->timers[$id]);
			}
		}
	}

	/**
	 * Calculate the select timeout based on the nearest timer.
	 *
	 * @return float Timeout in seconds (0.1 max if no timers)
	 */
	private function calculateTimeout(): float
	{
		if (empty($this->timers)) {
			return 0.1; // 100ms default poll interval
		}

		$now = microtime(true);
		$nearest = PHP_FLOAT_MAX;

		foreach ($this->timers as $timer) {
			$remaining = $timer['due'] - $now;
			if ($remaining < $nearest) {
				$nearest = $remaining;
			}
		}

		return max(0.0, min($nearest, 0.1));
	}

	/**
	 * Dispatch callbacks for a received signal.
	 */
	private function dispatchSignal(int $signo): void
	{
		foreach ($this->signals as $entry) {
			if ($entry['signal'] === $signo) {
				($entry['callback'])($signo);
			}
		}
	}

	/**
	 * Check if any watchers are active.
	 */
	private function hasWatchers(): bool
	{
		return !empty($this->readers)
			|| !empty($this->writers)
			|| !empty($this->timers)
			|| !empty($this->signals);
	}

	/**
	 * Generate a unique watcher ID.
	 */
	private function generateId(): string
	{
		return 'sel_' . $this->nextId++;
	}
}
