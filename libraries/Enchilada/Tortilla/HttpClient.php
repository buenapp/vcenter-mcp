<?php

namespace Enchilada\Tortilla;

/* Enchilada Framework 3.0
 * Loop-aware HTTP Client
 *
 * A synchronous-looking HTTP front end over \EnchiladaMultiHTTP that
 * decides per call HOW the wait happens, so tool code never has to
 * know:
 *
 *   reactor mode  An EventLoop is injected AND the caller is running
 *                 inside a Fiber (StdioTransport dispatches tool calls
 *                 that way). The client parks the calling fiber and
 *                 drives the curl_multi state machine from a loop
 *                 timer, so the transport keeps answering protocol
 *                 traffic (pings, cancellations) while the HTTP call
 *                 is in flight.
 *
 *   blocking mode No loop, or no fiber (Windows stdio, PHP-FPM, plain
 *                 daemons). The client drives the state machine from
 *                 a bounded poll loop (\EnchiladaMultiHTTP::tick()
 *                 blocks up to 100ms in curl_multi_select). Pings
 *                 cannot be answered here — nothing can read stdin
 *                 meanwhile — so the injected $progress callable is
 *                 invoked on every iteration: with protocol revision
 *                 2026-07-28 having removed `ping`, progress
 *                 notifications are the ONLY liveness signal a modern
 *                 host gets during a slow call.
 *
 * Progress emission is deliberately asymmetric: in reactor mode the
 * transport's own progress timer fires while the fiber is parked, so
 * this class stays silent and never double-emits; in blocking mode
 * the transport is stuck inside dispatch() and progress must come
 * from here.
 *
 * There is deliberately no yield primitive for non-HTTP waits (plain
 * sleep, C-extension blocks): that starvation case is a documented,
 * test-asserted limitation.
 *
 * The surface mirrors \EnchiladaHTTP's call() + status accessors, so
 * existing per-verb client glue (e.g. an API client that inspects
 * getHttpCode()) swaps over without behavioral change.
 *
 * Construction (composition root):
 *
 *   $client = new HttpClient(new \EnchiladaMultiHTTP($baseUrl), $loop, $server->tick(...));
 *
 * Software License Agreement (BSD License)
 *
 * Copyright (c) 2026, The Daniel Morante Company, Inc.
 * All rights reserved.
 */

class HttpClient
{
	/**
	 * Interval (seconds) between drives of the curl_multi state
	 * machine. tick() itself blocks up to 100ms in curl_multi_select
	 * while handles are running, so a finer cadence buys nothing.
	 */
	private const TICK_INTERVAL = 0.05;

	/** @var \EnchiladaMultiHTTP The driven multi client */
	private \EnchiladaMultiHTTP $multi;

	/** @var EventLoop|null Reactor loop; null forces the blocking poll */
	private ?EventLoop $loop;

	/** @var \Closure|null "Emit progress now": function(): void */
	private ?\Closure $progress;

	/** @var int HTTP status code of the most recent call (0 = none/unknown) */
	private int $lastHttpCode = 0;

	/** @var int CURLE_* of the most recent call (0 = transport ok) */
	private int $lastCurlErrno = 0;

	/** @var string cURL error text of the most recent call ('' = none) */
	private string $lastCurlError = '';

	/** @var array Response headers of the most recent call (lowercase name => list of values) */
	private array $lastResponseHeaders = [];

	/**
	 * @param \EnchiladaMultiHTTP $multi    The multi client to drive
	 *                                      (subclassed API clients are
	 *                                      welcome — they are the house
	 *                                      pattern)
	 * @param EventLoop|null      $loop     Loop for fiber-suspending
	 *                                      waits, or null to always poll
	 * @param callable|null       $progress function(): void — emit a
	 *                                      progress notification for the
	 *                                      in-flight request (blocking
	 *                                      mode only); null disables it
	 */
	public function __construct(\EnchiladaMultiHTTP $multi, ?EventLoop $loop = null, ?callable $progress = null)
	{
		$this->multi = $multi;
		$this->loop = $loop;
		$this->progress = $progress !== null ? $progress(...) : null;
	}

