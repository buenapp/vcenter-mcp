<?php

namespace EnchiladaMCP;

/* Enchilada Framework 3.0
 * MCP Protocol Server
 *
 * JSON-RPC 2.0 protocol handler for the Model Context Protocol.
 * Handles initialize, tools/list, tools/call, and ping methods.
 * Transport-agnostic: receives decoded requests, returns response arrays.
 *
 * Software License Agreement (BSD License)
 * 
 * Copyright (c) 2026, The Daniel Morante Company, Inc.
 * All rights reserved.
 */

class McpServer
{
	/** @var ToolRegistry */
	private ToolRegistry $registry;

	/** @var array{name:string,version:string} */
	private array $serverInfo;

	/** @var string Human-readable display name (2025-11-25 Implementation.title); empty = omit. */
	private string $serverTitle = '';

	/** @var string Human-readable description (2025-11-25 Implementation.description); empty = omit. */
	private string $serverDescription = '';

	/** @var string Website URL (2025-11-25 Implementation.websiteUrl); empty = omit. */
	private string $serverWebsiteUrl = '';

	/**
	 * @var array<array{src:string,mimeType?:string,sizes?:string[],theme?:string}>
	 *       Sized icons for client UIs (2025-11-25 Implementation.icons); empty = omit.
	 */
	private array $serverIcons = [];

	/** MCP revision that switches the server to modern (stateless) era. */
	public const MODERN_PROTOCOL_VERSION = '2026-07-28';

	/** Newest legacy (handshake-based) MCP revision supported. */
	public const LEGACY_PROTOCOL_VERSION = '2025-11-25';

	/**
	 * Latest MCP protocol revision this library implements. Exposed so
	 * downstream code can report or default to it without restating the
	 * literal and drifting from the server. Points at the modern revision;
	 * the legacy `initialize` handshake negotiates from
	 * {@see legacyVersions()} and never answers with the modern literal.
	 */
	public const DEFAULT_PROTOCOL_VERSION = self::MODERN_PROTOCOL_VERSION;

	/** Unsupported protocol version (data.supported, data.requested). */
	public const ERR_UNSUPPORTED_PROTOCOL_VERSION = -32022;

	/**
	 * Header/body mismatch on modern Streamable HTTP requests
	 * (emitted by the HTTP transports, not this class).
	 */
	public const ERR_HEADER_MISMATCH = -32020;

	/**
	 * @var string[] Every MCP protocol version this server can speak, newest
	 *               first, both eras. Used for `server/discover` and for the
	 *               UnsupportedProtocolVersion data.supported list.
	 */
	private array $supportedProtocolVersions = [self::MODERN_PROTOCOL_VERSION, self::LEGACY_PROTOCOL_VERSION, '2025-06-18', '2025-03-26'];

	/** @var string[] Newest-first handshake-era revisions `initialize` may negotiate. */
	private array $legacyProtocolVersions = [self::LEGACY_PROTOCOL_VERSION, '2025-06-18', '2025-03-26'];

	/** @var string[] Per-request `_meta` revisions (no handshake, no sessions). */
	private array $modernProtocolVersions = [self::MODERN_PROTOCOL_VERSION];

	/** @var string Version agreed during the last `initialize`. */
	private string $negotiatedProtocolVersion = self::LEGACY_PROTOCOL_VERSION;

	/** @var string[] Result methods that carry ttlMs/cacheScope on modern requests. */
	private const CACHEABLE_LIST_METHODS = ['server/discover', 'tools/list', 'resources/list', 'resources/templates/list', 'resources/read'];

	/** @var int ttlMs hint for list/discover results on modern requests (1 hour; the tool and resource sets change only with configuration). */
	private int $listCacheTtlMs = 3600000;

	/** @var int ttlMs hint for resources/read results on modern requests (0 = immediately stale; memory is live data). */
	private int $readCacheTtlMs = 0;

	/** @var string cacheScope hint on modern cacheable results ("private" — these deployments are single-tenant). */
	private string $cacheScope = 'private';

	/** @var string Server instructions for AI agents (included in initialize response). */
	private string $instructions = '';

	/** @var bool Whether the server has been initialized at least once. */
	private bool $initialized = false;

	/** @var callable|null Callback invoked when a re-initialize is received (cleanup prior state). */
	private $onReinitialize = null;

