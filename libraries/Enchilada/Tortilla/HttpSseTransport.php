<?php

namespace Enchilada\Tortilla;

/* Enchilada Framework 3.0
 * Streamable HTTP Transport
 *
 * Request handler for JSON-RPC servers communicating over Streamable HTTP
 * with optional SSE framing. Implements the MCP specification (2025-03-26)
 * for HTTP-based communication.
 *
 * Handles CORS, session management, content negotiation (JSON vs SSE),
 * protocol version validation, and request routing to the handler.
 * TYPE-decoupled from the protocol core — it receives primitives from
 * the application, never an MCP-class reference — but NOT policy-free:
 * the era rules, session semantics and header vocabulary it enforces
 * (MCP-Protocol-Version, Mcp-Method, Mcp-Name, MCP-Session-Id) come from
 * the MCP spec text. Splitting that policy out of the wire mechanics is
 * deferred (see the plan's "HTTP transport policy de-duplication").
 *
 * Usage:
 *   $server = new EnchiladaMCP\McpServer('my-server', '1.0.0');
 *   $server->register($myTools);
 *   $transport = new HttpSseTransport(
 *       $server->handleRequest(...),
 *       $server->modernVersions(),
 *       $server->legacyVersions(),
 *   );
 *   $transport->handle();
 *
 * @see https://modelcontextprotocol.io/specification/2025-03-26/basic/transports#streamable-http
 *
 * Software License Agreement (BSD License)
 *
 * Copyright (c) 2026, The Daniel Morante Company, Inc.
 * All rights reserved.
 *
 * Redistribution and use of this software in source and binary forms,
 * with or without modification, are permitted provided that the following
 * conditions are met:
 *
 *   Redistributions of source code must retain the above copyright notice,
 *   this list of conditions and the following disclaimer.
 *
 *   Redistributions in binary form must reproduce the above copyright notice,
 *   this list of conditions and the following disclaimer in the documentation
 *   and/or other materials provided with the distribution.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
 * ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE
 * LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
 * CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 */

class HttpSseTransport
{
	/** @var \Closure Handles one decoded JSON-RPC request: function(array $request): array */
	private \Closure $handler;

	/** @var string[] Modern-era (per-request metadata, stateless) revisions accepted. */
	private array $modernVersions;

	/** @var string[] Handshake-era (initialize-based) versions accepted. */
	private array $legacyVersions;

	/** @var string Directory for session token files. */
	private string $sessionDir;

	/**
	 * @var string[] Browser Origin header values accepted (2026-07-28 DNS
	 *               rebinding defense, MUST). Default empty: any request
	 *               carrying an Origin header is refused; non-browser MCP
	 *               clients send no Origin and are unaffected. The literal
	 *               '*' opts out entirely.
	 */
	private array $allowedOrigins = [];

	/** @var callable|null Optional callback invoked after each tools/call request. */
	private $afterToolsCall = null;

	/**
	 * Create a new HTTP SSE transport.
	 *
	 * The transport never types against a protocol server; the
	 * application composes both. An MCP application passes:
	 *
	 * @param callable    $handler        function(array $request): array —
	 *                                    decoded JSON-RPC request in,
	 *                                    response out (e.g.
	 *                                    McpServer::handleRequest())
	 * @param string[]    $modernVersions Modern-era revisions accepted
	 *                                    (e.g. McpServer::modernVersions())
	 * @param string[]    $legacyVersions Handshake-era revisions accepted
	 *                                    (e.g. McpServer::legacyVersions())
	 * @param string|null $sessionDir     Session token directory (default: sys_get_temp_dir()/mcp-sessions)
	 */
	public function __construct(callable $handler, array $modernVersions, array $legacyVersions, ?string $sessionDir = null)
	{
		$this->handler = $handler(...);
		$this->modernVersions = $modernVersions;
		$this->legacyVersions = $legacyVersions;
		$this->sessionDir = $sessionDir ?? sys_get_temp_dir() . '/mcp-sessions';
	}

	/**
	 * Set the browser Origin allow-list (default: refuse all requests that
	 * carry an Origin header). Pass ['*'] to allow any Origin (disables
	 * the DNS-rebinding defense).
	 *
	 * @param string[] $origins Accepted Origin header values, or ['*']
	 */
	public function setAllowedOrigins(array $origins): void
	{
		$this->allowedOrigins = $origins;
	}