	/**
	 * Perform a synchronous-looking HTTP call. Same contract as
	 * \EnchiladaHTTP::call().
	 *
	 * @param  string        $method        API method/path appended to the multi client's base endpoint
	 * @param  array|string|null $data      Request payload or query parameters
	 * @param  string        $httpVerb      GET, POST, PUT, PATCH, DELETE
	 * @param  array         $extraHeaders  Additional headers
	 * @param  int|null      $timeout       Per-request timeout in seconds
	 * @param  string        $format        'json'|'form'|'raw', plus ',sse' / ',stream-keepalive' flags
	 * @param  callable|null $writeCallback Streaming sink: function(string $chunk)
	 * @return mixed Decoded result ('json'), body string ('raw'/'sse'), or false on transport failure
	 */
	public function call(string $method, $data = null, string $httpVerb = 'GET', array $extraHeaders = [], ?int $timeout = null, string $format = 'json', ?callable $writeCallback = null)
	{
		$requestId = $this->multi->queue($method, $data, $httpVerb, $extraHeaders, $timeout, $format, $writeCallback);
		$outcome = $this->await($requestId);

		$this->lastHttpCode = $outcome['http_code'];
		$this->lastCurlErrno = $outcome['curl_errno'];
		$this->lastCurlError = $outcome['curl_error'] !== ''
			? $outcome['curl_error']
			: ($outcome['error'] ?? '');
		$this->lastResponseHeaders = $outcome['headers'] ?? [];

		return $outcome['result'];
	}

	/** HTTP status code of the most recent call (e.g. 200, 404). 0 when no response arrived. */
	public function getHttpCode(): int
	{
		return $this->lastHttpCode;
	}

	/** cURL errno of the most recent call (0 = completed without transport error). */
	public function getLastCurlErrno(): int
	{
		return $this->lastCurlErrno;
	}

	/** cURL error message of the most recent call ('' when there was none). */
	public function getLastCurlError(): string
	{
		return $this->lastCurlError;
	}

	/**
	 * Response headers of the most recent call (lowercase name => list
	 * of values; repeated headers such as Set-Cookie are never merged).
	 * Empty when the engine predates response-header capture.
	 */
	public function getLastResponseHeaders(): array
	{
		return $this->lastResponseHeaders;
	}

	/**
	 * Drive the request to completion by whichever wait regime the
	 * environment supports.
	 *
	 * @return array{result:mixed,error:?string,raw:?string,http_code:int,curl_errno:int,curl_error:string}
	 */
	private function await(int $requestId): array
	{
		$fiber = \Fiber::getCurrent();

		// No loop, or not inside a Fiber (plain scripts, PHP-FPM,
		// blocking stdio): nothing can be serviced while we wait, so
		// drive the state machine in place and keep progress flowing.
		if ($fiber === null || $this->loop === null) {
			while (($outcome = $this->multi->getResult($requestId)) === null) {
				$this->multi->tick();
				$this->emitProgress();
			}
			return $outcome;
		}

		return $this->awaitReactor($requestId, $fiber);
	}

	/**
	 * Park the calling fiber and drive the state machine from a loop
	 * timer, resuming once the request completes. The transport's own
	 * progress timer covers progress emission while we are suspended.
	 *
	 * @return array{result:mixed,error:?string,raw:?string,http_code:int,curl_errno:int,curl_error:string}
	 */
	private function awaitReactor(int $requestId, \Fiber $fiber): array
	{
		$loop = $this->loop;
		$outcome = null;
		$failure = null;
		$timerId = null;

		$timerId = $loop->repeat(self::TICK_INTERVAL, function () use (&$timerId, &$outcome, &$failure, $requestId, $fiber, $loop) {
			try {
				$this->multi->tick();
			} catch (\Throwable $e) {
				// Surface the failure to the waiting call, not the loop.
				$failure = $e;
			}

			if ($failure === null) {
				$outcome = $this->multi->getResult($requestId);
			}
			if ($outcome === null && $failure === null) {
				return;
			}

			if ($timerId !== null) {
				$loop->cancel($timerId);
			}
			if ($fiber->isSuspended()) {
				$fiber->resume();
			}
		});

		\Fiber::suspend();

		if ($failure !== null) {
			throw $failure;
		}
		return $outcome;
	}

	/**
	 * Blocking-mode liveness: emit a progress notification for the
	 * in-flight request. Best-effort — liveness must never break the
	 * operation it serves.
	 */
	private function emitProgress(): void
	{
		if ($this->progress === null) {
			return;
		}
		try {
			($this->progress)();
		} catch (\Throwable $e) {
			// never propagate: see docblock
		}
	}
}