	/** @var callable|null Optional logger: function(string $message): void */
	private $logger = null;

	/**
	 * Notification writer: function(string $method, array $params): void.
	 *
	 * The transport wires its own write path here so the server can push
	 * progress notifications during a long call without knowing anything
	 * about the transport itself.
	 */
	private ?\Closure $notifier = null;

	/**
	 * @var string|int|float|null progressToken of the request currently being
	 *      dispatched (from params._meta.progressToken), null when idle.
	 */
	private string|int|float|null $activeProgressToken = null;

	/** @var float When the currently dispatched request started */
	private float $activeRequestStartedAt = 0.0;

	/** @var float Last time a progress notification was emitted for the active request */
	private float $lastProgressAt = 0.0;

	/** @var int Minimum seconds between progress notifications */
	private int $progressThrottleSeconds = 1;

	/**
	 * Create a new MCP server instance.
	 *
	 * @param string $name    Server name for client identification
	 * @param string $version Server version string
	 */
	public function __construct(string $name = 'mcp-server', string $version = '1.0.0')
	{
		$this->registry = new ToolRegistry();
		$this->serverInfo = [
			'name' => $name,
			'version' => $version,
		];
	}

	/**
	 * Wire the notification writer — a callable that pushes a JSON-RPC
	 * notification to the client.
	 *
	 * The application wires the transport's write path here (e.g.
	 * Enchilada\Tortilla\StdioTransport's sendNotification). The
	 * server never knows what kind of transport it is talking through.
	 *
	 * @param callable $notifier function(string $method, array $params): void
	 */
	public function setNotifier(callable $notifier): void
	{
		$this->notifier = $notifier(...);
	}

	/**
	 * Emit a progress notification for the in-flight request, throttled
	 * to one per progressThrottleSeconds.
	 *
	 * Called by the transport's progress timer (reactor mode) and by
	 * the Enchilada\Tortilla\HttpClient blocking-mode poll loop.
	 * Hosts that sent a progressToken reset their request timeout on
	 * each notification, keeping slow calls alive; under protocol
	 * revision 2026-07-28 (which removed `ping`) this is the only
	 * liveness signal available during a call.
	 *
	 * Servicing inbound traffic is the transport's own concern (its
	 * stdin watcher stays armed while a call is suspended).
	 *
	 * Never throws; safe to call from any point in tool code.
	 */
	public function tick(): void
	{
		if ($this->notifier === null) {
			return;
		}

		if ($this->activeProgressToken === null) {
			return;
		}
		$now = microtime(true);
		if (($now - $this->lastProgressAt) < $this->progressThrottleSeconds) {
			return;
		}
		$this->lastProgressAt = $now;
		$elapsed = round($now - $this->activeRequestStartedAt, 1);
		try {
			($this->notifier)('notifications/progress', [
				'progressToken' => $this->activeProgressToken,
				'progress' => $elapsed,
				'message' => "in progress ({$elapsed}s elapsed)",
			]);
		} catch (\Throwable $e) {
			// Best-effort; a failed progress write must not fail the call.
		}
	}

	/**
	 * Get the protocol version agreed during the last `initialize`.
	 *
	 * Useful for tools that want to withhold a newer-revision field from a
	 * client that negotiated an older one. Note that withholding is not
	 * required for additive result fields: MCP clients must ignore members
	 * they do not recognise.
	 */
	public function negotiatedProtocolVersion(): string
	{
		return $this->negotiatedProtocolVersion;
	}

	/**
	 * All protocol versions this server can speak, newest first, both eras.
	 * Transports must advertise and validate from this list rather than
	 * hardcoding their own (the 2025-11-25 rollout required touching both).
	 *
	 * @return string[]
	 */
	public function supportedVersions(): array
	{
		return $this->supportedProtocolVersions;
	}

	/**
	 * Handshake-era (initialize-based) versions supported, newest first.
	 *
	 * @return string[]
	 */
	public function legacyVersions(): array
	{
		return $this->legacyProtocolVersions;
	}

	/**
	 * Modern (per-request metadata, stateless) versions supported.
	 *
	 * @return string[]
	 */
	public function modernVersions(): array
	{
		return $this->modernProtocolVersions;
	}

