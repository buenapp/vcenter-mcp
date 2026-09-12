<?php
/**
 * Enchilada Comal — Event Reactor (libevent2 backend)
 *
 * Event loop implementation using the ext-event PECL extension (libevent2).
 * Provides kqueue (FreeBSD), epoll (Linux), or poll fallback automatically.
 *
 * Requires: ext-event (pecl install event)
 *
 * @package    Enchilada\Comal
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

namespace Enchilada\Comal;

class EventReactor implements ReactorInterface
{
	/** @var \EventBase */
	private \EventBase $base;

	/** @var array<string, \Event> Active watchers indexed by ID */
	private array $watchers = [];

	/** @var array<string, callable> Callbacks indexed by watcher ID */
	private array $callbacks = [];

	/** @var array<string, mixed> Metadata for watchers (stream refs, etc.) */
	private array $meta = [];

	/** @var int Monotonic counter for watcher IDs */
	private int $nextId = 1;

	/** @var bool Whether the loop is currently running */
	private bool $running = false;

	public function __construct()
	{
		if (!extension_loaded('event')) {
			throw new \RuntimeException('EventReactor requires the event extension (pecl install event)');
		}

		$this->base = new \EventBase();
	}

	public function onReadable($stream, callable $callback): string
	{
		$id = $this->generateId();

		$event = new \Event($this->base, $stream, \Event::READ | \Event::PERSIST, function ($fd, $what) use ($id) {
			if (isset($this->callbacks[$id])) {
				($this->callbacks[$id])($this->meta[$id]['stream']);
			}
		});

		$event->add();
		$this->watchers[$id] = $event;
		$this->callbacks[$id] = $callback;
		$this->meta[$id] = ['stream' => $stream];

		return $id;
	}

	public function onWritable($stream, callable $callback): string
	{
		$id = $this->generateId();

		$event = new \Event($this->base, $stream, \Event::WRITE | \Event::PERSIST, function ($fd, $what) use ($id) {
			if (isset($this->callbacks[$id])) {
				($this->callbacks[$id])($this->meta[$id]['stream']);
			}
		});

		$event->add();
		$this->watchers[$id] = $event;
		$this->callbacks[$id] = $callback;
		$this->meta[$id] = ['stream' => $stream];

		return $id;
	}

	public function delay(float $seconds, callable $callback): string
	{
		$id = $this->generateId();

		$event = new \Event($this->base, -1, \Event::TIMEOUT, function () use ($id, $callback) {
			$callback();
			$this->cancel($id);
		});

		$event->add($seconds);
		$this->watchers[$id] = $event;
		$this->callbacks[$id] = $callback;

		return $id;
	}

	public function repeat(float $interval, callable $callback): string
	{
		$id = $this->generateId();

		// libevent2 doesn't have a native repeating timer — we re-add on each fire
		$event = \Event::timer($this->base, function () use ($id, $interval, $callback) {
			$callback();
			// Re-add the timer for the next interval
			if (isset($this->watchers[$id])) {
				$this->watchers[$id]->add($interval);
			}
		});

		$event->add($interval);
		$this->watchers[$id] = $event;
		$this->callbacks[$id] = $callback;
		$this->meta[$id] = ['interval' => $interval];

		return $id;
	}

	public function onSignal(int $signal, callable $callback): string
	{
		$id = $this->generateId();

		$event = \Event::signal($this->base, $signal, function () use ($signal, $callback) {
			$callback($signal);
		});

		$event->add();
		$this->watchers[$id] = $event;
		$this->callbacks[$id] = $callback;

		return $id;
	}

	public function cancel(string $watcherId): void
	{
		if (!isset($this->watchers[$watcherId])) {
			return;
		}

		$event = $this->watchers[$watcherId];
		$event->del();
		$event->free();

		unset($this->watchers[$watcherId]);
		unset($this->callbacks[$watcherId]);
		unset($this->meta[$watcherId]);
	}

	public function run(): void
	{
		$this->running = true;

		while ($this->running && !empty($this->watchers)) {
			$this->base->loop(\EventBase::LOOP_ONCE);
		}
	}

	public function stop(): void
	{
		$this->running = false;
		$this->base->stop();
	}

	public function tick(): void
	{
		$this->base->loop(\EventBase::LOOP_NONBLOCK);
	}

	/**
	 * Generate a unique watcher ID.
	 */
	private function generateId(): string
	{
		return 'event_' . $this->nextId++;
	}
}
