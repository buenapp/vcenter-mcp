<?php
/**
 * Enchilada Comal — Ev Reactor (libev backend)
 *
 * Event loop implementation using the ext-ev PECL extension (libev).
 * Provides kqueue (FreeBSD), epoll (Linux), or poll fallback automatically.
 *
 * Requires: ext-ev (pecl install ev)
 *
 * @package    Enchilada\Comal
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

namespace Enchilada\Comal;

class EvReactor implements ReactorInterface
{
	/** @var \EvLoop */
	private \EvLoop $loop;

	/** @var array<string, \EvWatcher> Active watchers indexed by ID */
	private array $watchers = [];

	/** @var int Monotonic counter for watcher IDs */
	private int $nextId = 1;

	/** @var bool Whether the loop is currently running */
	private bool $running = false;

	public function __construct()
	{
		if (!extension_loaded('ev')) {
			throw new \RuntimeException('EvReactor requires the ev extension (pecl install ev)');
		}

		$this->loop = \EvLoop::defaultLoop();
	}

	public function onReadable($stream, callable $callback): string
	{
		$id = $this->generateId();
		$fd = $this->extractFd($stream);

		$watcher = $this->loop->io($fd, \Ev::READ, function () use ($stream, $callback) {
			$callback($stream);
		});

		$this->watchers[$id] = $watcher;
		return $id;
	}

	public function onWritable($stream, callable $callback): string
	{
		$id = $this->generateId();
		$fd = $this->extractFd($stream);

		$watcher = $this->loop->io($fd, \Ev::WRITE, function () use ($stream, $callback) {
			$callback($stream);
		});

		$this->watchers[$id] = $watcher;
		return $id;
	}

	public function delay(float $seconds, callable $callback): string
	{
		$id = $this->generateId();

		$watcher = $this->loop->timer($seconds, 0.0, function ($watcher) use ($id, $callback) {
			$callback();
			// One-shot: auto-cancel after firing
			$this->cancel($id);
		});

		$this->watchers[$id] = $watcher;
		return $id;
	}

	public function repeat(float $interval, callable $callback): string
	{
		$id = $this->generateId();

		$watcher = $this->loop->timer($interval, $interval, function () use ($callback) {
			$callback();
		});

		$this->watchers[$id] = $watcher;
		return $id;
	}

	public function onSignal(int $signal, callable $callback): string
	{
		$id = $this->generateId();

		$watcher = $this->loop->signal($signal, function () use ($signal, $callback) {
			$callback($signal);
		});

		$this->watchers[$id] = $watcher;
		return $id;
	}

	public function cancel(string $watcherId): void
	{
		if (!isset($this->watchers[$watcherId])) {
			return;
		}

		$watcher = $this->watchers[$watcherId];
		$watcher->stop();
		unset($this->watchers[$watcherId]);
	}

	public function run(): void
	{
		$this->running = true;

		while ($this->running && !empty($this->watchers)) {
			$this->loop->run(\Ev::RUN_ONCE);
		}
	}

	public function stop(): void
	{
		$this->running = false;
		$this->loop->stop(\Ev::BREAK_ONE);
	}

	public function tick(): void
	{
		$this->loop->run(\Ev::RUN_NOWAIT);
	}

	/**
	 * Generate a unique watcher ID.
	 */
	private function generateId(): string
	{
		return 'ev_' . $this->nextId++;
	}

	/**
	 * Extract a usable file descriptor for ext-ev.
	 *
	 * ext-ev's EvIo accepts either a numeric fd (int) or a PHP stream
	 * resource directly. We pass the resource as-is since PHP's internal
	 * stream layer handles the fd extraction correctly.
	 *
	 * @param resource $stream
	 * @return resource The stream resource (passed through)
	 * @throws \InvalidArgumentException If not a valid stream
	 */
	private function extractFd($stream): mixed
	{
		if (!is_resource($stream)) {
			throw new \InvalidArgumentException('Expected a stream resource');
		}

		return $stream;
	}
}