	/**
	 * The protocol version a raw decoded JSON-RPC request declares in
	 * `params._meta['io.modelcontextprotocol/protocolVersion']`, or null
	 * when absent (legacy-shaped request).
	 *
	 * @param  array<string,mixed> $request Decoded JSON-RPC request object
	 */
	public static function declaredVersionOf(array $request): ?string
	{
		$v = $request['params']['_meta']['io.modelcontextprotocol/protocolVersion'] ?? null;
		return is_string($v) ? $v : null;
	}

	/**
	 * Decode a modern Streamable HTTP header value that may carry the
	 * `=?base64?...?=` sentinel encoding (2026-07-28 Value Encoding).
	 *
	 * A plain value must be header-safe per RFC 9110 (visible ASCII, space,
	 * tab — and no leading/trailing whitespace, which clients are required
	 * to Base64-encode instead). Returns [valid, decodedValue].
	 *
	 * @param  string|null $raw Raw header value
	 * @return array{0:bool,1:?string}
	 */
	public static function decodeSentinelHeaderValue(?string $raw): array
	{
		if ($raw === null) {
			return [false, null];
		}
		if (str_starts_with($raw, '=?base64?') && str_ends_with($raw, '?=')) {
			$decoded = base64_decode(substr($raw, 9, -2), true);
			return $decoded === false ? [false, null] : [true, $decoded];
		}
		if ($raw !== trim($raw) || preg_match('/[^\t\x20-\x7E]/', $raw)) {
			return [false, null];
		}
		return [true, $raw];
	}

	/**
	 * True when the request carries a modern-era `_meta` protocol version.
	 * Era is decided per request, not per connection: a dual-era client may
	 * mix shaped requests on one transport while probing.
	 *
	 * @param  array<string,mixed> $request Decoded JSON-RPC request object
	 */
	public function isModernRequest(array $request): bool
	{
		return in_array(self::declaredVersionOf($request), $this->modernProtocolVersions, true);
	}

	/**
	 * Override cache hints on modern cacheable results.
	 *
	 * @param  int    $listTtlMs ttlMs for server/discover, tools/list,
	 *                           resources/list, resources/templates/list
	 * @param  int    $readTtlMs ttlMs for resources/read
	 * @param  string $scope     "private" (default) or "public"
	 * @throws \InvalidArgumentException on negative TTL or unknown scope
	 */
	public function setCachePolicy(int $listTtlMs, int $readTtlMs, string $scope = 'private'): self
	{
		if ($listTtlMs < 0 || $readTtlMs < 0) {
			throw new \InvalidArgumentException('ttlMs must be >= 0');
		}
		if (!in_array($scope, ['public', 'private'], true)) {
			throw new \InvalidArgumentException("cacheScope must be 'public' or 'private'");
		}
		$this->listCacheTtlMs = $listTtlMs;
		$this->readCacheTtlMs = $readTtlMs;
		$this->cacheScope = $scope;
		return $this;
	}

	/**
	 * Set server instructions for AI agents.
	 *
	 * The instructions string is included in the initialize response
	 * to help AI agents understand the server's purpose and usage.
	 *
	 * @param  string $instructions Instructions text
	 * @return self                 Fluent interface
	 */
	public function setInstructions(string $instructions): self
	{
		$this->instructions = $instructions;
		return $this;
	}

	/**
	 * Set the human-readable display name (Implementation.title, 2025-11-25).
	 */
	public function setTitle(string $title): self
	{
		$this->serverTitle = $title;
		return $this;
	}

	/**
	 * Set the human-readable description (Implementation.description, 2025-11-25).
	 */
	public function setDescription(string $description): self
	{
		$this->serverDescription = $description;
		return $this;
	}

	/**
	 * Set the implementation website URL (Implementation.websiteUrl, 2025-11-25).
	 */
	public function setWebsiteUrl(string $url): self
	{
		$this->serverWebsiteUrl = $url;
		return $this;
	}

