<?php

namespace Enchilada\Tortilla;

/* Enchilada Framework 3.0
 * Tortilla Event Loop Port
 *
 * The event-loop capabilities the transport layer needs, expressed as
 * this library's own contract so transport and protocol code never names
 * a concrete loop implementation.
 *
 * The house reactor (Enchilada\Comal) is one implementation, adapted by
 * {@see ComalEventLoop}; an embedding application that already runs its
 * own loop can implement this interface instead of vendoring Comal, and
 * hand it to StdioTransport::setLoop(). Within this library only
 * ComalEventLoop and EmbeddedHttpTransport (which runs its HTTP server
 * *inside* the reactor) refer to Comal. Composition roots inject the
 * loop explicitly (house default: ComalEventLoop::create()); transport
 * code never provisions one.
 *
 * Implementations are expected to be single-threaded and callback-driven
 * (kqueue/epoll/libev class of machinery, not polling): callbacks run on
 * the loop thread, and watcher ids are opaque handles for cancel().
 *
 * Software License Agreement (BSD License)
 *
 * Copyright (c) 2026, The Daniel Morante Company, Inc.
 * All rights reserved.
 */

interface EventLoop
{
	/**
	 * Invoke $callback whenever $stream becomes readable.
	 *
	 * @param  resource $stream   Stream to watch
	 * @param  callable $callback function($stream): void
	 * @return string             Watcher id for cancel()
	 */
	public function onReadable($stream, callable $callback): string;

	/**
	 * Invoke $callback once, after $seconds.
	 *
	 * @return string Watcher id for cancel()
	 */
	public function delay(float $seconds, callable $callback): string;

	/**
	 * Invoke $callback every $interval seconds until cancelled.
	 *
	 * @return string Watcher id for cancel()
	 */
	public function repeat(float $interval, callable $callback): string;

	/**
	 * Cancel a watcher. Cancelling an unknown or already-cancelled id
	 * must be a no-op, never an error.
	 */
	public function cancel(string $watcherId): void;

	/**
	 * Run until stop() is called (or there is nothing left to watch).
	 */
	public function run(): void;

	/**
	 * Ask the loop to return from run().
	 */
	public function stop(): void;

	/**
	 * Short name of the underlying mechanism, for logs
	 * (e.g. 'comal:ev', 'comal:kqueue').
	 */
	public function backend(): string;
}
