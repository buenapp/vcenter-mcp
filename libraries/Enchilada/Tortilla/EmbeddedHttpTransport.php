<?php

namespace Enchilada\Tortilla;

/* Enchilada Framework 3.0
 * Embedded HTTP Transport (Reactor-Driven)
 *
 * Runs a JSON-RPC Streamable HTTP endpoint inside a long-running
 * process using EnchiladaHttpServer and a Comal reactor. Handles
 * transport concerns: JSON-RPC routing, session management, bearer
 * token auth, CORS, and SSE/JSON content negotiation.
 *
 * TYPE-decoupled from the protocol core (primitives only, no MCP-class
 * reference) but NOT policy-free: the session/header/era rules come
 * from the MCP Streamable HTTP spec. Splitting that policy out of the
 * wire mechanics is deferred (see the plan's "HTTP transport policy
 * de-duplication").
 *
 * NOTE this is the one transport that genuinely requires Comal: it *is*
 * a reactor-driven HTTP server, and it spawns each dispatch as a Comal
 * Fiber task. HttpClient and StdioTransport are loop-agnostic — they
 * depend on the EventLoop port (see EventLoop.php), so an
 * installation that only serves stdio or PHP-FPM HTTP need not vendor
 * Comal at all.
 *
 * Usage:
 *   $mcpServer = new EnchiladaMCP\McpServer('myserver', '1.0.0');
 *   $mcpServer->register($myTools);
 *
 *   $transport = new EmbeddedHttpTransport(
 *       $mcpServer->handleRequest(...),
 *       $mcpServer->modernVersions(),
 *       $mcpServer->legacyVersions(),
 *       $reactor,
 *       ['port' => 8808, 'token' => 'my-secret'],
 *   );
 *   $transport->listen();
 *   $reactor->run();
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
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE
 * FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL
 * DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
 * SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY,
 * OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */

use Enchilada\Comal\ReactorInterface;

class EmbeddedHttpTransport
{
	/** @var \Closure Handles one decoded JSON-RPC request: function(array $request): array */
	private \Closure $handler;

	/** @var string[] Modern-era (per-request metadata, stateless) revisions accepted. */
	private array $modernVersions;

	/** @var string[] Handshake-era (initialize-based) versions accepted. */
	private array $legacyVersions;

	/** @var ReactorInterface */
	private ReactorInterface $reactor;

	/** @var \EnchiladaREST\EnchiladaHttpServer */
	private \EnchiladaREST\EnchiladaHttpServer $httpServer;

	/** @var string|null Bearer token for authentication (null = no auth) */
	private ?string $token;

	/** @var string Directory for session token files */
	private string $sessionDir;

	/**
	 * @var string[] Browser Origin header values accepted (2026-07-28 DNS
	 *               rebinding defense, MUST). Default empty: any request
	 *               carrying an Origin header is refused; non-browser MCP
	 *               clients send no Origin and are unaffected. The literal
	 *               '*' opts out entirely.
	 */
	private array $allowedOrigins;

	/** @var callable|null */
	private $logger = null;