	/**
	 * Set sized icons for client UIs (Implementation.icons, 2025-11-25).
	 *
	 * Each entry: ['src' => (HTTPS URL or data: URI, required),
	 *              'mimeType' => ?string, 'sizes' => ?string[] ('WxH' or
	 *              'any'), 'theme' => ?'light'|'dark'].
	 * Clients strictly older than 2025-11-25 ignore unknown serverInfo
	 * members, so icons can be sent unconditionally.
	 *
	 * @param  array<array<string,mixed>> $icons
	 * @throws \InvalidArgumentException  If an icon is missing src or has an
	 *                                    invalid theme
	 */
	public function setIcons(array $icons): self
	{
		foreach ($icons as $i => $icon) {
			if (!is_array($icon) || !is_string($icon['src'] ?? null) || $icon['src'] === '') {
				throw new \InvalidArgumentException("Icon #{$i} requires a non-empty string 'src'");
			}
			if (isset($icon['theme']) && !in_array($icon['theme'], ['light', 'dark'], true)) {
				throw new \InvalidArgumentException("Icon #{$i} theme must be 'light' or 'dark'");
			}
		}
		$this->serverIcons = array_values($icons);
		return $this;
	}

	/**
	 * The serverInfo payload sent in initialize, including any configured
	 * 2025-11-25 Implementation metadata.
	 *
	 * @return array<string,mixed>
	 */
	private function implementationInfo(): array
	{
		$info = $this->serverInfo;
		if ($this->serverTitle !== '') {
			$info['title'] = $this->serverTitle;
		}
		if ($this->serverDescription !== '') {
			$info['description'] = $this->serverDescription;
		}
		if ($this->serverWebsiteUrl !== '') {
			$info['websiteUrl'] = $this->serverWebsiteUrl;
		}
		if (!empty($this->serverIcons)) {
			$info['icons'] = $this->serverIcons;
		}
		return $info;
	}

	/**
	 * Set a callback invoked when the client sends a second initialize request.
	 *
	 * Stdio MCP servers are single-connection, but some IDE hosts will send
	 * a fresh initialize on the same pipe to "restart" the logical session.
	 * The callback should clean up any stateful resources (browser sessions,
	 * open connections, etc.) so the server can start fresh without a process
	 * restart.
	 *
	 * @param  callable $callback Invoked with no arguments before re-init response
	 * @return self               Fluent interface
	 */
	public function onReinitialize(callable $callback): self
	{
		$this->onReinitialize = $callback;
		return $this;
	}

	/**
	 * Set a logging callback for protocol-level diagnostics.
	 *
	 * Receives one line per request describing the method, tool name,
	 * argument digests, duration, and outcome. Argument VALUES are never
	 * logged — only lengths and SHA-256 digests — so secrets passed as
	 * tool arguments cannot leak into log files.
	 *
	 * @param  callable $logger Function accepting a string message
	 * @return self             Fluent interface
	 */
	public function setLogger(callable $logger): self
	{
		$this->logger = $logger;
		return $this;
	}

	/**
	 * Register an object's tools with the server.
	 *
	 * @param  object $handler Object containing McpTool-annotated methods
	 * @return self            Fluent interface
	 */
	public function register(object $handler): self
	{
		$this->registry->register($handler);
		return $this;
	}

