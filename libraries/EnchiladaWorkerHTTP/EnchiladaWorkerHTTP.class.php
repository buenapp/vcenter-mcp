<?php

/* Enchilada Framework 3.0
 * Worker-process HTTP Client
 *
 * A full HTTP client (same constructor, settings, queue()/getResult()
 * contract as EnchiladaMultiHTTP) whose transfers run in child PHP
 * processes. PHP's curl extension exposes no socket or timer callbacks
 * (CURLMOPT_SOCKETFUNCTION/TIMERFUNCTION), so curl cannot be driven by
 * an event loop without polling. Here each worker performs one request
 * at a time with a blocking curl_exec() and reports back over a pipe;
 * the parent only ever reads pipes that have data.
 *
 * Two ways to drive it:
 *
 *   event loop  setWatchHooks($watch, $unwatch): $watch($stream) is
 *               called when a worker takes a request, $unwatch($stream)
 *               when it goes idle or is closed. The loop's read callback
 *               calls handleReadable($stream), which returns the IDs of
 *               requests that completed; getResult() hands them over.
 *               Idle workers are not watched, so they never keep a loop
 *               alive.
 *
 *   no loop     wait($requestId) reads that request's worker pipe in
 *               blocking mode until the request completes.
 *
 * Use one driver per instance. Workers start on demand up to
 * setMaxWorkers() (default 4); further requests queue for a free one.
 * A worker that dies fails its request (CURLE_RECV_ERROR) and is
 * replaced on demand. At shutdown idle workers get EOF on stdin and
 * busy ones are terminated, so process exit never waits on a transfer.
 *
 * Channel: proc_open() pipes, because their parent ends are
 * close-on-exec: a later child (another worker, a browser opener) never
 * inherits them, so closing the parent end reliably gives the worker
 * EOF. Frames are a 4-byte big-endian length followed by a JSON object;
 * binary values (payload, body, chunks) travel base64-encoded.
 *
 * Requests whose payload holds objects (CURLFile parts) cannot cross the
 * pipe: queueSpec() rejects them so the caller can run them in place
 * (EnchiladaCurl::perform()).
 *
 * POSIX only: Windows cannot wait on anonymous pipes. supported()
 * reports whether workers can run on this host.
 *
 * Software License Agreement (BSD License)
 *
 * Copyright (c) 2026, The Daniel Morante Company, Inc.
 * All rights reserved.
 */

class EnchiladaWorkerHTTP extends EnchiladaCurl {

	/** Largest accepted frame (bodies are base64; 256 MB covers any API response) */
	const MAX_FRAME = 268435456;

	/** Default concurrent worker processes */
	const DEFAULT_MAX_WORKERS = 4;

	/** @var int */
	protected $maxWorkers = self::DEFAULT_MAX_WORKERS;

	/**
	 * Running workers, key => [
	 *   'proc' => process, 'in' => request pipe, 'out' => response pipe,
	 *   'buf'  => unparsed response bytes,
	 *   'job'  => the request it is running, or null when idle,
	 * ]
	 *
	 * @var array<int,array>
	 */
	protected $workers = array();

	/** @var array<int,int> get_resource_id(response pipe) => worker key */
	protected $pipeIndex = array();

	/**
	 * Requests waiting for a free worker, in order. A job is
	 * [ 'id' => int, 'format' => string, 'frame' => encoded request
	 *   frame, 'onChunk' => ?callable ].
	 *
	 * @var array<int,array>
	 */
	protected $queued = array();

	/** @var int */
	protected $nextWorker = 1;

	/** @var string|null Why workers cannot be started, once a spawn failed */
	protected $unavailable = null;

	/** @var \Closure|null function($stream): void */
	protected $watchHook = null;

	/** @var \Closure|null function($stream): void */
	protected $unwatchHook = null;

	/**
	 * Create a new instance.
	 *
	 * @param string $api_endpoint Base API endpoint URL.
	 * @throws Exception if cURL is not available.
	 */
	public function __construct($api_endpoint) {
		parent::__construct($api_endpoint);
		$self = \WeakReference::create($this);
		register_shutdown_function(static function () use ($self) {
			$self->get()?->shutdown();
		});
	}

	public function __destruct() {
		$this->shutdown();
	}

