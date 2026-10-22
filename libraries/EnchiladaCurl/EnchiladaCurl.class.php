<?php

/* Enchilada Framework 3.0
 * curl Client Base
 *
 * The shared core of the curl-based HTTP clients. It is not a client by
 * itself (abstract, no HTTP suffix); EnchiladaMultiHTTP (curl_multi,
 * driven by tick()) and EnchiladaWorkerHTTP (worker processes, driven
 * by watch hooks or wait()) extend it and differ only in how transfers
 * are executed.
 *
 * What lives here, so every executor behaves the same:
 *   - settings (timeout, TLS, basic auth, Unix socket, user agent)
 *   - request building: build_request(), build_headers(),
 *     build_payload() (overridable) and buildSpec(), which describes a
 *     request as a plain array
 *   - the only code that touches easy and share handles:
 *     createHandle(), collectTransport(), releaseHandle(), newShare()
 *   - perform(): one transfer in place with a blocking curl_exec()
 *   - decodeTransport(): raw transfer outcome to the getResult() shape,
 *     and deliver(), the single point where queued requests are decoded
 *     and stored for a hand-over-once getResult()
 *
 * Software License Agreement (BSD License)
 *
 * Copyright (c) 2026, The Daniel Morante Company, Inc.
 * All rights reserved.
 */

abstract class EnchiladaCurl {

	const CONTENT_TYPE_JSON = 'Content-Type: application/json';
	const CONTENT_TYPE_FORM_ENCODED = 'Content-type: application/x-www-form-urlencoded';
	const CONTENT_TYPE_NONE = NULL;
	const DEFAULT_HTTP_REQUEST_TIMEOUT = 10;

	protected $api_endpoint;
	protected $debug = APPLICATION_DEBUG;
	protected $useragent = APPLICATION_USERAGENT;
	protected $request_timeout;

	// This is meant to be overriden
	protected $default_headers = array();

	// Extra stuff to pass to CURL
	protected $ca_cert;
	protected $plaintext_auth;
	protected $verify_ssl = true;
	protected $unix_socket;

	/** @var CurlShareHandle Connection, DNS and TLS-session cache for this client's in-process transfers */
	protected $share;

	/** @var int */
	protected $nextRequestId = 1;

	/**
	 * Completed outcomes waiting for getResult(), requestId => the
	 * getResult() shape. An entry is removed when it is handed over.
	 *
	 * @var array<int,array>
	 */
	protected $outcomes = array();

	/** @var array Response headers of the most recently completed request */
	protected $last_response_headers = array();

	/**
	 * Create a new instance.
	 *
	 * @param string $api_endpoint Base API endpoint URL.
	 * @throws Exception if cURL is not available.
	 */
	public function __construct($api_endpoint) {
		if (!function_exists('curl_init')) {
			throw new Exception('cURL support is required for ' . static::class);
		}

		$this->api_endpoint = $api_endpoint;

		if (defined('APPLICATION_HTTP_TIMEOUT')) {
			$this->request_timeout = APPLICATION_HTTP_TIMEOUT;
		} else {
			$this->request_timeout = self::DEFAULT_HTTP_REQUEST_TIMEOUT;
		}

		$this->share = self::newShare();
	}

	/**
	 * Sets the time to wait for the HTTP request to complete.
	 *
	 * This is the per-client default; a per-request timeout passed to
	 * queue() overrides it.
	 *
	 * @param int $timeout seconds to wait for
	 */
	public function setTimeout($timeout = self::DEFAULT_HTTP_REQUEST_TIMEOUT) {
		if (is_int($timeout)) {
			$this->request_timeout = $timeout;
		}
	}

	/**
	 * Enable or disable SSL certificate verification.
	 *
	 * @param bool $verify Whether to verify SSL certificates
	 */
	public function setVerifySsl(bool $verify) {
		$this->verify_ssl = $verify;
	}

	/**
	 * Sets the HTTP Basic Authentication credentials.
	 *
	 * @param string $username
	 * @param string $password
	 */
	public function setPlaintextAuth($username, $password) {
		$this->plaintext_auth = $username . ':' . $password;
	}

	/**
	 * Sets the CA certificate bundle path for SSL verification.
	 *
	 * @param string $path Path to CA certificate file
	 */
	public function setCaCert($path) {
		$this->ca_cert = $path;
	}