	/**
	 * Handle a JSON-RPC request and return a response.
	 *
	 * @param  array<string,mixed> $request JSON-RPC request object
	 * @return array<string,mixed>          JSON-RPC response (empty array for notifications)
	 */
	public function handleRequest(array $request): array
	{
		$id = $request['id'] ?? null;
		$method = $request['method'] ?? '';
		$params = $request['params'] ?? [];

		// Track the progressToken of the dispatched request so the
		// transport's progress timer (and Tortilla HttpClient's
		// blocking-mode poll) can keep the host's timeout reset while it
		// runs. Liveness traffic is exempt: it is answered out of band
		// while a call is still in flight and must not clobber that
		// call's progress state.
		$trackProgress = !in_array($method, ['ping', 'notifications/cancelled'], true);
		if ($trackProgress) {
			$token = $params['_meta']['progressToken'] ?? null;
			$this->activeProgressToken = (is_string($token) || is_int($token) || is_float($token)) ? $token : null;
			$this->activeRequestStartedAt = microtime(true);
			$this->lastProgressAt = 0.0;
		}

		$started = microtime(true);
		if ($method === 'tools/call') {
			$toolName = $params['name'] ?? '';
			$this->log("Request tools/call '{$toolName}' (id=" . json_encode($id) . ') ' . $this->summarizeArguments($params['arguments'] ?? []));
		} else {
			$this->log("Request {$method} (id=" . json_encode($id) . ')');
		}

		// Era decision (2026-07-28, dual-era): a request is modern when its
		// params._meta carries a protocolVersion we implement as modern. A
		// declared version we do not implement at all is rejected with
		// UnsupportedProtocolVersionError so dual-era clients can pick from
		// data.supported and retry instead of guessing legacy.
		$declared = self::declaredVersionOf($request);
		$modern = in_array($declared, $this->modernProtocolVersions, true);
		if ($declared !== null && !in_array($declared, $this->supportedProtocolVersions, true)) {
			$this->log("Error {$method} (id=" . json_encode($id) . '): unsupported protocol version ' . $declared);
			return $this->errorResponse($id, self::ERR_UNSUPPORTED_PROTOCOL_VERSION, 'Unsupported protocol version', [
				'supported' => $this->supportedProtocolVersions,
				'requested' => $declared,
			]);
		}

		try {
			if ($method === 'ping' && $modern) {
				// Ping was removed from the modern revision; returning a
				// normal result is what the client uses to infer era.
				throw new \Exception('Method not found: ping (removed in protocol revision 2026-07-28)', -32601);
			}

			$result = match($method) {
				'initialize' => $this->handleInitialize($params),
				'server/discover' => $this->handleServerDiscover(),
				'notifications/initialized' => null,
				'tools/list' => $this->handleToolsList($params),
				'tools/call' => $this->handleToolsCall($params),
				'resources/list' => $this->handleResourcesList($params),
				'resources/templates/list' => $this->handleResourceTemplatesList($params),
				'resources/read' => $this->handleResourcesRead($params),
				'ping' => new \stdClass(),
				default => throw new \Exception("Method not found: {$method}", -32601),
			};

			// Notifications don't get responses
			if ($result === null) {
				return [];
			}

			// Modern-era result decoration: resultType on every result,
			// _meta serverInfo, and caching hints on the cacheable set.
			// handleServerDiscover() decorates itself because it must answer
			// in modern shape even for legacy-shaped probes.
			if ($modern && $method !== 'server/discover' && $method !== 'initialize') {
				$result = $this->modernizeResult($result, $method);
			}

			$elapsed = round((microtime(true) - $started) * 1000, 1);
			if (is_array($result) && !empty($result['isError'])) {
				$errorText = $result['content'][0]['text'] ?? '(no detail)';
				$this->log("Error {$method}" . ($method === 'tools/call' ? " '{$toolName}'" : '') . " (id=" . json_encode($id) . ") tool reported failure after {$elapsed}ms: " . Logger::truncate($errorText));
			} else {
				$this->log("OK {$method}" . ($method === 'tools/call' ? " '{$toolName}'" : '') . " (id=" . json_encode($id) . ") {$elapsed}ms");
			}

			return $this->successResponse($id, $result);

		} catch (\Throwable $e) {
			$elapsed = round((microtime(true) - $started) * 1000, 1);
			$this->log("Error {$method}" . ($method === 'tools/call' ? " '{$toolName}'" : '') . " (id=" . json_encode($id) . ") {$elapsed}ms: {$e->getMessage()}");
			return $this->errorResponse($id, (int)($e->getCode()) ?: -32603, $e->getMessage());
		} finally {
			if ($trackProgress) {
				$this->activeProgressToken = null;
			}
		}
	}

	/**
	 * Build a secret-safe summary of tool call arguments for logging.
	 *
	 * Scalars (int, float, bool, null) are logged as-is; strings are
	 * reduced to length + SHA-256 digest so values (which may be tokens
	 * or secrets) never appear in logs; arrays are summarized by their
	 * JSON encoding length + digest.
	 *
	 * @param  array<string,mixed> $arguments Tool call arguments
	 * @return string                         e.g. "args: owner=5 page=1 data(len=10308 sha256=9f2c…)"
	 */
	private function summarizeArguments(array $arguments): string
	{
		if (empty($arguments)) {
			return 'args: (none)';
		}

		$parts = [];
		foreach ($arguments as $key => $value) {
			if (is_string($value)) {
				$parts[] = $key . '(' . Logger::digest($value) . ')';
			} elseif (is_scalar($value) || $value === null) {
				$parts[] = $key . '=' . json_encode($value);
			} else {
				$json = json_encode($value);
				$parts[] = $key . '(' . Logger::digest($json === false ? '' : $json) . ')';
			}
		}
		return 'args: ' . implode(' ', $parts);
	}