	/**
	 * Whether this host can run workers (POSIX CLI PHP with proc_open).
	 */
	public static function supported(): bool {
		return \PHP_OS_FAMILY !== 'Windows' && function_exists('proc_open') && PHP_BINARY !== '';
	}

	/**
	 * Reason workers cannot be started, or null while they can.
	 */
	public function unavailable(): ?string {
		return $this->unavailable;
	}

	/**
	 * Limit the number of concurrent worker processes.
	 *
	 * @param int $max At least 1
	 */
	public function setMaxWorkers(int $max): void {
		$this->maxWorkers = max(1, $max);
	}

	/**
	 * Integrate with an event loop without depending on one.
	 *
	 * $watch($stream) is called for a worker's response pipe while that
	 * worker runs a request (including any already busy); $unwatch($stream)
	 * when it goes idle and before it is closed. The watcher's callback
	 * should call handleReadable($stream).
	 *
	 * @param callable $watch   function($stream): void
	 * @param callable $unwatch function($stream): void
	 */
	public function setWatchHooks(callable $watch, callable $unwatch): void {
		$this->watchHook = $watch(...);
		$this->unwatchHook = $unwatch(...);
		foreach ($this->workers as $worker) {
			if ($worker['job'] !== null) {
				($this->watchHook)($worker['out']);
			}
		}
	}

	/**
	 * Queue a request described by buildSpec().
	 *
	 * @param array         $spec          From buildSpec()
	 * @param callable|null $writeCallback As for queue()
	 * @return int Request ID
	 * @throws \InvalidArgumentException When the spec cannot be sent to a worker
	 *                                   (see isPortable(), or not JSON-encodable)
	 * @throws \RuntimeException When no worker can be started; the request was not taken
	 */
	public function queueSpec(array $spec, $writeCallback = null) {
		if (!self::isPortable($spec)) {
			throw new \InvalidArgumentException('EnchiladaWorkerHTTP: a payload with CURLFile parts cannot be sent to a worker');
		}
		if ($this->idleWorker() === null && empty($this->workers)) {
			throw new \RuntimeException('EnchiladaWorkerHTTP: ' . ($this->unavailable ?? 'no worker available'));
		}

		$requestId = $this->nextRequestId++;
		$wire = $spec;
		if (is_string($wire['payload'])) {
			$wire['payload_b64'] = base64_encode($wire['payload']);
			$wire['payload'] = null;
		}
		try {
			$frame = self::encodeFrame(array('id' => $requestId, 'spec' => $wire, 'stream' => $writeCallback !== null));
		} catch (\JsonException $e) {
			throw new \InvalidArgumentException('EnchiladaWorkerHTTP: request cannot be encoded for a worker: ' . $e->getMessage(), 0, $e);
		}

		$this->queued[] = array('id' => $requestId, 'format' => $spec['format'], 'frame' => $frame, 'onChunk' => $writeCallback);
		$this->dispatch();

		foreach ($this->queued as $i => $job) {
			if ($job['id'] === $requestId && empty($this->workers)) {
				// Every worker failed to take it and none can start
				unset($this->queued[$i]);
				$this->queued = array_values($this->queued);
				throw new \RuntimeException('EnchiladaWorkerHTTP: ' . ($this->unavailable ?? 'no worker available'));
			}
		}
		return $requestId;
	}