	/**
	 * Connect through a Unix domain socket instead of TCP.
	 *
	 * The endpoint URL still supplies the scheme, path and Host header
	 * (e.g. http://localhost); the connection goes to the socket.
	 *
	 * @param string|null $path Socket path, or null for TCP
	 */
	public function setUnixSocket(?string $path) {
		$this->unix_socket = $path;
	}

	/**
	 * Response headers of the most recently completed request.
	 *
	 * @return array lowercase header name => list of values
	 */
	public function getLastResponseHeaders(): array {
		return $this->last_response_headers;
	}

	/**
	 * Queue a new HTTP request.
	 *
	 * @param string     $method       API method/path appended to base endpoint.
	 * @param array|null $data        Request payload or query parameters.
	 * @param string     $http_verb   HTTP verb (GET, POST, PUT, PATCH, DELETE).
	 * @param array      $extra_headers Additional headers.
	 * @param int|null   $timeout     Per-request timeout (total seconds;
	 *                                ignored in 'stream-keepalive' mode).
	 * @param string     $format      'json' (default) or 'form' or 'raw',
	 *                                optionally combined with ',sse' and/or
	 *                                ',stream-keepalive' behavior flags.
	 * @param callable|null $writeCallback  Receives each response byte range
	 *                                as it arrives: function(string $chunk).
	 *                                Forces streaming accumulation mode.
	 * @return int       Request ID that can be used to retrieve the response.
	 */
	public function queue($method, $data = null, $http_verb = 'GET', array $extra_headers = array(), $timeout = null, $format = 'json', $writeCallback = null) {
		return $this->queueSpec($this->buildSpec($method, $data, $http_verb, $extra_headers, $timeout, $format), $writeCallback);
	}

	/**
	 * Queue a request described by buildSpec().
	 *
	 * @param array         $spec          From buildSpec()
	 * @param callable|null $writeCallback As for queue()
	 * @return int Request ID
	 */
	abstract public function queueSpec(array $spec, $writeCallback = null);

	/**
	 * Returns true if there are any requests still in flight.
	 */
	abstract public function hasPendingRequests();

	/**
	 * Take the result for a specific request ID (if completed).
	 *
	 * A completed outcome is handed over exactly once: its slot is freed
	 * on return, so a long-lived client does not retain every response
	 * body it has ever received. Later calls for the same ID return null.
	 *
	 * @param int $requestId
	 * @return array|null [ 'result' => mixed, 'error' => string|null, 'raw' => string|null,
	 *                      'http_code' => int, 'curl_errno' => int, 'curl_error' => string,
	 *                      'headers' => array ]
	 *                    or null if not finished (or unknown / already taken).
	 */
	public function getResult($requestId) {
		if (!isset($this->outcomes[$requestId])) {
			return null;
		}
		$outcome = $this->outcomes[$requestId];
		unset($this->outcomes[$requestId]);
		return $outcome;
	}

	/**
	 * Describe a request as a plain array: the resolved URL, headers and
	 * payload plus this client's transfer options (timeout, TLS, auth,
	 * socket, user agent). Arguments are as for queue().
	 *
	 * Apart from an array payload (multipart fields, passed to
	 * CURLOPT_POSTFIELDS as-is), every value is a scalar, string or list,
	 * so a spec can be handed to another process (see isPortable()).
	 *
	 * @return array
	 */
	public function buildSpec($method, $data = null, $http_verb = 'GET', array $extra_headers = array(), $timeout = null, $format = 'json') {
		$url = $this->build_request($method);
		$headers = $this->build_headers($extra_headers);
		$payload = $this->build_payload($data, $format);

		// For GET requests, encode array data as query parameters
		if ($http_verb === 'GET' && is_array($data) && !empty($data)) {
			$query = http_build_query($data);
			$url .= (strpos($url, '?') === false ? '?' : '&') . $query;
			// GET requests should not send a body
			$payload = null;
		}

		// Automatically set Content-Type header based on format if not already present
		$hasContentType = false;
		foreach ($headers as $h) {
			if (stripos($h, 'Content-Type:') === 0) {
				$hasContentType = true;
				break;
			}
		}
		$baseFormat = strtok((string) $format, ',');
		if (!$hasContentType && $http_verb !== 'GET') {
			if ($baseFormat === 'json') {
				$headers[] = self::CONTENT_TYPE_JSON;
			} elseif (is_array($data) && $baseFormat !== 'raw' && $baseFormat !== 'multipart') {
				// 'multipart': curl sends an array payload as
				// multipart/form-data and sets the boundary header itself
				$headers[] = self::CONTENT_TYPE_FORM_ENCODED;
			}
		}

		return array(
			'url' => $url,
			'verb' => $http_verb,
			'headers' => array_values($headers),
			'payload' => ($http_verb !== 'GET') ? $payload : null,
			'timeout' => $timeout !== null ? $timeout : $this->request_timeout,
			'format' => (string) $format,
			'verify_ssl' => (bool) $this->verify_ssl,
			'ca_cert' => !empty($this->ca_cert) ? $this->ca_cert : null,
			'plaintext_auth' => !empty($this->plaintext_auth) ? $this->plaintext_auth : null,
			'unix_socket' => !empty($this->unix_socket) ? $this->unix_socket : null,
			'useragent' => (string) $this->useragent,
		);
	}