	/**
	 * Log a message via the configured logger.
	 *
	 * @param string $message
	 */
	private function log(string $message): void
	{
		if ($this->logger !== null) {
			try {
				($this->logger)($message);
			} catch (\Throwable $e) {
				// Logging must never break protocol handling
			}
		}
	}

	/**
	 * Handle initialize request.
	 *
	 * @param  array<string,mixed> $params Request parameters
	 * @return array<string,mixed>         Initialize response
	 */
	private function handleInitialize(array $params): array
	{
		// If already initialized, invoke the cleanup callback so callers can
		// reset stateful resources (browser sessions, etc.) before the client
		// treats this as a fresh connection.
		if ($this->initialized && $this->onReinitialize !== null) {
			try {
				($this->onReinitialize)();
			} catch (\Throwable $e) {
				// Non-fatal — best-effort cleanup. Route through the logger:
				// a raw blocking STDERR write can stall the whole transport
				// when the host does not drain the pipe.
				$this->log("Re-initialize cleanup error: {$e->getMessage()}");
			}
		}

		$this->initialized = true;

		// Version negotiation (legacy handshake only): echo the client's
		// version when it is a handshake-era revision we support, else answer
		// our newest legacy revision and let the client decide whether to
		// continue. The modern literal is never returned here — modern
		// clients do not initialize, and agreeing to a modern version inside
		// a handshake would let a client run modern semantics without the
		// per-request metadata the modern path validates on.
		$requested = $params['protocolVersion'] ?? null;
		$this->negotiatedProtocolVersion =
			(is_string($requested) && in_array($requested, $this->legacyProtocolVersions, true))
				? $requested
				: self::LEGACY_PROTOCOL_VERSION;

		$result = [
			'protocolVersion' => $this->negotiatedProtocolVersion,
			'capabilities' => $this->capabilityMap(),
			'serverInfo' => $this->implementationInfo(),
		];

		if (!empty($this->instructions)) {
			$result['instructions'] = $this->instructions;
		}

		return $result;
	}

	/**
	 * The capabilities map shared by `initialize` and `server/discover`.
	 *
	 * @return array<string,mixed>
	 */
	private function capabilityMap(): array
	{
		$capabilities = [
			'tools' => new \stdClass(),
			'logging' => new \stdClass(),
		];
		if ($this->registry->hasResources()) {
			$capabilities['resources'] = new \stdClass();
		}
		return $capabilities;
	}

	/**
	 * Handle a server/discover request (2026-07-28; servers MUST implement
	 * it). Answers in modern shape unconditionally — dual-era clients on
	 * stdio send this as their era probe, before any negotiation, so the
	 * result must not depend on the request having modern `_meta`.
	 *
	 * @return array<string,mixed>
	 */
	private function handleServerDiscover(): array
	{
		$result = [
			'supportedVersions' => $this->supportedProtocolVersions,
			'capabilities' => $this->capabilityMap(),
		];
		if (!empty($this->instructions)) {
			$result['instructions'] = $this->instructions;
		}
		return $this->modernizeResult($result, 'server/discover');
	}

	/**
	 * Decorate a result with the fields 2026-07-28 requires: `resultType`
	 * on every result, `_meta` server identification, and ttlMs/cacheScope
	 * hints on cacheable list and read results.
	 *
	 * @param  array<string,mixed> $result Undecorated method result
	 * @return array<string,mixed>
	 */
	private function modernizeResult(array $result, string $method): array
	{
		$result = ['resultType' => 'complete'] + $result;
		if (in_array($method, self::CACHEABLE_LIST_METHODS, true)) {
			$result['ttlMs'] = $method === 'resources/read' ? $this->readCacheTtlMs : $this->listCacheTtlMs;
			$result['cacheScope'] = $this->cacheScope;
		}
		$result['_meta'] = ['io.modelcontextprotocol/serverInfo' => $this->implementationInfo()];
		return $result;
	}

	/**
	 * Handle tools/list request.
	 *
	 * @param  array<string,mixed> $params Request parameters
	 * @return array<string,mixed>         Tools list response
	 */
	private function handleToolsList(array $params): array
	{
		return [
			'tools' => $this->registry->listTools(),
		];
	}