	/**
	 * Create a new embedded HTTP transport.
	 *
	 * The transport never types against a protocol server; the
	 * application composes both. An MCP application passes:
	 *
	 * @param callable         $handler        function(array $request): array —
	 *                                         decoded JSON-RPC request in,
	 *                                         response out (e.g.
	 *                                         McpServer::handleRequest())
	 * @param string[]         $modernVersions Modern-era revisions accepted
	 *                                         (e.g. McpServer::modernVersions())
	 * @param string[]         $legacyVersions Handshake-era revisions accepted
	 *                                         (e.g. McpServer::legacyVersions())
	 * @param ReactorInterface $reactor        Event loop for I/O
	 * @param array            $options        Configuration:
	 *   - host: string (default '0.0.0.0')
	 *   - port: int (default 8808)
	 *   - token: string|null (bearer token, null to disable auth)
	 *   - session_dir: string (default sys_get_temp_dir()/mcp-sessions)
	 *   - allowed_origins: string[] (default []; ['*'] allows any Origin)
	 */
	public function __construct(callable $handler, array $modernVersions, array $legacyVersions, ReactorInterface $reactor, array $options = [])
	{
		$this->handler = $handler(...);
		$this->modernVersions = $modernVersions;
		$this->legacyVersions = $legacyVersions;
		$this->reactor = $reactor;
		$this->token = $options['token'] ?? null;
		$this->sessionDir = $options['session_dir'] ?? sys_get_temp_dir() . '/mcp-sessions';
		$this->allowedOrigins = $options['allowed_origins'] ?? [];

		// Create the underlying HTTP server with our MCP request handler
		$this->httpServer = new \EnchiladaREST\EnchiladaHttpServer($reactor, function (\EnchiladaREST\EnchiladaRequest $req, \EnchiladaREST\EnchiladaResponse $res) {
			$this->handleMcpRequest($req, $res);
		}, [
			'host' => $options['host'] ?? '0.0.0.0',
			'port' => $options['port'] ?? 8808,
		]);
	}

	/**
	 * Set a logger callback.
	 *
	 * @param callable $logger fn(string $message): void
	 */
	public function setLogger(callable $logger): void
	{
		$this->logger = $logger;
		$this->httpServer->setLogger($logger);
	}

	/**
	 * Start listening for MCP connections.
	 */
	public function listen(): void
	{
		$this->httpServer->listen();
		$this->log("MCP transport listening on port " . $this->httpServer->getPort());
	}

	/**
	 * Stop the transport.
	 */
	public function close(): void
	{
		$this->httpServer->close();
	}

	/**
	 * Handle an incoming HTTP request as MCP.
	 */
	private function handleMcpRequest(\EnchiladaREST\EnchiladaRequest $req, \EnchiladaREST\EnchiladaResponse $res): void
	{
		$method = $req->getMethod();
		$requestOrigin = $req->getHeader('origin');

		// CORS headers on all responses. The allow-all wildcard only when
		// the operator opted out of Origin validation; otherwise reflect the
		// validated request Origin, or omit.
		if (in_array('*', $this->allowedOrigins, true)) {
			$res->setHeader('Access-Control-Allow-Origin', $requestOrigin ?? '*');
		} elseif ($requestOrigin !== null && in_array($requestOrigin, $this->allowedOrigins, true)) {
			$res->setHeader('Access-Control-Allow-Origin', $requestOrigin);
		}
		$res->setHeader('Access-Control-Allow-Methods', 'POST, GET, DELETE, OPTIONS');
		$res->setHeader('Access-Control-Allow-Headers', 'Content-Type, Accept, Authorization, MCP-Session-Id, MCP-Protocol-Version, Mcp-Method, Mcp-Name');

		// DNS-rebinding defense (MUST in every revision since 2025-03-26)
		if (!$this->checkOrigin($req, $res)) {
			return;
		}

		// Preflight
		if ($method === 'OPTIONS') {
			$res->noContent();
			return;
		}

		// GET: not supported (no server-initiated SSE stream)
		if ($method === 'GET') {
			$res->setStatus(405);
			$res->json(['error' => 'Method not allowed']);
			return;
		}

		// DELETE: session termination
		if ($method === 'DELETE') {
			$this->handleSessionDelete($req, $res);
			return;
		}

		// Only POST beyond this point
		if ($method !== 'POST') {
			$res->setStatus(405);
			$res->json(['error' => 'Method not allowed']);
			return;
		}

		// Validate Content-Type
		$contentType = $req->getHeader('content-type', '');
		if (strpos($contentType, 'application/json') === false) {
			$res->setStatus(400);
			$res->json(['jsonrpc' => '2.0', 'error' => ['code' => -32600, 'message' => 'Content-Type must be application/json']]);
			return;
		}

		// Bearer token auth
		if ($this->token !== null) {
			$authHeader = $req->getHeader('authorization', '');
			$providedToken = '';
			if (str_starts_with($authHeader, 'Bearer ')) {
				$providedToken = substr($authHeader, 7);
			}
			if ($providedToken !== $this->token) {
				$res->unauthorized('Invalid or missing bearer token');
				return;
			}
		}

		// Parse JSON-RPC body
		$body = $req->getRawBody();
		$request = json_decode($body, true);

		if ($request === null) {
			$res->setStatus(400);
			$res->json(['jsonrpc' => '2.0', 'error' => ['code' => -32700, 'message' => 'Parse error']]);
			return;
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
			if (!$this->validateModernHeaders($request, $req, $res)) {
				return;
			}
		} elseif (!$isInitialize) {
			// Legacy path unchanged: session (if presented) then version.
			if (!$this->validateSession($req, $res)) {
				return;
			}
			if (!$this->validateProtocolVersion($req, $res)) {
				return;
			}
		}

		// Handle notifications (no id): return 202 Accepted
		if (!$hasId) {
			($this->handler)($request);
			$res->setStatus(202);
			$res->send();
			return;
		}

		// Dispatch tool calls inside a Fiber so tools that opt into
		// non-blocking I/O (via Async\read, Async\write, etc.) can
		// suspend without freezing the reactor. Tools that use plain
		// synchronous I/O continue to work unchanged.
		\Enchilada\Comal\Async\spawn($this->reactor, function () use ($request, $req, $res, $isInitialize, $modern) {
			$response = ($this->handler)($request);

			// Create session on successful initialize (legacy era only —
			// the modern revision has no protocol-level sessions to mint).
			if ($isInitialize && isset($response['result']) && !$modern) {
				$sessionId = $this->createSession();
				$res->setHeader('MCP-Session-Id', $sessionId);
			}

			// 2026-07-28: unknown RPC methods on modern requests answer 404
			// with -32601 (distinguishes a hosted-but-unimplemented method
			// from an absent endpoint during client era detection).
			if ($modern && ($response['error']['code'] ?? null) === -32601) {
				$res->setStatus(404);
				$res->json($response);
				return;
			}

			// Send response
			$this->sendMcpResponse($req, $res, $response);
		});
	}

