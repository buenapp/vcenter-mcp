<?php

namespace Enchilada\Tortilla;

/* Enchilada Framework 3.0
 * Tortilla Request Era Helpers
 *
 * Pure helpers for the 2026-07-28 dual-era Streamable HTTP rules that
 * the HTTP transports enforce: era detection from the request body's
 * `_meta['io.modelcontextprotocol/protocolVersion']`, and the
 * `=?base64?...?=` Value Encoding sentinel used by the Mcp-Name header.
 *
 * The same two pure functions exist on EnchiladaMCP\McpServer, which is
 * the protocol core's authority; the copies here let this library stay
 * independently vendorable with no reference into MCP/.
 *
 * Software License Agreement (BSD License)
 *
 * Copyright (c) 2026, The Daniel Morante Company, Inc.
 * All rights reserved.
 */

final class RequestEra
{
	/**
	 * Header/body mismatch on modern Streamable HTTP requests (emitted
	 * by the HTTP transports). Same value as
	 * EnchiladaMCP\McpServer::ERR_HEADER_MISMATCH.
	 */
	public const ERR_HEADER_MISMATCH = -32020;

	private function __construct() {}

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
	 * True when the request declares one of the given modern-era
	 * revisions. Era is decided per request, not per connection: a
	 * dual-era client may mix shaped requests on one transport while
	 * probing.
	 *
	 * @param array<string,mixed> $request        Decoded JSON-RPC request object
	 * @param string[]            $modernVersions Accepted modern revisions
	 */
	public static function isModern(array $request, array $modernVersions): bool
	{
		return in_array(self::declaredVersionOf($request), $modernVersions, true);
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
}