	/**
	 * Register a callback invoked after each tools/call request.
	 *
	 * Use this for domain-specific post-processing such as heartbeat
	 * writes or metrics collection. The callback receives the decoded
	 * request array and the response array.
	 *
	 * @param callable $callback function(array $request, array $response): void
	 */
	public function onAfterToolsCall(callable $callback): void
	{
		$this->afterToolsCall = $callback;
	}

	/**
	 * Handle the current HTTP request.
	 *
	 * Reads the request method, validates headers, dispatches to the
	 * request handler, and writes the response as JSON or SSE depending on
	 * the client's Accept header. Terminates the PHP process via exit.
	 */
	public function handle(): void
	{
		$method = $_SERVER['REQUEST_METHOD'];

		// CORS headers. The allow-all wildcard is only sent when the
		// operator opted out of Origin validation; otherwise reflect the
		// (validated) request Origin, or omit.
		$requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? null;
		if (in_array('*', $this->allowedOrigins, true)) {
			header('Access-Control-Allow-Origin: ' . ($requestOrigin ?? '*'));
		} elseif ($requestOrigin !== null && in_array($requestOrigin, $this->allowedOrigins, true)) {
			header('Access-Control-Allow-Origin: ' . $requestOrigin);
		}
		header('Access-Control-Allow-Methods: POST, GET, DELETE, OPTIONS');
		header('Access-Control-Allow-Headers: Content-Type, Accept, MCP-Session-Id, MCP-Protocol-Version, Mcp-Method, Mcp-Name');

		// DNS-rebinding defense (MUST in every revision since 2025-03-26):
		// reject requests from browser Origins not on the allow-list. HEAD on
		// the endpoint would have no side effects, but the same rule applies.
		if (!$this->checkOrigin()) {
			exit;
		}

		// Handle preflight
		if ($method === 'OPTIONS') {
			http_response_code(204);
			exit;
		}

		// GET: Server does not offer a server-initiated SSE stream.
		// 405 is spec-allowed, but it must not fall through to PHP's default
		// text/html — strict clients surface 'unexpected content type'
		// instead of 'no SSE stream' (heliofane issue #7).
		if ($method === 'GET') {
			header('Allow: POST, DELETE, OPTIONS');
			$this->sendJsonError(-32000, 'Method Not Allowed: server does not offer a server-initiated SSE stream', 405);
			exit;
		}

		// DELETE: Client-initiated session termination
		if ($method === 'DELETE') {
			$this->handleSessionDelete();
			exit;
		}

		// Only POST beyond this point
		if ($method !== 'POST') {
			header('Allow: POST, DELETE, OPTIONS');
			$this->sendJsonError(-32000, 'Method Not Allowed: only POST is used by this transport', 405);
			exit;
		}

		// Validate Content-Type
		$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
		if (strpos($contentType, 'application/json') === false) {
			$this->sendJsonError(-32600, 'Content-Type must be application/json', 400);
			exit;
		}

		// Read and parse request body
		$body = file_get_contents('php://input');
		$request = json_decode($body, true);

		if ($request === null) {
			$this->sendJsonError(-32700, 'Parse error', 400);
			exit;
		}

		$rpcMethod = $request['method'] ?? null;
		$hasId = array_key_exists('id', $request);
		$isInitialize = ($rpcMethod === 'initialize');

		// 2026-07-28 dual-era: modern requests carry their protocol version
		// in body _meta and must have matching metadata headers validated
		// against it (Mcp-Method/Mcp-Name, -32020 on mismatch). Sessions do
		// not exist for modern requests — Mcp-Session-Id is ignored.
		$modern = RequestEra::isModern($request, $this->modernVersions);

		if ($modern) {
			if (!$this->validateModernHeaders($request)) {
				exit;
			}
		} elseif (!$isInitialize) {
			// Legacy path unchanged: session (if presented) then version.
			if (!$this->validateSession()) {
				exit;
			}
			if (!$this->validateProtocolVersion()) {
				exit;
			}
		}

		// Handle notifications (no id): return 202 Accepted
		if (!$hasId) {
			($this->handler)($request);
			http_response_code(202);
			exit;
		}

		// Handle JSON-RPC request
		$response = ($this->handler)($request);

		// Post-processing hook for tools/call
		if ($rpcMethod === 'tools/call' && $this->afterToolsCall !== null) {
			try {
				($this->afterToolsCall)($request, $response);
			} catch (\Throwable $e) {
				// Hook failure is non-fatal
			}
		}

		// Create session on initialize (legacy era only — the modern
		// revision has no protocol-level sessions to mint).
		if ($isInitialize && isset($response['result']) && !$modern) {
			$this->createSession();
		}

		// 2026-07-28: unknown RPC methods on modern requests answer 404
		// with -32601 (distinguishes a hosted-but-unimplemented method from
		// an absent endpoint during client era detection).
		if ($modern && ($response['error']['code'] ?? null) === -32601) {
			http_response_code(404);
			header('Content-Type: application/json');
			echo json_encode($response, JSON_UNESCAPED_SLASHES);
			exit;
		}

		// Send response (SSE or JSON)
		$this->sendResponse($response);
	}

