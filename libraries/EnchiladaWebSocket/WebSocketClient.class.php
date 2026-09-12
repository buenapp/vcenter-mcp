<?php
/**
 * Enchilada WebSocket — Client
 *
 * Fully non-blocking WebSocket client. I/O-agnostic: never calls
 * stream_select(), never sleeps, never blocks. Register getStream()
 * with any reactor's onReadable and call poll() when data arrives.
 *
 * Usage:
 *   $ws = new WebSocketClient(new StreamTransport());
 *   $ws->connect('wss://example.com/ws');
 *   $ws->onMessage(function(string $msg) { ... });
 *   $reactor->onReadable($ws->getStream(), fn() => $ws->poll());
 *
 * @package    EnchiladaWebSocket
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

namespace EnchiladaWebSocket;

class WebSocketClient
{
	private WebSocketTransportInterface $transport;
	private bool $connected = false;

	/** @var string Accumulator for fragmented data frames */
	private string $fragmentBuffer = '';

	private ?\Closure $onMessage = null;
	private ?\Closure $onClose = null;
	private ?\Closure $onError = null;

	public function __construct(WebSocketTransportInterface $transport)
	{
		$this->transport = $transport;
	}

	/**
	 * Connect to a WebSocket server URL.
	 *
	 * This is the only blocking call — performs TCP/TLS connect and
	 * the HTTP upgrade handshake. After this returns, the transport
	 * is in non-blocking mode and ready for poll().
	 *
	 * @param string $url WebSocket URL (ws:// or wss://)
	 * @param array $headers Additional HTTP headers for the upgrade request
	 * @param float $timeout Connection timeout in seconds
	 * @param bool $strictAccept If false, Sec-WebSocket-Accept mismatch is non-fatal
	 * @throws \RuntimeException on connection or handshake failure
	 */
	public function connect(string $url, array $headers = [], float $timeout = 5.0, bool $strictAccept = true): void
	{
		$parsed = parse_url($url);

		if ($parsed === false || !isset($parsed['host'])) {
			throw new \RuntimeException("Invalid WebSocket URL: {$url}");
		}

		$scheme = $parsed['scheme'] ?? 'ws';
		$host = $parsed['host'];
		$tls = ($scheme === 'wss');
		$port = $parsed['port'] ?? ($tls ? 443 : 80);
		$path = ($parsed['path'] ?? '/') . (isset($parsed['query']) ? '?' . $parsed['query'] : '');

		// Establish TCP/TLS connection (blocking — sets non-blocking after)
		$this->transport->connect($host, $port, $tls, $timeout);

		// Perform WebSocket handshake (reads response from buffer)
		$hostHeader = $host . ($port !== ($tls ? 443 : 80) ? ":{$port}" : '');
		$handshake = new WebSocketHandshake($hostHeader, $path, $headers);

		$request = $handshake->buildRequest();
		$this->transport->write($request);

		// Read handshake response — brief blocking read is acceptable
		// during one-time setup. We drain into the transport buffer.
		$response = $this->readHandshakeResponse();
		$handshake->validateResponse($response, $strictAccept);

		$this->connected = true;
	}

	/**
	 * Send a text message.
	 */
	public function send(string $message): void
	{
		$this->ensureConnected();
		$this->transport->write(WebSocketFrame::text($message)->encode());
	}

	/**
	 * Send a binary message.
	 */
	public function sendBinary(string $data): void
	{
		$this->ensureConnected();
		$this->transport->write(WebSocketFrame::binary($data)->encode());
	}

	/**
	 * Send a close frame and close the connection.
	 */
	public function disconnect(int $code = 1000, string $reason = ''): void
	{
		if ($this->connected) {
			try {
				$this->transport->write(WebSocketFrame::close($code, $reason)->encode());
			} catch (\RuntimeException $e) {
				// Connection may already be broken
			}
		}

		// Always close the transport — even when the logical connection is
		// already down, the underlying stream may still be open (e.g. after
		// a poll error) and would otherwise keep a reactor watcher firing.
		$this->connected = false;
		$this->transport->close();
	}

	/**
	 * Send a ping frame.
	 */
	public function ping(string $payload = ''): void
	{
		$this->ensureConnected();
		$this->transport->write(WebSocketFrame::ping($payload)->encode());
	}

	/**
	 * Process any pending data on the WebSocket. Fully non-blocking.
	 *
	 * Call this when the reactor signals readability on getStream().
	 * Drains the transport, parses complete frames, dispatches callbacks.
	 * Returns immediately if no complete frames are available.
	 */
	public function poll(): void
	{
		if (!$this->connected) {
			return;
		}

		try {
			$this->transport->drain();
		} catch (\RuntimeException $e) {
			$this->connected = false;
			if ($this->onError) {
				($this->onError)($e);
			}
			return;
		}

		$this->processBuffer();
	}

	/**
	 * Get the stream resource for reactor registration.
	 *
	 * @return resource|null
	 */
	public function getStream(): mixed
	{
		return $this->transport->getStream();
	}

	/**
	 * Check if the WebSocket connection is active.
	 */
	public function isConnected(): bool
	{
		return $this->connected && $this->transport->isConnected();
	}

	public function onMessage(callable $callback): void
	{
		$this->onMessage = $callback;
	}

	public function onClose(callable $callback): void
	{
		$this->onClose = $callback;
	}

	public function onError(callable $callback): void
	{
		$this->onError = $callback;
	}

	/**
	 * Parse and dispatch all complete frames from the transport buffer.
	 */
	private function processBuffer(): void
	{
		while ($this->transport->buffered() >= 2) {
			// Peek at the buffer without consuming
			$buffer = $this->transport->consume($this->transport->buffered());

			$result = WebSocketFrame::tryDecode($buffer);

			if ($result === null) {
				// Incomplete frame — put everything back
				$this->transport->prepend($buffer);
				return;
			}

			[$frame, $consumed] = $result;

			// Put unconsumed bytes back
			if ($consumed < strlen($buffer)) {
				$this->transport->prepend(substr($buffer, $consumed));
			}

			// Handle the frame
			if ($frame->isControl()) {
				$this->handleControlFrame($frame);
				if ($frame->opcode === WebSocketFrame::OPCODE_CLOSE) {
					return;
				}
				continue;
			}

			// Data frame — accumulate for fragmented messages
			$this->fragmentBuffer .= $frame->payload;

			if ($frame->fin) {
				$message = $this->fragmentBuffer;
				$this->fragmentBuffer = '';

				if ($this->onMessage) {
					($this->onMessage)($message);
				}
			}
		}
	}

	private function handleControlFrame(WebSocketFrame $frame): void
	{
		switch ($frame->opcode) {
			case WebSocketFrame::OPCODE_PING:
				$this->transport->write(WebSocketFrame::pong($frame->payload)->encode());
				break;

			case WebSocketFrame::OPCODE_CLOSE:
				$this->connected = false;
				try {
					$this->transport->write(WebSocketFrame::close(1000)->encode());
				} catch (\RuntimeException $e) {
					// Already disconnected
				}
				$this->transport->close();
				if ($this->onClose) {
					($this->onClose)();
				}
				break;

			case WebSocketFrame::OPCODE_PONG:
				break;
		}
	}

	/**
	 * Read the HTTP upgrade response during handshake.
	 * Uses a brief blocking approach (acceptable during one-time connect).
	 */
	private function readHandshakeResponse(): string
	{
		$stream = $this->transport->getStream();
		$response = '';
		$maxSize = 8192;

		// Temporarily block for handshake
		stream_set_blocking($stream, true);
		stream_set_timeout($stream, 5);

		while (strlen($response) < $maxSize) {
			$byte = fread($stream, 1);
			if ($byte === false || $byte === '') {
				break;
			}
			$response .= $byte;
			if (str_ends_with($response, "\r\n\r\n")) {
				break;
			}
		}

		// Back to non-blocking
		stream_set_blocking($stream, false);

		if (!str_ends_with($response, "\r\n\r\n")) {
			throw new \RuntimeException('WebSocket handshake failed: incomplete response headers');
		}

		return $response;
	}

	private function ensureConnected(): void
	{
		if (!$this->connected) {
			throw new \RuntimeException('WebSocket is not connected');
		}
	}
}
