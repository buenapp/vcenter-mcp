<?php
/**
 * Enchilada Comal — Kqueue Reactor (stub)
 *
 * Placeholder for a future native kqueue/kevent backend using a
 * dedicated PHP extension (ext-kqueue). This will provide direct
 * access to FreeBSD's kevent() syscall with changelist batching
 * for maximum performance — no libev/libevent abstraction overhead.
 *
 * This class cannot be instantiated until ext-kqueue is available.
 *
 * @package    Enchilada\Comal
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

namespace Enchilada\Comal;

class KqueueReactor implements ReactorInterface
{
	public function __construct()
	{
		throw new \RuntimeException(
			'KqueueReactor requires ext-kqueue which is not yet available. '
			. 'Use EvReactor or EventReactor instead (both use kqueue internally on FreeBSD).'
		);
	}

	public function onReadable($stream, callable $callback): string
	{
		throw new \RuntimeException('KqueueReactor is not available');
	}

	public function onWritable($stream, callable $callback): string
	{
		throw new \RuntimeException('KqueueReactor is not available');
	}

	public function delay(float $seconds, callable $callback): string
	{
		throw new \RuntimeException('KqueueReactor is not available');
	}

	public function repeat(float $interval, callable $callback): string
	{
		throw new \RuntimeException('KqueueReactor is not available');
	}

	public function onSignal(int $signal, callable $callback): string
	{
		throw new \RuntimeException('KqueueReactor is not available');
	}

	public function cancel(string $watcherId): void
	{
		throw new \RuntimeException('KqueueReactor is not available');
	}

	public function run(): void
	{
		throw new \RuntimeException('KqueueReactor is not available');
	}

	public function stop(): void
	{
		throw new \RuntimeException('KqueueReactor is not available');
	}

	public function tick(): void
	{
		throw new \RuntimeException('KqueueReactor is not available');
	}
}