	/**
	 * Whether a spec can run in another process: everything but an array
	 * payload holding objects (CURLFile uploads) serializes.
	 *
	 * @param array $spec From buildSpec()
	 */
	public static function isPortable(array $spec): bool {
		if (!is_array($spec['payload'] ?? null)) {
			return true;
		}
		foreach ($spec['payload'] as $value) {
			if (!is_scalar($value) && $value !== null) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Run a buildSpec() request to completion in the calling thread with
	 * a blocking curl_exec() and return its raw transfer outcome, for
	 * decodeTransport(). curl sleeps in its own wait, so nothing spins
	 * while a name lookup has no socket yet (Windows). Connections are
	 * pooled through this client's share handle.
	 *
	 * @param array         $spec          From buildSpec()
	 * @param callable|null $writeCallback function(string $chunk) for streamed bodies
	 * @param callable|null $onProgress    function(): void, called about once
	 *                                     per second while the transfer runs
	 * @return array [ 'raw' => string, 'http_code' => int, 'curl_errno' => int,
	 *                 'curl_error' => string, 'headers' => array, 'sse_done' => bool ]
	 */
	public function perform(array $spec, $writeCallback = null, $onProgress = null): array {
		$transport = self::performSpec($spec, $writeCallback, $onProgress, $this->share, $this->debug);
		$this->last_response_headers = $transport['headers'];
		return $transport;
	}

	/**
	 * Turn a raw transfer outcome into the getResult() shape: 'json'
	 * bodies are decoded, other formats and SSE streams are returned raw.
	 *
	 * @param string $format    The request's format string
	 * @param array  $transport From perform() or an executor
	 * @return array As getResult()
	 */
	final public static function decodeTransport(string $format, array $transport): array {
		$outcome = array(
			'result' => null,
			'error' => null,
			'raw' => $transport['raw'],
			'http_code' => $transport['http_code'],
			'curl_errno' => $transport['curl_errno'],
			'curl_error' => $transport['curl_error'],
			'headers' => $transport['headers'],
		);

		if ($transport['curl_errno'] !== CURLE_OK) {
			$outcome['error'] = $transport['curl_error'] !== '' ? $transport['curl_error'] : 'curl error ' . $transport['curl_errno'];
			return $outcome;
		}

		$baseFormat = strtok($format, ',');
		if ($baseFormat === 'json' && !$transport['sse_done'] && !str_contains($format, 'sse')) {
			$raw = $transport['raw'];
			$decoded = $raw ? json_decode($raw, true) : false;
			if ($decoded === null) {
				// Un-decodable body (a proxy/gateway error page,
				// truncated JSON, ...): the request IS finished —
				// leaving result=null would make getResult() report
				// "still in flight" forever and any poll-driven
				// waiter would spin for the process lifetime.
				$outcome['result'] = false;
				$outcome['error'] = 'Invalid JSON in response body ('
					. json_last_error_msg() . ', HTTP ' . $transport['http_code'] . ')';
			} else {
				$outcome['result'] = $decoded;
			}
		} else {
			// raw, or an SSE body: the caller parses frames itself
			// (event-stream is not a single JSON document).
			$outcome['result'] = $transport['raw'];
		}

		return $outcome;
	}

	/**
	 * Store a finished queued request: decode its raw outcome and keep
	 * it for getResult(). Every executor completes requests through here.
	 *
	 * @param int    $requestId
	 * @param string $format    The request's format string
	 * @param array  $transport Raw transfer outcome
	 */
	protected function deliver(int $requestId, string $format, array $transport, ?array $info = null): void {
		$this->last_response_headers = $transport['headers'];
		if ($this->debug) {
			self::debugTransport($transport, $info);
		}
		$this->outcomes[$requestId] = self::decodeTransport($format, $transport);
	}

	/**
	 * Finish an in-process transfer: collect its outcome from the easy
	 * handle, release the handle and deliver() the result.
	 *
	 * @param int        $requestId
	 * @param string     $format     The request's format string
	 * @param CurlHandle $handle     No longer attached to a multi handle
	 * @param int        $curlResult CURLE_* of the transfer
	 * @param \stdClass  $io         The transfer's state
	 * @param mixed      $body       Returned body (RETURNTRANSFER), if any
	 */
	final protected function completeHandle(int $requestId, string $format, $handle, int $curlResult, \stdClass $io, $body): void {
		$transport = self::collectTransport($handle, $curlResult, $io, $body);
		$info = $this->debug ? curl_getinfo($handle) : null;
		self::releaseHandle($handle);
		$this->deliver($requestId, $format, $transport, $info);
	}

	/**
	 * A share handle pooling connections, DNS results and TLS sessions
	 * across easy handles.
	 *
	 * @return CurlShareHandle
	 */
	final protected static function newShare() {
		$share = curl_share_init();
		foreach (array(CURL_LOCK_DATA_CONNECT, CURL_LOCK_DATA_DNS, CURL_LOCK_DATA_SSL_SESSION) as $data) {
			curl_share_setopt($share, CURLSHOPT_SHARE, $data);
		}
		return $share;
	}

	/**
	 * perform() without a client instance: the transfer runs in place
	 * and the raw outcome is returned. Worker processes use this with
	 * one share handle for their lifetime.
	 *
	 * @param array                $spec          From buildSpec()
	 * @param callable|null        $writeCallback function(string $chunk)
	 * @param callable|null        $onProgress    function(): void
	 * @param CurlShareHandle|null $share         Connection pool, or null
	 * @param bool                 $debug         Print the transfer (in-process callers only)
	 * @return array As perform()
	 */
	final protected static function performSpec(array $spec, $writeCallback, $onProgress, $share, bool $debug = false): array {
		$io = self::newTransferState();
		$handle = self::createHandle($spec, $io, $writeCallback, $share);
		if ($onProgress !== null) {
			curl_setopt($handle, CURLOPT_NOPROGRESS, false);
			curl_setopt($handle, CURLOPT_XFERINFOFUNCTION, function () use ($onProgress) {
				$onProgress();
				return 0;
			});
		}
		$body = curl_exec($handle);
		$transport = self::collectTransport($handle, curl_errno($handle), $io, $body);
		if ($debug) {
			self::debugTransport($transport, curl_getinfo($handle));
		}
		self::releaseHandle($handle);
		return $transport;
	}

	/**
	 * Fresh per-transfer state shared with the handle's callbacks.
	 */
	final protected static function newTransferState(): \stdClass {
		return (object) array('raw' => '', 'headers' => array(), 'sseDone' => false, 'streamed' => false);
	}

	/**
	 * Build a curl easy handle for a spec. Callbacks write into $io.
	 *
	 * @param array                $spec          From buildSpec()
	 * @param \stdClass            $io            From newTransferState()
	 * @param callable|null        $writeCallback function(string $chunk)
	 * @param CurlShareHandle|null $share         Connection pool, or null
	 * @return CurlHandle
	 */
	final protected static function createHandle(array $spec, \stdClass $io, $writeCallback, $share) {
		// Optional per-request behavior modifiers, comma-separated:
		//   'sse'             — treat the response as a text/event-stream:
		//                       complete the request when a data: [DONE]
		//                       frame arrives, without waiting for the
		//                       server to close the connection. The body
		//                       ends with the [DONE] event: bytes that
		//                       arrived after it in the same chunk are
		//                       dropped, so the outcome never depends on
		//                       packet timing.
		//   'stream-keepalive'— long-running streams: CURLOPT_TIMEOUT (a
		//                       TOTAL-time budget) is replaced by a stall
		//                       watchdog (CURLOPT_LOW_SPEED_LIMIT/TIME), so
		//                       active streams may run arbitrarily long
		//                       while frozen connections are cut promptly.
		$sseMode = false;
		$streamKeepalive = false;
		foreach (explode(',', $spec['format']) as $flag) {
			if ($flag === 'sse') { $sseMode = true; }
			if ($flag === 'stream-keepalive') { $streamKeepalive = true; }
		}

		$curlOptions = array(
			CURLOPT_URL => $spec['url'],
			CURLOPT_TIMEOUT => $spec['timeout'],
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_SSL_VERIFYPEER => $spec['verify_ssl'],
			CURLOPT_SSL_VERIFYHOST => $spec['verify_ssl'] ? 2 : 0,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_CUSTOMREQUEST => $spec['verb'],
			CURLOPT_USERAGENT => $spec['useragent'],
			CURLOPT_HTTPHEADER => $spec['headers'],
			// Capture response headers. CURLOPT_FOLLOWLOCATION replays one
			// header block per hop; a new "HTTP/" status line resets the
			// block so only the final response's headers are kept.
			CURLOPT_HEADERFUNCTION => function ($ch, string $line) use ($io) {
				$io->headers = self::parseHeaderLine($io->headers, $line);
				return strlen($line);
			},
		);
		if ($share !== null) {
			$curlOptions[CURLOPT_SHARE] = $share;
		}

		// Streaming writes: CURLOPT_WRITEFUNCTION is mutually exclusive
		// with CURLOPT_RETURNTRANSFER; the callback accumulates the body
		// and hands each byte range to any registered writeCallback. In
		// 'sse' mode the completion scan runs per chunk.
		if ($writeCallback !== null || $sseMode) {
			$io->streamed = true;
			$curlOptions[CURLOPT_RETURNTRANSFER] = false;
			$curlOptions[CURLOPT_WRITEFUNCTION] =
				function ($ch, string $chunk) use ($io, $writeCallback, $sseMode) {
					$priorLength = strlen($io->raw);
					$io->raw .= $chunk;
					if ($writeCallback !== null) {
						$writeCallback($chunk);
					}
					if ($sseMode && !$io->sseDone) {
						// [DONE] may cross chunk boundaries, so scan from the
						// last line boundary before this chunk rather than the
						// whole body (keeps the per-chunk cost O(chunk)).
						// Negative strrpos offset = search right-to-left,
						// skipping everything from this chunk onward.
						$lastNewline = $priorLength > 0
							? strrpos($io->raw, "\n", $priorLength - strlen($io->raw) - 1)
							: false;
						$tailStart = $lastNewline === false ? 0 : $lastNewline + 1;
						$tail = substr($io->raw, $tailStart);
						if (preg_match('/^data:[ \t]*\[DONE\][ \t]*(?=\r?\n|$)/m', $tail, $m, PREG_OFFSET_CAPTURE)) {
							$io->sseDone = true;
							$io->raw = substr($io->raw, 0, $tailStart + $m[0][1] + strlen($m[0][0])) . "\n\n";
							// Return short count to abort the transfer NOW
							// instead of waiting for server close.
							return strlen($chunk) - 1;
						}
					}
					return strlen($chunk);
				};
		}

		if ($streamKeepalive) {
			// Long-lived streams: total-time timeout is wrong (an active
			// stream legitimately outlives any fixed budget). Watch for
			// stalls instead — cut the connection if nothing moves for the
			// watchdog window.
			unset($curlOptions[CURLOPT_TIMEOUT]);
			$curlOptions[CURLOPT_LOW_SPEED_LIMIT] = 1;      // bytes/sec
			$curlOptions[CURLOPT_LOW_SPEED_TIME] = 75;      // seconds of stall
		}

		if ($spec['payload'] !== null) {
			$curlOptions[CURLOPT_POSTFIELDS] = $spec['payload'];
		}

		// Optional CA certificate
		if (!empty($spec['ca_cert'])) {
			$curlOptions[CURLOPT_CAINFO] = $spec['ca_cert'];
		}

		// Plain text HTTP authemtication
		if (!empty($spec['plaintext_auth'])) {
			$curlOptions[CURLOPT_USERPWD] = $spec['plaintext_auth'];
		}

		// Unix domain socket; the URL host only fills the Host header
		if (!empty($spec['unix_socket'])) {
			$curlOptions[CURLOPT_UNIX_SOCKET_PATH] = $spec['unix_socket'];
		}

		$handle = curl_init();
		curl_setopt_array($handle, $curlOptions);
		return $handle;
	}

	/**
	 * Gather a finished transfer's raw outcome from its handle.
	 *
	 * @param CurlHandle $handle
	 * @param int        $curlResult CURLE_* of the transfer
	 * @param \stdClass  $io         The transfer's state
	 * @param mixed      $body       Returned body (RETURNTRANSFER), if any
	 * @return array As perform()
	 */
	final protected static function collectTransport($handle, int $curlResult, \stdClass $io, $body): array {
		$error = $curlResult !== CURLE_OK ? (string) curl_error($handle) : '';

		// A short write-callback return (our [DONE] early-completion)
		// surfaces as CURLE_WRITE_ERROR (23); that is success, not failure.
		if ($curlResult === CURLE_WRITE_ERROR && $io->sseDone) {
			$curlResult = CURLE_OK;
			$error = '';
		}

		return array(
			'raw' => $io->streamed ? $io->raw : (is_string($body) ? $body : ''),
			'http_code' => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE),
			'curl_errno' => $curlResult,
			'curl_error' => $error,
			'headers' => $io->headers,
			'sse_done' => $io->sseDone,
		);
	}

	/**
	 * Release an easy handle. On PHP 8 the handle and the callbacks
	 * holding its transfer state are freed once the last reference goes.
	 *
	 * @param CurlHandle $handle
	 */
	final protected static function releaseHandle($handle): void {
		curl_close($handle);
	}

	/**
	 * Print a transfer when debugging (APPLICATION_DEBUG).
	 *
	 * @param array      $transport Raw transfer outcome
	 * @param array|null $info      curl_getinfo() of the handle, when in process
	 */
	protected static function debugTransport(array $transport, ?array $info): void {
		print_r($transport['raw']);
		echo PHP_EOL;
		if ($info !== null) {
			print_r($info);
			echo PHP_EOL;
		}
	}

	/**
	 * Parse one raw header line into the name => list-of-values map.
	 * A new "HTTP/" status line (redirect hop) resets the block; the
	 * status line itself and blank lines are dropped. Repeated headers
	 * (e.g. Set-Cookie) accumulate as list entries — never merged.
	 *
	 * @param array  $headers Current header map
	 * @param string $line    Raw line as passed to CURLOPT_HEADERFUNCTION
	 * @return array Updated header map
	 */
	final protected static function parseHeaderLine(array $headers, string $line): array {
		$line = rtrim($line, "\r\n");
		if ($line === '') {
			return $headers;
		}
		if (stripos($line, 'HTTP/') === 0) {
			return array();
		}
		$colon = strpos($line, ':');
		if ($colon === false) {
			return $headers;
		}
		$name = strtolower(trim(substr($line, 0, $colon)));
		$headers[$name][] = trim(substr($line, $colon + 1));
		return $headers;
	}

	/**
	 * Build the full request URL.
	 */
	protected function build_request($method) {
		return sprintf("%s/%s", $this->api_endpoint, $method);
	}

	/**
	 * Merge default and extra headers.
	 */
	protected function build_headers(array $extra_headers = array()) {
		return array_merge($this->default_headers, $extra_headers);
	}

	/**
	 * Formats the payload accordingly.
	 */
	protected function build_payload($data, $format) {
		if ($data === null) {
			return null;
		}

		$baseFormat = strtok((string) $format, ',');
		if (is_array($data)) {
			if ($baseFormat === 'json') {
				return json_encode($data);
			} elseif ($baseFormat === 'form') {
				return http_build_query($data);
			}
		}

		return $data;
	}

}