	/**
	 * DNS-rebinding defense. When the request carries a browser Origin
	 * header, it must be on the allow-list (or the operator opted out with
	 * '*'); requests without an Origin header are non-browser clients and
	 * pass. Rejections answer HTTP 403 with an id-less JSON-RPC error body.
	 */
	private function checkOrigin(): bool
	{
		$origin = $_SERVER['HTTP_ORIGIN'] ?? null;
		if ($origin === null || in_array('*', $this->allowedOrigins, true) || in_array($origin, $this->allowedOrigins, true)) {
			return true;
		}
		http_response_code(403);
		header('Content-Type: application/json');
		echo json_encode([
			'jsonrpc' => '2.0',
			'error' => ['code' => -32600, 'message' => 'Forbidden: Origin not allowed'],
		]);
		return false;
	}

	/**
	 * Validate 2026-07-28 request-metadata headers against the request
	 * body: MCP-Protocol-Version must equal the _meta protocolVersion,
	 * Mcp-Method must equal the body method, and Mcp-Name (required for
	 * tools/call and resources/read) must equal params.name/params.uri
	 * after `=?base64?…?=` sentinel decoding. Failures answer 400 with
	 * -32020 HeaderMismatch.
	 */
	private function validateModernHeaders(array $request): bool
	{
		$fail = function (string $detail): bool {
			$this->sendJsonError(RequestEra::ERR_HEADER_MISMATCH, 'Header mismatch: ' . $detail, 400);
			return false;
		};

		$declared = RequestEra::declaredVersionOf($request);
		$headerVersion = $_SERVER['HTTP_MCP_PROTOCOL_VERSION'] ?? null;
		if ($headerVersion === null || $headerVersion !== $declared) {
			return $fail("MCP-Protocol-Version header ('" . ($headerVersion ?? '(missing)') . "') does not match the _meta protocolVersion ('{$declared}')");
		}

		$methodHeader = $_SERVER['HTTP_MCP_METHOD'] ?? null;
		$bodyMethod = $request['method'] ?? null;
		if ($methodHeader === null || $methodHeader !== $bodyMethod) {
			return $fail("Mcp-Method header ('" . ($methodHeader ?? '(missing)') . "') does not match body method ('{$bodyMethod}')");
		}

		if (in_array($bodyMethod, ['tools/call', 'resources/read', 'prompts/get'], true)) {
			[$ok, $nameValue] = RequestEra::decodeSentinelHeaderValue($_SERVER['HTTP_MCP_NAME'] ?? null);
			if (!$ok) {
				return $fail('Mcp-Name header is missing or malformed');
			}
			$expected = $bodyMethod === 'tools/call'
				? ($request['params']['name'] ?? null)
				: ($request['params']['uri'] ?? null);
			if (!is_string($expected) || $nameValue !== $expected) {
				return $fail("Mcp-Name header value '{$nameValue}' does not match body value '" . (is_string($expected) ? $expected : '(missing)') . "'");
			}
		}

		return true;
	}

