<?php

/* Enchilada Framework 3.0 
 * Non-blocking HTTP Client (curl_multi)
 *
 * Provides a multi-request HTTP client built on curl_multi_* that can be
 * driven by a PHP event loop or manual polling: queue() requests, call
 * tick() until getResult() hands each outcome over. Settings, request
 * building, decoding and easy-handle setup come from EnchiladaCurl.
 *
 * Software License Agreement (BSD License)
 *
 * Copyright (c) 2013-2026, The Daniel Morante Company, Inc.
 * All rights reserved.
 */

class EnchiladaMultiHTTP extends EnchiladaCurl {

	/** @var CurlMultiHandle */
	protected $multiHandle;

	/**
	 * In-flight requests, requestId => [
	 *   'handle' => CurlHandle,
	 *   'io'     => transfer state (streamed body, headers, SSE completion),
	 *   'format' => 'json'|'form'|'raw' — plus suffix options
	 *               'json,sse' (collect SSE frames, complete on '[DONE]')
	 *               and 'json,stream-keepalive' (CURLOPT_TIMEOUT disabled;
	 *               stall watchdog via CURLOPT_LOW_SPEED_LIMIT/TIME),
	 * ]
	 *
	 * A completed request moves to EnchiladaCurl::$outcomes until
	 * getResult() hands it over.
	 */
	protected $requests = array();

	/** @var array<int,int> spl_object_id(CurlHandle) => requestId, in-flight requests only */
	protected $handleIndex = array();

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
		parent::__construct($api_endpoint);
		$this->multiHandle = curl_multi_init();
	}

	public function __destruct() {
		foreach ($this->requests as $req) {
			curl_multi_remove_handle($this->multiHandle, $req['handle']);
			self::releaseHandle($req['handle']);
		}
		$this->requests = array();
		$this->handleIndex = array();

		if ($this->multiHandle !== null) {
			curl_multi_close($this->multiHandle);
			$this->multiHandle = null;
		}
	}

	/**
	 * Queue a request described by buildSpec().
	 *
	 * @param array         $spec          From buildSpec()
	 * @param callable|null $writeCallback As for queue()
	 * @return int Request ID
	 */
	public function queueSpec(array $spec, $writeCallback = null) {
		$requestId = $this->nextRequestId++;
		$io = self::newTransferState();
		$handle = self::createHandle($spec, $io, $writeCallback, $this->share);

		$this->requests[$requestId] = array(
			'handle' => $handle,
			'io' => $io,
			'format' => $spec['format'],
		);

		curl_multi_add_handle($this->multiHandle, $handle);
		$this->handleIndex[spl_object_id($handle)] = $requestId;

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
			curl_multi_remove_handle($this->multiHandle, $handle);

			$requestId = $this->findRequestIdByHandle($handle);
			if ($requestId === null) {
				self::releaseHandle($handle);
				continue;
			}
			$req = $this->requests[$requestId];
			unset($this->requests[$requestId], $this->handleIndex[spl_object_id($handle)]);
			$this->completeHandle($requestId, $req['format'], $handle, (int) $info['result'], $req['io'], curl_multi_getcontent($handle));
		}
	}

	/**
	 * Returns true if there are any pending requests.
	 */
	public function hasPendingRequests() {
		return !empty($this->handleIndex);
	}

	/**
	 * Find the in-flight request ID for a cURL handle.
	 *
	 * @param CurlHandle $handle
	 * @return int|null
	 */
	protected function findRequestIdByHandle($handle) {
		return $this->handleIndex[spl_object_id($handle)] ?? null;
	}

}
