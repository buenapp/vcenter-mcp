<?php

/* Enchilada Framework 3.0 
 * Non-blocking HTTP Client (curl_multi)
 *
 * Provides a multi-request HTTP client built on curl_multi_* that can be
 * driven by a PHP event loop or manual polling.
 */

class EnchiladaMultiHTTP {

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

	/** @var resource */
	protected $multiHandle;

	/**
	 * Map of requestId => [
	 *   'handle'        => resource,
	 *   'format'        => 'json'|'form'|'raw' — plus suffix options
	 *                      'json,sse' (collect SSE frames, complete on
	 *                      '[DONE]') and 'json,stream-keepalive'
	 *                      (CURLOPT_TIMEOUT disabled; stall watchdog via
	 *                      CURLOPT_LOW_SPEED_LIMIT/TIME),
	 *   'raw'|'result'|'error' => response slots,
	 *   'writeCallback' => callable|null,
	 *   'http_code'   => int,    HTTP status code (0 until the transfer completes)
	 *   'curl_errno'  => int,    CURLE_* value (0 on success)
	 *   'curl_error'  => string, curl error message ('' on success)
	 *   'headers'     => array,  response headers of the final HTTP block,
	 *                            lowercase name => list of values
	 * ]
	 */
	protected $requests = array();

	/** @var int */
	protected $nextRequestId = 1;

	/** @var array Response headers of the most recently completed request */
	protected $last_response_headers = array();

	/**
	 * Create a new instance.
	 *
	 * @param string $api_endpoint Base API endpoint URL.
	 * @throws Exception if cURL multi is not available.
	 */
	public function __construct($api_endpoint) {
		if (!function_exists('curl_multi_init')) {
			throw new Exception('cURL multi support is required for EnchiladaMultiHTTP');
		}

		$this->api_endpoint = $api_endpoint;

		if (defined('APPLICATION_HTTP_TIMEOUT')) {
			$this->request_timeout = APPLICATION_HTTP_TIMEOUT;
		} else {
			$this->request_timeout = self::DEFAULT_HTTP_REQUEST_TIMEOUT;
		}

		$this->multiHandle = curl_multi_init();
	}

