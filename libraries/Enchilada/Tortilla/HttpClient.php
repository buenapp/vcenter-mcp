<?php

namespace Enchilada\Tortilla;

/* Enchilada Framework 3.0
 * Loop-aware HTTP Client
 *
 * A synchronous-looking HTTP front end over Extras' curl clients that
 * decides per call HOW the wait happens, so tool code never has to
 * know:
 *
 *   reactor mode  An EventLoop is injected AND the caller is running
 *                 inside a Fiber (StdioTransport dispatches tool calls
 *                 that way). The request goes to the loop's
 *                 \EnchiladaWorkerHTTP (one per loop, its watch hooks
 *                 mapped to onReadable()/cancel()), which runs it in a
 *                 worker process; the calling fiber parks until the
 *                 worker's result arrives on a loop read watcher, so the
 *                 transport keeps answering protocol traffic (pings,
 *                 cancellations) and the loop thread never waits on curl.
 *
 *   blocking mode No loop, or no fiber (Windows stdio, PHP-FPM, plain
 *                 daemons). The request runs to completion in place
 *                 through \EnchiladaCurl::perform() (a blocking
 *                 curl_exec(); no spinning while a name lookup has no
 *                 socket). Pings cannot be answered here — nothing can
 *                 read stdin meanwhile — so the injected $progress
 *                 callable is invoked from curl's progress callback:
 *                 with protocol revision 2026-07-28 having removed
 *                 `ping`, progress notifications are the ONLY liveness
 *                 signal a modern host gets during a slow call.
 *
 * Requests are always built by the caller's engine (buildSpec()), so
 * subclassed API clients keep their URL, header and payload rules in
 * both modes. Reactor-mode requests that cannot go to a worker (an
 * upload with CURLFile parts, or a host where workers cannot start) run
 * in place like blocking mode, which holds the loop for the transfer.
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
	/** @var \EnchiladaMultiHTTP The caller's engine: request building, in-place transfers, decoding */
	private \EnchiladaMultiHTTP $multi;

	/**
	 * Worker lanes, one per loop: loop => {
	 *   engine:   \EnchiladaWorkerHTTP,
	 *   watchers: array<int,string> pipe resource id => loop watcher id,
	 *   parked:   array<int,\Fiber> request id => waiting fiber,
	 *   done:     array<int,array>  request id => outcome not yet picked up,
	 * }
	 *
	 * @var \WeakMap<EventLoop,\stdClass>|null
	 */
	private static ?\WeakMap $lanes = null;

	/** @var EventLoop|null Reactor loop; null forces in-place waits */
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
	 * @param \EnchiladaMultiHTTP $multi    The engine (subclassed API
	 *                                      clients are welcome — they are
	 *                                      the house pattern)
	 * @param EventLoop|null      $loop     Loop for fiber-suspending
	 *                                      waits, or null to always wait
	 *                                      in place
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
		$spec = $this->multi->buildSpec($method, $data, $httpVerb, $extraHeaders, $timeout, $format);
		$fiber = \Fiber::getCurrent();

		$outcome = ($fiber !== null && $this->loop !== null)
			? $this->awaitWorker($spec, $writeCallback)
			: null;
		$outcome ??= $this->awaitInPlace($spec, $writeCallback);

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
	 * Run the request to completion in the calling thread, emitting
	 * progress while it runs.
	 *
	 * @return array{result:mixed,error:?string,raw:?string,http_code:int,curl_errno:int,curl_error:string}
	 */
	private function awaitInPlace(array $spec, ?callable $writeCallback): array
	{
		$transport = $this->multi->perform($spec, $writeCallback, $this->progress !== null ? fn() => $this->emitProgress() : null);
		return $this->multi::decodeTransport($spec['format'], $transport);
	}

	/**
	 * Run the request on the loop's worker client and park the calling
	 * fiber until its result arrives.
	 *
	 * @return array{result:mixed,error:?string,raw:?string,http_code:int,curl_errno:int,curl_error:string}|null
	 *         null when the request cannot go to a worker (it was not sent)
	 */
	private function awaitWorker(array $spec, ?callable $writeCallback): ?array
	{
		if (!\EnchiladaWorkerHTTP::supported() || !\EnchiladaCurl::isPortable($spec)) {
			return null;
		}
		$lane = self::lane($this->loop);
		try {
			$requestId = $lane->engine->queueSpec($spec, $writeCallback);
		} catch (\InvalidArgumentException | \RuntimeException $e) {
			return null;
		}

		$lane->parked[$requestId] = \Fiber::getCurrent();
		while (!isset($lane->done[$requestId])) {
			\Fiber::suspend();
		}
		$outcome = $lane->done[$requestId];
		unset($lane->done[$requestId]);
		return $outcome;
	}

	/**
	 * The worker lane for a loop, created on first use.
	 */
	private static function lane(EventLoop $loop): \stdClass
	{
		self::$lanes ??= new \WeakMap();
		if (isset(self::$lanes[$loop])) {
			return self::$lanes[$loop];
		}

		$lane = (object) ['engine' => new \EnchiladaWorkerHTTP(''), 'watchers' => [], 'parked' => [], 'done' => []];
		$lane->engine->setWatchHooks(
			function ($stream) use ($lane, $loop): void {
				$lane->watchers[get_resource_id($stream)] = $loop->onReadable($stream, function ($stream) use ($lane): void {
					self::completed($lane, $lane->engine->handleReadable($stream));
				});
			},
			function ($stream) use ($lane, $loop): void {
				$id = get_resource_id($stream);
				if (isset($lane->watchers[$id])) {
					$loop->cancel($lane->watchers[$id]);
					unset($lane->watchers[$id]);
				}
			}
		);
		return self::$lanes[$loop] = $lane;
	}

	/**
	 * Hand finished requests to their parked fibers.
	 *
	 * @param int[] $requestIds
	 */
	private static function completed(\stdClass $lane, array $requestIds): void
	{
		foreach ($requestIds as $requestId) {
			$lane->done[$requestId] = $lane->engine->getResult($requestId);
			$fiber = $lane->parked[$requestId] ?? null;
			unset($lane->parked[$requestId]);
			if ($fiber !== null && $fiber->isSuspended()) {
				$fiber->resume();
			}
		}
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
