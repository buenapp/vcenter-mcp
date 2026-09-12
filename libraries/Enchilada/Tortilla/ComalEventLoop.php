<?php

namespace Enchilada\Tortilla;

/* Enchilada Framework 3.0
 * Tortilla Event Loop Adapter — Enchilada Comal
 *
 * Adapts the house reactor (Enchilada\Comal) to this library's
 * {@see EventLoop} port. This is one of only two files in
 * this library that refer to Comal directly (the other is
 * EmbeddedHttpTransport, which is inherently reactor-driven): the
 * remaining transport code depends on the interface, so an embedding
 * application may supply its own loop instead, and a host without Comal
 * vendored still runs the stdio transport in blocking mode.
 *
 * Comal picks the best available multiplexer for the platform
 * (ext-ev/libev, ext-event/libevent, kqueue), which is why it is the
 * preferred implementation where it is present.
 *
 * Usage:
 *   $loop = ComalEventLoop::create();          // null when unavailable
 *   $transport->setLoop($loop);
 *
 * Software License Agreement (BSD License)
 *
 * Copyright (c) 2026, The Daniel Morante Company, Inc.
 * All rights reserved.
 */

use Enchilada\Comal\ReactorFactory;
use Enchilada\Comal\ReactorInterface;

final class ComalEventLoop implements EventLoop
{
	/** @var ReactorInterface */
	private ReactorInterface $reactor;

	/** @var string Backend label resolved at construction */
	private string $backend;

	/**
	 * Wrap an existing Comal reactor (e.g. one already running an
	 * embedded HTTP listener).
	 */
	public function __construct(ReactorInterface $reactor, ?string $backend = null)
	{
		$this->reactor = $reactor;
		$this->backend = 'comal:' . ($backend ?? (class_exists(ReactorFactory::class) ? ReactorFactory::detectBackend() : 'unknown'));
	}

	/**
	 * Is Comal vendored in this installation?
	 */
	public static function available(): bool
	{
		return class_exists(ReactorFactory::class);
	}

	/**
	 * Build a loop on a freshly created Comal reactor, or return null
	 * when Comal is not vendored. Callers that require a loop should
	 * raise their own error on null, so the message can name what the
	 * loop was needed for.
	 *
	 * @param string|null $backend Force a Comal backend, or null to auto-detect
	 */
	public static function create(?string $backend = null): ?self
	{
		if (!self::available()) {
			return null;
		}
		return new self(ReactorFactory::create($backend), $backend);
	}

	/**
	 * The wrapped reactor, for code that needs Comal-specific features
	 * (signal watchers, writability, sharing the loop with an HTTP
	 * listener) beyond the EventLoop port.
	 */
	public function reactor(): ReactorInterface
	{
		return $this->reactor;
	}

	public function onReadable($stream, callable $callback): string
	{
		return $this->reactor->onReadable($stream, $callback);
	}

	public function delay(float $seconds, callable $callback): string
	{
		return $this->reactor->delay($seconds, $callback);
	}

	public function repeat(float $interval, callable $callback): string
	{
		return $this->reactor->repeat($interval, $callback);
	}

	public function cancel(string $watcherId): void
	{
		$this->reactor->cancel($watcherId);
	}

	public function run(): void
	{
		$this->reactor->run();
	}

	public function stop(): void
	{
		$this->reactor->stop();
	}

	public function backend(): string
	{
		return $this->backend;
	}
}
