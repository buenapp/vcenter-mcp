<?php
/**
 * Enchilada Comal — Fiber-Based Async Helpers (opt-in)
 *
 * Provides suspend/resume I/O primitives for use inside Fibers.
 * Requires PHP 8.1+ Fibers. Does NOT modify ReactorInterface.
 *
 * Usage:
 *   Async\spawn($reactor, function() use ($reactor) {
 *       $data = Async\read($reactor, $socket, 65536);
 *   });
 *
 * @package    Enchilada\Comal
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

namespace Enchilada\Comal\Async;

use Enchilada\Comal\ReactorInterface;

/**
 * Spawn a new concurrent task (Fiber) on the reactor.
 *
 * The callable runs immediately up to its first suspend point,
 * then yields back to the caller. The reactor drives resumption.
 *
 * @param ReactorInterface $reactor Event loop to register watchers on
 * @param callable         $task    fn(): void — runs inside a new Fiber
 */
function spawn(ReactorInterface $reactor, callable $task): void
{
	$fiber = new \Fiber($task);
	$fiber->start();
}

/**
 * Suspend the current fiber until $stream is readable, then return the data.
 *
 * @param ReactorInterface $reactor Event loop
 * @param resource         $stream  Non-blocking stream
 * @param int              $length  Max bytes to read
 * @return string Data read (empty string on EOF)
 * @throws \LogicException If called outside a Fiber
 */
function read(ReactorInterface $reactor, $stream, int $length = 65536): string
{
	$fiber = \Fiber::getCurrent();
	if ($fiber === null) {
		throw new \LogicException('Async\\read() must be called inside a Fiber (use Async\\spawn)');
	}

	$watcherId = '';
	$watcherId = $reactor->onReadable($stream, function ($s) use ($reactor, $fiber, $length, &$watcherId) {
		$reactor->cancel($watcherId);
		$data = fread($s, $length);
		$fiber->resume($data !== false ? $data : '');
	});

	return \Fiber::suspend();
}

/**
 * Suspend the current fiber until $stream is writable, then write $data.
 *
 * @param ReactorInterface $reactor Event loop
 * @param resource         $stream  Non-blocking stream
 * @param string           $data    Data to write
 * @return int Bytes written
 * @throws \LogicException If called outside a Fiber
 */
function write(ReactorInterface $reactor, $stream, string $data): int
{
	$fiber = \Fiber::getCurrent();
	if ($fiber === null) {
		throw new \LogicException('Async\\write() must be called inside a Fiber (use Async\\spawn)');
	}

	$watcherId = '';
	$watcherId = $reactor->onWritable($stream, function ($s) use ($reactor, $fiber, $data, &$watcherId) {
		$reactor->cancel($watcherId);
		$written = fwrite($s, $data);
		$fiber->resume($written !== false ? $written : 0);
	});

	return \Fiber::suspend();
}

/**
 * Suspend the current fiber for $seconds (non-blocking sleep).
 *
 * @param ReactorInterface $reactor Event loop
 * @param float            $seconds Delay in fractional seconds
 * @throws \LogicException If called outside a Fiber
 */
function sleep(ReactorInterface $reactor, float $seconds): void
{
	$fiber = \Fiber::getCurrent();
	if ($fiber === null) {
		throw new \LogicException('Async\\sleep() must be called inside a Fiber (use Async\\spawn)');
	}

	$reactor->delay($seconds, function () use ($fiber) {
		$fiber->resume();
	});

	\Fiber::suspend();
}