	/**
	 * DNS-rebinding defense. When the request carries a browser Origin
	 * header, it must be on the allow-list (or the operator opted out with
	 * '*'); requests without an Origin header are non-browser clients and
	 * pass. Rejections answer HTTP 403 with an id-less JSON-RPC error body.
	 */
	private function checkOrigin(\EnchiladaREST\EnchiladaRequest $req, \EnchiladaREST\EnchiladaResponse $res): bool
	{
		$origin = $req->getHeader('origin');
		if ($origin === null || in_array('*', $this->allowedOrigins, true) || in_array($origin, $this->allowedOrigins, true)) {
			return true;
		}
		$res->setStatus(403);
		$res->json([
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
	private function validateModernHeaders(array $request, \EnchiladaREST\EnchiladaRequest $req, \EnchiladaREST\EnchiladaResponse $res): bool
	{
		$fail = function (string $detail) use ($res): bool {
			$res->setStatus(400);
			$res->json([
				'jsonrpc' => '2.0',
				'error' => ['code' => RequestEra::ERR_HEADER_MISMATCH, 'message' => 'Header mismatch: ' . $detail],
			]);
			return false;
		};

		$declared = RequestEra::declaredVersionOf($request);
		$headerVersion = $req->getHeader('mcp-protocol-version');
		if ($headerVersion === null || $headerVersion !== $declared) {
			return $fail("MCP-Protocol-Version header ('" . ($headerVersion ?? '(missing)') . "') does not match the _meta protocolVersion ('{$declared}')");
		}

		$methodHeader = $req->getHeader('mcp-method');
		$bodyMethod = $request['method'] ?? null;
		if ($methodHeader === null || $methodHeader !== $bodyMethod) {
			return $fail("Mcp-Method header ('" . ($methodHeader ?? '(missing)') . "') does not match body method ('{$bodyMethod}')");
		}

		if (in_array($bodyMethod, ['tools/call', 'resources/read', 'prompts/get'], true)) {
			[$ok, $nameValue] = RequestEra::decodeSentinelHeaderValue($req->getHeader('mcp-name'));
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
	private function handleSessionDelete(\EnchiladaREST\EnchiladaRequest $req, \EnchiladaREST\EnchiladaResponse $res): void
	{
		$sessionId = $req->getHeader('mcp-session-id');
		if ($sessionId !== null) {
			$sessionFile = $this->sessionDir . '/' . basename($sessionId);
			if (file_exists($sessionFile)) {
				@unlink($sessionFile);
			}
		}
		$res->setStatus(200);
		$res->send();
	}

	/**
	 * Validate session ID on non-initialize requests.
	 *
	 * @return bool True if valid, false if response was sent with error
	 */
	private function validateSession(\EnchiladaREST\EnchiladaRequest $req, \EnchiladaREST\EnchiladaResponse $res): bool
	{
		$sessionId = $req->getHeader('mcp-session-id');
		if ($sessionId === null) {
			return true; // No session header = OK (stateless mode)
		}

		$sessionFile = $this->sessionDir . '/' . basename($sessionId);
		if (!file_exists($sessionFile)) {
			$res->setStatus(404);
			$res->json([
				'jsonrpc' => '2.0',
				'error' => ['code' => -32600, 'message' => 'Session not found. Send a new InitializeRequest.'],
			]);
			return false;
		}

		return true;
	}

	/**
	 * Validate MCP-Protocol-Version header.
	 *
	 * @return bool True if valid or absent, false if error was sent
	 */
	private function validateProtocolVersion(\EnchiladaREST\EnchiladaRequest $req, \EnchiladaREST\EnchiladaResponse $res): bool
	{
		$version = $req->getHeader('mcp-protocol-version');
		if ($version === null) {
			return true;
		}

		// Modern-era header on a legacy-shaped request body: the header
		// value MUST match the _meta field, so this is a HeaderMismatch.
		if (in_array($version, $this->modernVersions, true)) {
			$res->setStatus(400);
			$res->json([
				'jsonrpc' => '2.0',
				'error' => ['code' => RequestEra::ERR_HEADER_MISMATCH, 'message' => 'Header mismatch: MCP-Protocol-Version declares ' . $version . ' but the request body carries no matching _meta protocolVersion'],
			]);
			return false;
		}

		// Legacy-era acceptance list supplied by the application (with
		// 2024-11-05 grandfathered: transport-only tolerance kept).
		$legacy = array_merge($this->legacyVersions, ['2024-11-05']);
		if (!in_array($version, $legacy, true)) {
			$res->setStatus(400);
			$res->json([
				'jsonrpc' => '2.0',
				'error' => ['code' => -32600, 'message' => 'Unsupported MCP-Protocol-Version'],
			]);
			return false;
		}

		return true;
	}

	/**
	 * Create a new session and return the session ID.
	 */
	private function createSession(): string
	{
		$sessionId = bin2hex(random_bytes(32));
		if (!is_dir($this->sessionDir)) {
			@mkdir($this->sessionDir, 0700, true);
		}
		file_put_contents($this->sessionDir . '/' . $sessionId, time());
		return $sessionId;
	}

	/**
	 * Send MCP response respecting Accept header (JSON vs SSE).
	 */
	private function sendMcpResponse(\EnchiladaREST\EnchiladaRequest $req, \EnchiladaREST\EnchiladaResponse $res, array $response): void
	{
		$accept = $req->getHeader('accept', '');
		$json = json_encode($response, JSON_UNESCAPED_SLASHES);

		if (str_contains($accept, 'text/event-stream')) {
			$res->setHeader('Content-Type', 'text/event-stream');
			$res->setHeader('Cache-Control', 'no-cache');
			$res->setHeader('X-Accel-Buffering', 'no');
			$body = "event: message\ndata: {$json}\n\n";
			$res->setBody($body);
		} else {
			$res->setHeader('Content-Type', 'application/json');
			$res->setBody($json);
		}

		$res->send();
	}

	private function log(string $message): void
	{
		if ($this->logger) {
			($this->logger)("[McpTransport] " . $message);
		}
	}
}