	/**
	 * Handle tools/call request.
	 *
	 * Unknown tool names are returned as tool-level error results (with
	 * name suggestions), not protocol-level errors.
	 *
	 * @param  array<string,mixed> $params Request parameters
	 * @return array<string,mixed>         Tool call response
	 */
	private function handleToolsCall(array $params): array
	{
		$name = $params['name'] ?? '';
		$arguments = $params['arguments'] ?? [];

		if (!$this->registry->hasTool($name)) {
			// Return as a tool-level error result rather than a protocol-level
			// -32602: several MCP clients treat protocol errors as connection
			// failures (tearing down and restarting the server), while an
			// isError result is shown to the agent so it can self-correct.
			$suggestions = implode(', ', $this->registry->suggestTools($name));
			$this->log("Unknown tool '{$name}' (closest matches: {$suggestions})");
			return ToolResult::error("Unknown tool: '{$name}'. Closest matches: {$suggestions}")->toArray();
		}

		try {
			$result = $this->registry->callTool($name, $arguments);
		} catch (ToolWarningInterface $e) {
			// Uncertain-but-not-failed outcome (e.g. upstream timeout where
			// the server may still have completed the operation). Return as
			// a normal result so the agent can read the explanation and
			// verify state instead of treating the call as failed.
			$this->log("Warning tools/call '{$name}': " . Logger::truncate($e->getMessage()));
			return ToolResult::text($e->getMessage())->toArray();
		} catch (\Throwable $e) {
			return ToolResult::error($e->getMessage())->toArray();
		}

		// Typed return: tools that return ToolResult get pass-through
		if ($result instanceof ToolResult) {
			return $result->toArray();
		}

		// Backward compat: plain values auto-wrap as text
		$text = is_string($result) ? $result : json_encode($result, JSON_UNESCAPED_SLASHES);
		return ToolResult::text($text)->toArray();
	}

	/**
	 * Build a JSON-RPC success response.
	 *
	 * @param  mixed                       $id     Request ID
	 * @param  array<string,mixed>|object  $result Result data
	 * @return array<string,mixed>
	 */
	private function successResponse($id, array|object $result): array
	{
		return [
			'jsonrpc' => '2.0',
			'id' => $id,
			'result' => $result,
		];
	}

	/**
	 * Handle resources/list request.
	 *
	 * @param  array<string,mixed> $params Request parameters
	 * @return array<string,mixed>         Resources list response
	 */
	private function handleResourcesList(array $params): array
	{
		return [
			'resources' => $this->registry->listResources(),
		];
	}

	/**
	 * Handle resources/templates/list request.
	 *
	 * @param  array<string,mixed> $params Request parameters
	 * @return array<string,mixed>         Resource templates list response
	 */
	private function handleResourceTemplatesList(array $params): array
	{
		return [
			'resourceTemplates' => $this->registry->listResourceTemplates(),
		];
	}

	/**
	 * Handle resources/read request.
	 *
	 * @param  array<string,mixed> $params Request parameters (must include 'uri')
	 * @return array<string,mixed>         Resource read response
	 * @throws \Exception                  If URI not provided or no match
	 */
	private function handleResourcesRead(array $params): array
	{
		$uri = $params['uri'] ?? '';

		if (empty($uri)) {
			throw new \Exception("Missing required parameter: uri", -32602);
		}

		try {
			$content = $this->registry->readResource($uri);
		} catch (\Throwable $e) {
			throw new \Exception("Resource not found: {$e->getMessage()}", -32602);
		}

		return [
			'contents' => [$content],
		];
	}

	/**
	 * Build a JSON-RPC error response.
	 *
	 * @param  mixed                $id      Request ID
	 * @param  int                  $code    Error code
	 * @param  string               $message Error message
	 * @param  array<string,mixed>|null $data Structured error data (e.g. the
	 *                                       supported/requested fields of
	 *                                       UnsupportedProtocolVersionError)
	 * @return array<string,mixed>
	 */
	private function errorResponse($id, int $code, string $message, ?array $data = null): array
	{
		$error = ['code' => $code, 'message' => $message];
		if ($data !== null) {
			$error['data'] = $data;
		}
		return [
			'jsonrpc' => '2.0',
			'id' => $id,
			'error' => $error,
		];
	}
}
