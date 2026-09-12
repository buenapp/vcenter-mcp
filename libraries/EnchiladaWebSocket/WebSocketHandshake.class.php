<?php
/**
 * Enchilada WebSocket — Handshake
 *
 * Generates the HTTP Upgrade request and validates the server's
 * upgrade response per RFC 6455 Section 4.
 * Pure protocol logic — no I/O.
 *
 * @package    EnchiladaWebSocket
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

namespace EnchiladaWebSocket;

class WebSocketHandshake
{
	private string $key;
	private string $host;
	private string $path;
	private array $headers;

	// RFC 6455 §1.3
	const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

	/**
	 * @param string $host Host header value (host:port)
	 * @param string $path Request URI path (e.g., /ws or /session/id/se/bidi)
	 * @param array $headers Additional headers to include in the upgrade request
	 */
	public function __construct(string $host, string $path = '/', array $headers = [])
	{
		$this->key = base64_encode(random_bytes(16));
		$this->host = $host;
		$this->path = $path;
		$this->headers = $headers;
	}

	/**
	 * Generate the HTTP Upgrade request string.
	 */
	public function buildRequest(): string
	{
		$request = "GET {$this->path} HTTP/1.1\r\n";
		$request .= "Host: {$this->host}\r\n";
		$request .= "Upgrade: websocket\r\n";
		$request .= "Connection: Upgrade\r\n";
		$request .= "Sec-WebSocket-Key: {$this->key}\r\n";
		$request .= "Sec-WebSocket-Version: 13\r\n";

		foreach ($this->headers as $name => $value) {
			$request .= "{$name}: {$value}\r\n";
		}

		$request .= "\r\n";

		return $request;
	}

	/**
	 * Validate the server's HTTP 101 Switching Protocols response.
	 *
	 * @param string $response Raw HTTP response headers
	 * @param bool $strictAccept If false, accept mismatch is non-fatal (for WebSocket proxies)
	 * @return bool True if handshake is valid
	 * @throws \RuntimeException if validation fails
	 */
	public function validateResponse(string $response, bool $strictAccept = true): bool
	{
		// Check for 101 status
		if (!preg_match('#^HTTP/1\.1 101#i', $response)) {
			$statusLine = strtok($response, "\r\n");
			throw new \RuntimeException("WebSocket handshake failed: expected HTTP 101, got: {$statusLine}");
		}

		// Check Upgrade header
		if (!preg_match('#Upgrade:\s*websocket#i', $response)) {
			throw new \RuntimeException('WebSocket handshake failed: missing Upgrade: websocket header');
		}

		// Validate Sec-WebSocket-Accept
		$expectedAccept = base64_encode(sha1($this->key . self::GUID, true));

		if (!preg_match('#Sec-WebSocket-Accept:\s*(.+)\r\n#i', $response, $m)) {
			throw new \RuntimeException('WebSocket handshake failed: missing Sec-WebSocket-Accept header');
		}

		$actualAccept = trim($m[1]);
		if ($actualAccept !== $expectedAccept) {
			if ($strictAccept) {
				throw new \RuntimeException("WebSocket handshake failed: Sec-WebSocket-Accept mismatch (expected {$expectedAccept}, got {$actualAccept})");
			}
		}

		return true;
	}

}
