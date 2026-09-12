<?php
/**
 * Enchilada Comal — Reactor Interface
 *
 * Defines the contract for multiplexed I/O event loops. Implementations
 * provide the actual backend (libev, libevent2, stream_select, kqueue).
 *
 * Key contract guarantee: registration methods (onReadable, onWritable,
 * cancel) queue changes that take effect no later than the start of the
 * next loop iteration. This deferred semantics enables kqueue changelist
 * batching and is consistent with how libev/libevent operate internally.
 *
 * @package    Enchilada\Comal
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

namespace Enchilada\Comal;

interface ReactorInterface
{
	/**
	 * Register a callback for when a stream becomes readable.
	 *
	 * The callback is active no later than the next loop iteration.
	 * The callback receives the stream resource as its only argument.
	 *
	 * @param resource $stream   PHP stream resource (socket, pipe, etc.)
	 * @param callable $callback fn($stream): void
	 * @return string Opaque watcher ID (for cancel)
	 */
	public function onReadable($stream, callable $callback): string;

	/**
	 * Register a callback for when a stream becomes writable.
	 *
	 * Same deferred semantics as onReadable.
	 *
	 * @param resource $stream   PHP stream resource
	 * @param callable $callback fn($stream): void
	 * @return string Opaque watcher ID
	 */
	public function onWritable($stream, callable $callback): string;

	/**
	 * One-shot timer. Fires once after the specified delay.
	 *
	 * @param float    $seconds  Delay in seconds (fractional OK)
	 * @param callable $callback fn(): void
	 * @return string Watcher ID
	 */
	public function delay(float $seconds, callable $callback): string;

	/**
	 * Repeating timer. Fires every $interval seconds.
	 *
	 * The first invocation occurs after $interval seconds.
	 *
	 * @param float    $interval Interval in seconds (fractional OK)
	 * @param callable $callback fn(): void
	 * @return string Watcher ID
	 */
	public function repeat(float $interval, callable $callback): string;

	/**
	 * Register a signal handler within the event loop.
	 *
	 * Signals are delivered as events within the loop iteration,
	 * not as interrupts. On backends that don't support native signal
	 * integration, falls back to pcntl_signal() + dispatch.
	 *
	 * @param int      $signal   Signal number (e.g., SIGTERM, SIGINT)
	 * @param callable $callback fn(int $signal): void
	 * @return string Watcher ID
	 */
	public function onSignal(int $signal, callable $callback): string;

	/**
	 * Cancel a watcher by ID.
	 *
	 * The cancellation takes effect no later than the next iteration.
	 * Cancelling an already-cancelled or unknown ID is a no-op.
	 *
	 * @param string $watcherId Watcher ID returned by a registration method
	 */
	public function cancel(string $watcherId): void;

	/**
	 * Enter the event loop. Blocks until stop() is called or
	 * no active watchers remain.
	 */
	public function run(): void;

	/**
	 * Stop the event loop. May be called from within a callback.
	 *
	 * After stop(), run() returns. The reactor instance remains
	 * usable — watchers are preserved and run() can be called again.
	 */
	public function stop(): void;

	/**
	 * Execute a single iteration of the event loop (non-blocking).
	 *
	 * Polls for ready events, dispatches callbacks, then returns.
	 * Useful for incremental migration from manual while-loops.
	 */
	public function tick(): void;
}
