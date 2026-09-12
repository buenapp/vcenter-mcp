<?php
/**
 * Enchilada WebSocket — Transport Interface
 *
 * Non-blocking I/O contract for WebSocket communication. The transport
 * owns the connection and an internal read buffer. The WebSocket client
 * never blocks — it drains available bytes into the buffer and attempts
 * incremental frame parsing.
 *
 * Built-in: StreamTransport (PHP stream_socket_client)
 *
 * @package    EnchiladaWebSocket
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

namespace EnchiladaWebSocket;

interface WebSocketTransportInterface
{
	/**
	 * Establish a TCP or TLS connection to the given host and port.
	 * This is the only blocking operation — acceptable for one-time setup.
	 *
	 * After connect returns, the stream MUST be in non-blocking mode.
	 *
	 * @param string $host Hostname or IP address
	 * @param int $port Port number
	 * @param bool $tls Whether to use TLS (wss://)
	 * @param float $timeout Connection timeout in seconds
	 * @throws \RuntimeException on connection failure
	 */
	public function connect(string $host, int $port, bool $tls = false, float $timeout = 5.0): void;

	/**
	 * Read all available bytes from the stream into the internal buffer.
	 * Never blocks — returns immediately if nothing is available.
	 *
	 * Call this when the reactor signals readability on getStream().
	 *
	 * @return int Number of bytes read (0 if nothing available)
	 * @throws \RuntimeException on read error or closed connection
	 */
	public function drain(): int;

	/**
	 * Consume up to $length bytes from the internal read buffer.
	 * Does NOT touch the stream — only reads from previously drained data.
	 *
	 * @param int $length Maximum bytes to consume
	 * @return string Bytes from buffer (may be shorter than $length or empty)
	 */
	public function consume(int $length): string;

	/**
	 * Get the number of bytes currently in the read buffer.
	 */
	public function buffered(): int;

	/**
	 * Prepend data to the front of the read buffer.
	 * Used to put back unconsumed bytes after partial frame parsing.
	 */
	public function prepend(string $data): void;

	/**
	 * Write data to the connection.
	 *
	 * @param string $data Raw bytes to write
	 * @return int Number of bytes written
	 * @throws \RuntimeException on write error
	 */
	public function write(string $data): int;

	/**
	 * Get the underlying stream resource for reactor IO watcher registration.
	 * Returns null if not connected.
	 *
	 * @return resource|null The raw PHP stream resource
	 */
	public function getStream(): mixed;

	/**
	 * Check if the transport is currently connected.
	 */
	public function isConnected(): bool;

	/**
	 * Close the connection and release resources.
	 */
	public function close(): void;
}