	/**
	 * Returns true if there are any requests queued or running.
	 */
	public function hasPendingRequests() {
		if (!empty($this->queued)) {
			return true;
		}
		foreach ($this->workers as $worker) {
			if ($worker['job'] !== null) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Consume what arrived on a worker's response pipe. Never blocks.
	 *
	 * Streamed chunks go to their request's writeCallback; finished
	 * requests become available from getResult().
	 *
	 * @param resource $stream A pipe announced through the watch hook
	 * @return int[] IDs of the requests that completed
	 */
	public function handleReadable($stream): array {
		$key = $this->pipeIndex[get_resource_id($stream)] ?? null;
		if ($key === null) {
			return array();
		}

		$out = $this->workers[$key]['out'];
		while (($data = fread($out, 65536)) !== false && $data !== '') {
			$this->workers[$key]['buf'] .= $data;
		}

		$completed = $this->consume($key);
		if (isset($this->workers[$key]) && feof($out)) {
			$completed = array_merge($completed, $this->retire($key, 'curl worker exited'));
		}
		return array_merge($completed, $this->dispatch());
	}

	/**
	 * Run until a request completes, reading worker pipes in blocking
	 * mode, for use without an event loop. The outcome is then available
	 * from getResult().
	 *
	 * While the request waits for a free worker, the busy workers are
	 * read one at a time until one finishes.
	 *
	 * @param int $requestId From queue()
	 */
	public function wait($requestId): void {
		while (!isset($this->outcomes[$requestId])) {
			$key = $this->workerFor($requestId);
			if ($key === null) {
				if (!$this->isQueued($requestId)) {
					return; // unknown, or already taken
				}
				$key = array_key_first($this->workers);
				if ($key === null) {
					$this->dispatch();
					continue;
				}
			}
			$this->readBlocking($key);
			$this->dispatch();
		}
	}

	/**
	 * Close every worker (idle ones get EOF, busy ones are terminated)
	 * and fail every request still queued or running.
	 */
	public function shutdown(): void {
		foreach (array_keys($this->workers) as $key) {
			$this->retire($key, 'curl worker pool shut down');
		}
		foreach ($this->queued as $job) {
			$this->deliver($job['id'], $job['format'], self::failure('curl worker pool shut down'));
		}
		$this->queued = array();
	}

	/**
	 * Worker entry point: serve requests from stdin until EOF.
	 *
	 * Each request frame runs through EnchiladaCurl::performSpec() with
	 * one share handle for the process lifetime; streamed chunks and the
	 * raw outcome go back as frames on stdout.
	 */
	public static function serveWorker(): void {
		$share = self::newShare();
		while (($frame = self::readFrame(STDIN)) !== null) {
			$id = $frame['id'];
			$spec = $frame['spec'];
			if (isset($spec['payload_b64'])) {
				$spec['payload'] = base64_decode($spec['payload_b64']);
				unset($spec['payload_b64']);
			}
			$onChunk = !empty($frame['stream'])
				? static function (string $chunk) use ($id) {
					self::writeFrame(STDOUT, array('id' => $id, 'type' => 'chunk', 'data' => base64_encode($chunk)));
				}
				: null;

			$transport = self::performSpec($spec, $onChunk, null, $share);
			$transport['raw'] = base64_encode((string) $transport['raw']);
			self::writeFrame(STDOUT, array('id' => $id, 'type' => 'done', 'transport' => $transport));
		}
	}

	/**
	 * Hand queued requests to idle workers, starting workers as needed.
	 *
	 * @return int[] IDs of queued requests failed because no worker can run
	 */
	protected function dispatch(): array {
		$failed = array();
		while (!empty($this->queued)) {
			$key = $this->idleWorker();
			if ($key === null) {
				if (empty($this->workers) && $this->unavailable !== null) {
					foreach ($this->queued as $job) {
						$this->deliver($job['id'], $job['format'], self::failure($this->unavailable));
						$failed[] = $job['id'];
					}
					$this->queued = array();
				}
				break;
			}

			$job = array_shift($this->queued);

			// The worker is idle and reading, so the (blocking) write
			// completes as fast as it consumes the frame.
			try {
				self::writeBytes($this->workers[$key]['in'], $job['frame']);
			} catch (\RuntimeException $e) {
				// Gone before taking it: replace the worker, keep the job
				$this->retire($key, $e->getMessage());
				array_unshift($this->queued, $job);
				continue;
			}
			$this->workers[$key]['job'] = $job;
			if ($this->watchHook !== null) {
				($this->watchHook)($this->workers[$key]['out']);
			}
		}
		return $failed;
	}

	/**
	 * Key of an idle worker, starting one when all are busy and the
	 * limit allows. A failed start marks the client unavailable.
	 */
	protected function idleWorker(): ?int {
		foreach ($this->workers as $key => $worker) {
			if ($worker['job'] === null) {
				return $key;
			}
		}
		if (count($this->workers) >= $this->maxWorkers || $this->unavailable !== null) {
			return null;
		}
		try {
			return $this->spawn();
		} catch (\RuntimeException $e) {
			$this->unavailable = $e->getMessage();
			return null;
		}
	}

	/**
	 * Start a worker process with pipes for stdin and stdout.
	 *
	 * @return int Worker key
	 * @throws \RuntimeException When the process cannot be started
	 */
	protected function spawn(): int {
		if (!self::supported()) {
			throw new \RuntimeException('curl workers need POSIX CLI PHP with proc_open');
		}
		$code = 'defined("APPLICATION_DEBUG") || define("APPLICATION_DEBUG", false);'
			. 'defined("APPLICATION_USERAGENT") || define("APPLICATION_USERAGENT", "Enchilada");'
			. 'require ' . var_export((new \ReflectionClass(EnchiladaCurl::class))->getFileName(), true) . ';'
			. 'require ' . var_export((new \ReflectionClass(self::class))->getFileName(), true) . ';'
			. '\\EnchiladaWorkerHTTP::serveWorker();';
		$proc = proc_open(
			array(PHP_BINARY, '-d', 'display_errors=stderr', '-r', $code),
			array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('file', '/dev/null', 'w')),
			$pipes
		);
		if (!is_resource($proc)) {
			throw new \RuntimeException('curl worker: proc_open() failed');
		}

		stream_set_blocking($pipes[1], false);
		$key = $this->nextWorker++;
		$this->workers[$key] = array(
			'proc' => $proc,
			'in' => $pipes[0],
			'out' => $pipes[1],
			'buf' => '',
			'job' => null,
		);
		$this->pipeIndex[get_resource_id($pipes[1])] = $key;
		return $key;
	}

	/**
	 * Read one worker's pipe in blocking mode until its request finishes
	 * or it exits.
	 */
	protected function readBlocking(int $key): void {
		$out = $this->workers[$key]['out'];
		stream_set_blocking($out, true);
		while (isset($this->workers[$key]) && $this->workers[$key]['job'] !== null) {
			$this->consume($key);
			if (!isset($this->workers[$key]) || $this->workers[$key]['job'] === null) {
				break;
			}
			$data = fread($out, self::bytesWanted($this->workers[$key]['buf']));
			if ($data === false || $data === '') {
				$this->retire($key, 'curl worker exited');
				return;
			}
			$this->workers[$key]['buf'] .= $data;
		}
		if (isset($this->workers[$key])) {
			stream_set_blocking($out, false);
		}
	}

	/**
	 * Process the complete frames buffered for a worker.
	 *
	 * @return int[] IDs of the requests that completed
	 */
	protected function consume(int $key): array {
		try {
			$messages = self::decodeFrames($this->workers[$key]['buf']);
		} catch (\RuntimeException $e) {
			return $this->retire($key, $e->getMessage());
		}

		$completed = array();
		foreach ($messages as $message) {
			$job = $this->workers[$key]['job'];
			if ($job === null || ($message['id'] ?? null) !== $job['id']) {
				continue;
			}
			if (($message['type'] ?? '') === 'chunk') {
				if ($job['onChunk'] !== null) {
					($job['onChunk'])(base64_decode($message['data']));
				}
				continue;
			}
			$transport = $message['transport'];
			$transport['raw'] = base64_decode($transport['raw']);
			$this->workers[$key]['job'] = null;
			if ($this->unwatchHook !== null) {
				($this->unwatchHook)($this->workers[$key]['out']);
			}
			$this->deliver($job['id'], $job['format'], $transport);
			$completed[] = $job['id'];
		}
		return $completed;
	}

	/**
	 * Stop watching a worker, close its pipes (an idle worker exits at
	 * EOF; a busy one is terminated rather than waited for), reap it,
	 * and fail its in-flight request.
	 *
	 * @return int[] The failed request's ID, if it had one
	 */
	protected function retire(int $key, string $reason): array {
		$worker = $this->workers[$key];
		unset($this->workers[$key], $this->pipeIndex[get_resource_id($worker['out'])]);
		if ($worker['job'] !== null && $this->unwatchHook !== null) {
			($this->unwatchHook)($worker['out']);
		}
		fclose($worker['in']);
		fclose($worker['out']);
		if ($worker['job'] !== null) {
			proc_terminate($worker['proc']);
		}
		proc_close($worker['proc']);
		if ($worker['job'] === null) {
			return array();
		}
		$this->deliver($worker['job']['id'], $worker['job']['format'], self::failure($reason));
		return array($worker['job']['id']);
	}

	/**
	 * Key of the worker running a request, or null.
	 */
	protected function workerFor(int $requestId): ?int {
		foreach ($this->workers as $key => $worker) {
			if ($worker['job'] !== null && $worker['job']['id'] === $requestId) {
				return $key;
			}
		}
		return null;
	}

	/**
	 * Whether a request is waiting for a free worker.
	 */
	protected function isQueued(int $requestId): bool {
		foreach ($this->queued as $job) {
			if ($job['id'] === $requestId) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A raw transfer outcome for a request no worker could complete.
	 */
	protected static function failure(string $reason): array {
		return array(
			'raw' => '',
			'http_code' => 0,
			'curl_errno' => CURLE_RECV_ERROR,
			'curl_error' => $reason,
			'headers' => array(),
			'sse_done' => false,
		);
	}

	/**
	 * Encode one frame.
	 *
	 * @param array<string,mixed> $message
	 */
	public static function encodeFrame(array $message): string {
		$json = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
		return pack('N', strlen($json)) . $json;
	}

	/**
	 * Split complete frames off the front of $buffer.
	 *
	 * @param string $buffer Accumulated bytes; consumed frames are removed
	 * @return array<int,array<string,mixed>> Decoded messages, in order
	 * @throws \RuntimeException On a malformed or oversized frame
	 */
	public static function decodeFrames(string &$buffer): array {
		$messages = array();
		while (strlen($buffer) >= 4) {
			$length = unpack('N', substr($buffer, 0, 4))[1];
			if ($length > self::MAX_FRAME) {
				throw new \RuntimeException("curl worker frame too large ({$length} bytes)");
			}
			if (strlen($buffer) < 4 + $length) {
				break;
			}
			$message = json_decode(substr($buffer, 4, $length), true);
			$buffer = substr($buffer, 4 + $length);
			if (!is_array($message)) {
				throw new \RuntimeException('curl worker sent a malformed frame');
			}
			$messages[] = $message;
		}
		return $messages;
	}

	/**
	 * Bytes still needed to complete the next frame in $buffer.
	 */
	protected static function bytesWanted(string $buffer): int {
		if (strlen($buffer) < 4) {
			return 4 - strlen($buffer);
		}
		$length = unpack('N', substr($buffer, 0, 4))[1];
		return max(1, min(4 + $length - strlen($buffer), 65536));
	}

	/**
	 * Write one frame to a blocking stream.
	 *
	 * @param resource            $stream
	 * @param array<string,mixed> $message
	 * @throws \RuntimeException When the peer is gone
	 */
	protected static function writeFrame($stream, array $message): void {
		self::writeBytes($stream, self::encodeFrame($message));
	}

	/**
	 * Write encoded frames to a blocking stream.
	 *
	 * @param resource $stream
	 * @throws \RuntimeException When the peer is gone
	 */
	protected static function writeBytes($stream, string $data): void {
		$written = 0;
		$length = strlen($data);
		while ($written < $length) {
			$n = fwrite($stream, substr($data, $written));
			if ($n === false || $n === 0) {
				throw new \RuntimeException('curl worker peer closed the pipe');
			}
			$written += $n;
		}
		fflush($stream);
	}

	/**
	 * Read one frame from a blocking stream.
	 *
	 * @param resource $stream
	 * @return array<string,mixed>|null null at end of stream
	 */
	protected static function readFrame($stream): ?array {
		$header = self::readExactly($stream, 4);
		if ($header === null) {
			return null;
		}
		$length = unpack('N', $header)[1];
		if ($length > self::MAX_FRAME) {
			return null;
		}
		$json = self::readExactly($stream, $length);
		$message = $json === null ? null : json_decode($json, true);
		return is_array($message) ? $message : null;
	}

	/**
	 * @param resource $stream
	 */
	protected static function readExactly($stream, int $length): ?string {
		$data = '';
		while (strlen($data) < $length) {
			$chunk = fread($stream, $length - strlen($data));
			if ($chunk === false || $chunk === '') {
				return null;
			}
			$data .= $chunk;
		}
		return $data;
	}

}
