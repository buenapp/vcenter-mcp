<?php
/**
 * vCenter MCP Server — vSphere REST Client
 *
 * Client for the vSphere Automation API (/api/...). Session is a token
 * from POST /api/session (HTTP Basic), sent as the
 * 'vmware-api-session-id' header; DELETE /api/session ends it. A 401
 * triggers one re-login and a single retry of the failed request.
 *
 * Shares the instance's Tortilla\HttpClient engine; tests inject the
 * same callable seam as ForgejoMCP: fn(method, url, headers, body)
 * returning ['code' => int, 'body' => string].
 *
 * @package    VCenterMCP\VCenter
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

namespace VCenter;

class RestClient
{
	/** @var Instance Owning instance (credentials, engine, config) */
	private Instance $instance;

	/** @var string|null vmware-api-session-id token */
	private ?string $sessionToken = null;

	/** @var int HTTP status of the most recent request (0 = none yet) */
	private int $lastHttpCode = 0;

	public function __construct(Instance $instance)
	{
		$this->instance = $instance;
	}

	public function get(string $endpoint, array $query = []): mixed
	{
		return $this->request('GET', $endpoint, null, $query);
	}

	public function post(string $endpoint, ?array $data = null, array $query = []): mixed
	{
		return $this->request('POST', $endpoint, $data, $query);
	}

	public function patch(string $endpoint, ?array $data = null): mixed
	{
		return $this->request('PATCH', $endpoint, $data);
	}

	public function put(string $endpoint, ?array $data = null): mixed
	{
		return $this->request('PUT', $endpoint, $data);
	}

	public function delete(string $endpoint): mixed
	{
		return $this->request('DELETE', $endpoint);
	}

	/** Whether a session token has been acquired. */
	public function hasSession(): bool
	{
		return $this->sessionToken !== null;
	}

	/**
	 * End the REST session. Best-effort; safe to call at shutdown.
	 */
	public function logout(): void
	{
		if ($this->sessionToken === null) {
			return;
		}
		try {
			$this->request('DELETE', 'session', null, [], false);
		} catch (\Throwable $e) {
			// best-effort
		}
		$this->sessionToken = null;
	}

	/**
	 * POST /api/session with HTTP Basic credentials; stores the token.
	 *
	 * @throws VCenterException
	 */
	private function login(): void
	{
		$headers = [
			'Accept: application/json',
			'Authorization: Basic ' . base64_encode($this->instance->username() . ':' . $this->instance->password()),
		];
		[$code, $body] = $this->send('POST', 'session', $headers, null);
		if ($code < 200 || $code >= 300) {
			throw VCenterException::fromRest($code, $body, $this->instance->url() . '/api/session');
		}
		$token = json_decode($body, true);
		$this->sessionToken = is_string($token) ? $token : (string) $token;
	}

	/**
	 * Authenticated request with one re-login + retry on 401.
	 *
	 * @return mixed Decoded JSON (arrays for objects/lists, string for
	 *               scalar results such as the session token)
	 * @throws VCenterException
	 */
	private function request(string $method, string $endpoint, ?array $data = null, array $query = [], bool $retryOnAuth = true): mixed
	{
		if ($this->sessionToken === null) {
			$this->login();
		}

		$headers = [
			'Accept: application/json',
			'vmware-api-session-id: ' . $this->sessionToken,
		];
		if ($data !== null) {
			$headers[] = 'Content-Type: application/json';
		}
		$body = $data !== null ? json_encode($data) : null;

		[$code, $responseBody] = $this->send($method, $endpoint, $headers, $body, $query);

		if ($code === 401 && $retryOnAuth) {
			$this->sessionToken = null;
			$this->login();
			$headers[1] = 'vmware-api-session-id: ' . $this->sessionToken;
			[$code, $responseBody] = $this->send($method, $endpoint, $headers, $body, $query);
		}

		$url = $this->instance->url() . '/api/' . ltrim($endpoint, '/');
		if ($code === 0) {
			throw new VCenterException("Transport error for {$url}: no HTTP response received", 0, 'Transport');
		}
		if ($code === 204 || $responseBody === '') {
			if ($code >= 400) {
				throw VCenterException::fromRest($code, $responseBody, $url);
			}
			return null;
		}
		if ($code >= 400) {
			throw VCenterException::fromRest($code, $responseBody, $url);
		}

		$decoded = json_decode($responseBody, true);
		if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
			throw new VCenterException("Invalid JSON response from {$url}: " . json_last_error_msg(), 0, 'ParseError');
		}
		return $decoded;
	}

	/**
	 * Send through the injected callable (tests) or the shared engine.
	 *
	 * @return array{0:int,1:string} HTTP code and raw body
	 * @throws VCenterException On transport failure
	 */
	private function send(string $method, string $endpoint, array $headers, ?string $body, array $query = []): array
	{
		$path = 'api/' . ltrim($endpoint, '/');
		$url = $this->instance->url() . '/' . $path;
		if (!empty($query)) {
			$url .= '?' . $this->buildQuery($query);
		}

		$fake = $this->instance->httpClient();
		if ($fake !== null) {
			$response = $fake($method, $url, $headers, $body);
			$this->lastHttpCode = $response['code'];
			return [$response['code'], (string) ($response['body'] ?? '')];
		}

		try {
			$result = $this->instance->http()->call($path . (!empty($query) ? '?' . $this->buildQuery($query) : ''), $body, $method, $headers, null, 'raw');
		} catch (\Exception $e) {
			throw new VCenterException("HTTP error for {$url}: " . $e->getMessage(), 0, 'Transport', $e);
		}

		$http = $this->instance->http();
		$this->lastHttpCode = $http->getHttpCode();
		if ($this->lastHttpCode === 0 || $http->getLastCurlErrno() !== 0) {
			throw new VCenterException("Transport error for {$url}: " . $http->getLastCurlError(), 0, 'Transport');
		}
		return [$this->lastHttpCode, is_string($result) ? $result : ''];
	}

	/**
	 * vCenter list filters take comma-separated repeated values; keep
	 * them unencoded the way the API expects for lists.
	 */
	private function buildQuery(array $query): string
	{
		$parts = [];
		foreach ($query as $key => $value) {
			foreach ((array) $value as $v) {
				$parts[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $v);
			}
		}
		return implode('&', $parts);
	}

	/** HTTP status of the most recent request. */
	public function getLastHttpCode(): int
	{
		return $this->lastHttpCode;
	}
}