	public function __destruct() {
		foreach ($this->requests as $req) {
			if (isset($req['handle'])) {
				curl_multi_remove_handle($this->multiHandle, $req['handle']);
				curl_close($req['handle']);
			}
		}

		if (is_resource($this->multiHandle)) {
			curl_multi_close($this->multiHandle);
		}
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

		// Optional per-request behavior modifiers, comma-separated:
		//   'sse'             — treat the response as a text/event-stream:
		//                       complete the request when a data: [DONE]
		//                       frame arrives, without waiting for the
		//                       server to close the connection.
		//   'stream-keepalive'— long-running streams: CURLOPT_TIMEOUT (a
		//                       TOTAL-time budget) is replaced by a stall
		//                       watchdog (CURLOPT_LOW_SPEED_LIMIT/TIME), so
		//                       active streams may run arbitrarily long
		//                       while frozen connections are cut promptly.
		$sseMode = false;
		$streamKeepalive = false;
		foreach (explode(',', (string) $format) as $flag) {
			if ($flag === 'sse') { $sseMode = true; }
			if ($flag === 'stream-keepalive') { $streamKeepalive = true; }
		}

		$handle = curl_init();

		$effectiveTimeout = $timeout !== null ? $timeout : $this->request_timeout;

		$curlOptions = array(
			CURLOPT_URL => $url,
			CURLOPT_TIMEOUT => $effectiveTimeout,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_SSL_VERIFYPEER => $this->verify_ssl,
			CURLOPT_SSL_VERIFYHOST => $this->verify_ssl ? 2 : 0,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_CUSTOMREQUEST => $http_verb,
			CURLOPT_USERAGENT => $this->useragent,
		);

		// Streaming writes: CURLOPT_WRITEFUNCTION is mutually exclusive
		// with CURLOPT_RETURNTRANSFER; the callback accumulates into the
		// request's 'raw' slot and hands each byte range to any registered
		// writeCallback. In 'sse' mode the completion scan runs per chunk.
		$requestId = $this->nextRequestId++;
		if ($writeCallback !== null || $sseMode) {
			$this->requests[$requestId] = array(
				'handle' => $handle,
				'format' => $format,
				'raw' => '',
				'result' => null,
				'error' => null,
				'writeCallback' => $writeCallback,
				'sseDone' => false,
				'http_code' => 0,
				'curl_errno' => 0,
				'curl_error' => '',
				'headers' => array(),
			);
			$curlOptions[CURLOPT_RETURNTRANSFER] = false;
			$curlOptions[CURLOPT_WRITEFUNCTION] =
				function ($ch, string $chunk) use ($requestId, $writeCallback, $sseMode) {
					$raw = &$this->requests[$requestId]['raw'];
					$priorLength = strlen($raw);
					$raw .= $chunk;
					if ($writeCallback !== null) {
						$writeCallback($chunk);
					}
					if ($sseMode && !$this->requests[$requestId]['sseDone']) {
						// [DONE] may cross chunk boundaries, so scan from the
						// last line boundary before this chunk rather than the
						// whole body (keeps the per-chunk cost O(chunk)).
						// Negative strrpos offset = search right-to-left,
						// skipping everything from this chunk onward.
						$lastNewline = $priorLength > 0
							? strrpos($raw, "\n", $priorLength - strlen($raw) - 1)
							: false;
						$tail = substr($raw, $lastNewline === false ? 0 : $lastNewline + 1);
						if (preg_match('/^data:\s*\[DONE\]\s*$/m', $tail)) {
							$this->requests[$requestId]['sseDone'] = true;
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

		curl_setopt_array($handle, $curlOptions);

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
			} elseif (is_array($data) && $baseFormat !== 'raw') {
				$headers[] = self::CONTENT_TYPE_FORM_ENCODED;
			}
		}

		curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);

		// Attach payload for non-GET requests
		if ($http_verb !== 'GET' && $payload !== null) {
			curl_setopt($handle, CURLOPT_POSTFIELDS, $payload);
		}

		// Optional CA certificate
		if (!empty($this->ca_cert)) {
			curl_setopt($handle, CURLOPT_CAINFO, $this->ca_cert);
		}

		// Plain text HTTP authemtication
		if (!empty($this->plaintext_auth)) {
			curl_setopt($handle, CURLOPT_USERPWD, $this->plaintext_auth);
		}

		// Non-streaming requests are registered here; streaming ones were
		// registered above so the write callback had a slot to append into.
		if (!isset($this->requests[$requestId])) {
			$this->requests[$requestId] = array(
				'handle' => $handle,
				'format' => $format,
				'raw' => null,
				'result' => null,
				'error' => null,
				'writeCallback' => null,
				'sseDone' => false,
				'http_code' => 0,
				'curl_errno' => 0,
				'curl_error' => '',
				'headers' => array(),
			);
		}

		// Capture response headers into the request slot. CURLOPT_FOLLOWLOCATION
		// replays one header block per hop; a new "HTTP/" status line resets the
		// block so only the final response's headers are kept.
		curl_setopt($handle, CURLOPT_HEADERFUNCTION,
			function ($ch, string $line) use ($requestId) {
				$this->requests[$requestId]['headers'] =
					self::parseHeaderLine($this->requests[$requestId]['headers'], $line);
				return strlen($line);
			});

		curl_multi_add_handle($this->multiHandle, $handle);

		return $requestId;
	}

	/**
	 * Advance the curl_multi state machine.
	 * Call this from an event loop or periodically.
	 */
	public function tick() {
		$running = null;
		// Drive the curl_multi state machine
		do {
			$status = curl_multi_exec($this->multiHandle, $running);
		} while ($status === CURLM_CALL_MULTI_PERFORM);

		// Wait for activity on sockets (up to 100ms). Without this call,
		// curl_multi_exec() alone may not detect server-side closes or
		// timeouts, leaving handles stuck in CLOSE_WAIT indefinitely.
		if ($running > 0) {
			curl_multi_select($this->multiHandle, 0.1);
		}

		// Process any completed transfers
		while ($info = curl_multi_info_read($this->multiHandle)) {
			$handle = $info['handle'];

			$requestId = $this->findRequestIdByHandle($handle);
			if ($requestId === null) {
				curl_multi_remove_handle($this->multiHandle, $handle);
				curl_close($handle);
				continue;
			}

			$req = $this->requests[$requestId];
			$streamed = ($req['writeCallback'] !== null) || $req['sseDone'] || str_contains($req['format'], 'sse');
			if ($streamed) {
				// Body already accumulated by the write callback;
				// curl_multi_getcontent() is empty when RETURNTRANSFER is off.
				$raw = $req['raw'];
			} else {
				$raw = curl_multi_getcontent($handle);
				$this->requests[$requestId]['raw'] = $raw;
			}

			// Capture status/diagnostics before the handle is closed, so
			// getResult() can report them (issue #36).
			$this->requests[$requestId]['http_code'] = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
			$this->requests[$requestId]['curl_errno'] = (int) $info['result'];
			$this->requests[$requestId]['curl_error'] = (string) curl_error($handle);
			$this->last_response_headers = $req['headers'] ?? array();

			// A short write-callback return (our [DONE] early-completion)
			// surfaces as CURLE_WRITE_ERROR (23); that is success, not failure.
			$curlResult = $info['result'];
			if ($curlResult === CURLE_WRITE_ERROR && $req['sseDone']) {
				$curlResult = CURLE_OK;
				$this->requests[$requestId]['curl_errno'] = CURLE_OK;
				$this->requests[$requestId]['curl_error'] = '';
			}

			if ($curlResult !== CURLE_OK) {
				$this->requests[$requestId]['error'] = curl_error($handle);
			} else {
				$baseFormat = strtok($req['format'], ',');
				if ($baseFormat === 'json' && !$req['sseDone'] && !str_contains($req['format'], 'sse')) {
					$decoded = $raw ? json_decode($raw, true) : false;
					if ($decoded === null) {
						// Un-decodable body (a proxy/gateway error page,
						// truncated JSON, ...): the request IS finished —
						// leaving result=null would make getResult() report
						// "still in flight" forever and any poll-driven
						// waiter would spin for the process lifetime.
						$this->requests[$requestId]['result'] = false;
						$this->requests[$requestId]['error'] = 'Invalid JSON in response body ('
							. json_last_error_msg() . ', HTTP '
							. $this->requests[$requestId]['http_code'] . ')';
					} else {
						$this->requests[$requestId]['result'] = $decoded;
					}
				} else {
					// raw, or an SSE body: the caller parses frames itself
					// (event-stream is not a single JSON document).
					$this->requests[$requestId]['result'] = $raw;
				}
			}

			if ($this->debug) {
				print_r($raw);
				echo PHP_EOL;
				print_r(curl_getinfo($handle));
				echo PHP_EOL;
			}

			curl_multi_remove_handle($this->multiHandle, $handle);
			curl_close($handle);
		}
	}

	/**
	 * Returns true if there are any pending requests.
	 */
	public function hasPendingRequests() {
		foreach ($this->requests as $id => $req) {
			if ($req['result'] === null && $req['error'] === null) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Get the result for a specific request ID (if completed).
	 *
	 * @param int $requestId
	 * @return array|null [ 'result' => mixed, 'error' => string|null, 'raw' => string|null,
	 *                      'http_code' => int, 'curl_errno' => int, 'curl_error' => string ]
	 *                    or null if not finished.
	 */
	public function getResult($requestId) {
		if (!isset($this->requests[$requestId])) {
			return null;
		}

		$req = $this->requests[$requestId];
		if ($req['result'] === null && $req['error'] === null) {
			return null;
		}

		return array(
			'result' => $req['result'],
			'error' => $req['error'],
			'raw' => $req['raw'],
			'http_code' => $req['http_code'],
			'curl_errno' => $req['curl_errno'],
			'curl_error' => $req['curl_error'],
			'headers' => $req['headers'] ?? array(),
		);
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
	 * Parse one raw header line into the name => list-of-values map.
	 * A new "HTTP/" status line (redirect hop) resets the block; the
	 * status line itself and blank lines are dropped. Repeated headers
	 * (e.g. Set-Cookie) accumulate as list entries — never merged.
	 *
	 * @param array  $headers Current header map
	 * @param string $line    Raw line as passed to CURLOPT_HEADERFUNCTION
	 * @return array Updated header map
	 */
	protected static function parseHeaderLine(array $headers, string $line): array {
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

	/**
	 * Find a request ID by its cURL handle.
	 *
	 * @param resource $handle
	 * @return int|null
	 */
	protected function findRequestIdByHandle($handle) {
		foreach ($this->requests as $id => $req) {
			if ($req['handle'] === $handle) {
				return $id;
			}
		}
		return null;
	}

}