	/**
	 * Handle DELETE request for session termination.
	 */
	private function handleSessionDelete(): void
	{
		$sessionId = $_SERVER['HTTP_MCP_SESSION_ID'] ?? null;
		if ($sessionId !== null) {
			$sessionFile = $this->sessionDir . '/' . basename($sessionId);
			if (file_exists($sessionFile)) {
				unlink($sessionFile);
			}
		}
		// JSON content-type so strict clients do not trip on PHP's default
		// text/html for a body-less 200 (heliofane issue #7).
		http_response_code(200);
		header('Content-Type: application/json');
	}

	/**
	 * Validate session ID on non-initialize requests.
	 *
	 * @return bool True if valid (or no session header sent), false if invalid session
	 */
	private function validateSession(): bool
	{
		$sessionId = $_SERVER['HTTP_MCP_SESSION_ID'] ?? null;
		if ($sessionId === null) {
			return true;
		}

		$sessionFile = $this->sessionDir . '/' . basename($sessionId);
		if (!file_exists($sessionFile)) {
			http_response_code(404);
			header('Content-Type: application/json');
			echo json_encode([
				'jsonrpc' => '2.0',
				'error' => [
					'code' => -32600,
					'message' => 'Session not found. Send a new InitializeRequest.',
				],
			]);
			return false;
		}

		return true;
	}

	/**
	 * Validate MCP-Protocol-Version header on non-initialize requests.
	 *
	 * @return bool True if valid or absent, false if unsupported version
	 */
	private function validateProtocolVersion(): bool
	{
		$protoVersion = $_SERVER['HTTP_MCP_PROTOCOL_VERSION'] ?? null;
		if ($protoVersion === null) {
			return true;
		}

		// A modern-era header on a request the era check already classified
		// as legacy (no modern _meta) contradicts the body — the header
		// value MUST match the _meta field, so this is a HeaderMismatch.
		if (in_array($protoVersion, $this->modernVersions, true)) {
			$this->sendJsonError(RequestEra::ERR_HEADER_MISMATCH, 'Header mismatch: MCP-Protocol-Version declares ' . $protoVersion . ' but the request body carries no matching _meta protocolVersion', 400);
			return false;
		}

		// Legacy-era acceptance list supplied by the application (with
		// 2024-11-05 grandfathered: transport-only tolerance kept).
		$legacy = array_merge($this->legacyVersions, ['2024-11-05']);
		if (!in_array($protoVersion, $legacy, true)) {
			$this->sendJsonError(-32600, 'Unsupported MCP-Protocol-Version', 400);
			return false;
		}

		return true;
	}

	/**
	 * Create a new session and send the session ID header.
	 */
	private function createSession(): void
	{
		$newSessionId = bin2hex(random_bytes(32));
		if (!is_dir($this->sessionDir)) {
			mkdir($this->sessionDir, 0700, true);
		}
		file_put_contents($this->sessionDir . '/' . $newSessionId, time());
		header('MCP-Session-Id: ' . $newSessionId);
	}

	/**
	 * Send response as SSE or JSON based on client Accept header.
	 *
	 * @param array<string,mixed> $response JSON-RPC response array
	 */
	private function sendResponse(array $response): void
	{
		$accept = $_SERVER['HTTP_ACCEPT'] ?? '';
		$wantsSSE = str_contains($accept, 'text/event-stream');

		$json = json_encode($response, JSON_UNESCAPED_SLASHES);

		if ($wantsSSE) {
			header('Content-Type: text/event-stream');
			header('Cache-Control: no-cache');
			header('Connection: keep-alive');
			header('X-Accel-Buffering: no');

			echo "event: message\n";
			echo "data: {$json}\n\n";

			if (function_exists('ob_end_flush')) {
				@ob_end_flush();
			}
			flush();
		} else {
			header('Content-Type: application/json');
			echo $json;
		}
	}

	/**
	 * Send a JSON-RPC error response and set HTTP status code.
	 *
	 * @param int    $code    JSON-RPC error code
	 * @param string $message Error message
	 * @param int    $status  HTTP status code (default: 400)
	 */
	private function sendJsonError(int $code, string $message, int $status = 400): void
	{
		http_response_code($status);
		header('Content-Type: application/json');
		echo json_encode([
			'jsonrpc' => '2.0',
			'error' => ['code' => $code, 'message' => $message],
		]);
	}
}
