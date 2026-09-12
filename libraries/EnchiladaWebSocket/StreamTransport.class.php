<?php
/**
 * Enchilada WebSocket — Stream Transport
 *
 * Non-blocking transport using PHP stream_socket_client with an
 * internal read buffer. drain() pulls available bytes from the stream;
 * consume() pulls from the buffer. Neither ever blocks.
 *
 * The connect() method is the only blocking call (one-time setup).
 * After connect, the stream is set to non-blocking mode permanently.
 *
 * TLS context options may be supplied via the constructor or
 * setContextOptions() before connect(); they are merged into the 'ssl'
 * section of the stream context (e.g. verify_peer, capture_peer_cert,
 * peer_name). When capture_peer_cert is enabled, getPeerCertificate()
 * returns the peer's certificate after connect().
 *
 * @package    EnchiladaWebSocket
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

namespace EnchiladaWebSocket;

class StreamTransport implements WebSocketTransportInterface
{
	/** @var resource|null */
	private $stream = null;

	/** @var string Internal read buffer */
	private string $buffer = '';

	/** @var array Options merged into the 'ssl' stream context section */
	private array $contextOptions;

	/**
	 * @param array $contextOptions 'ssl' stream context options, e.g.
	 *        ['verify_peer' => false, 'capture_peer_cert' => true]
	 */
	public function __construct(array $contextOptions = [])
	{
		$this->contextOptions = $contextOptions;
	}

	/**
	 * Replace the 'ssl' context options. Must be called before connect().
	 */
	public function setContextOptions(array $contextOptions): void
	{
		$this->contextOptions = $contextOptions;
	}

	/**
	 * The peer's TLS certificate as an OpenSSL X509 resource, captured
	 * when 'capture_peer_cert' was enabled on the context. Null when not
	 * connected or not captured.
	 *
	 * @return \OpenSSLCertificate|resource|null
	 */
	public function getPeerCertificate(): mixed
	{
		if ($this->stream === null) {
			return null;
		}
		$params = stream_context_get_params($this->stream);
		return $params['options']['ssl']['peer_certificate'] ?? null;
	}

	public function connect(string $host, int $port, bool $tls = false, float $timeout = 5.0): void
	{
		$scheme = $tls ? 'tls' : 'tcp';
		$address = "{$scheme}://{$host}:{$port}";

		$context = stream_context_create(['ssl' => $this->contextOptions]);
		$errno = 0;
		$errstr = '';

		$this->stream = @stream_socket_client(
			$address,
			$errno,
			$errstr,
			$timeout,
			STREAM_CLIENT_CONNECT,
			$context
		);

		if ($this->stream === false) {
			$this->stream = null;
			throw new \RuntimeException("WebSocket connection failed to {$address}: [{$errno}] {$errstr}");
		}

		// Non-blocking from this point forward
		stream_set_blocking($this->stream, false);
	}

	public function drain(): int
	{
		if ($this->stream === null) {
			throw new \RuntimeException('WebSocket transport not connected');
		}

		$total = 0;

		while (true) {
			$chunk = @fread($this->stream, 65536);

			if ($chunk === false) {
				if (feof($this->stream)) {
					@fclose($this->stream);
					$this->stream = null;
					throw new \RuntimeException('WebSocket connection closed by peer');
				}
				// fread can also return false when the stream would
				// block: on TLS sockets a stream_set_timeout() expiry is
				// reported this way, and non-blocking SSL reads return
				// false rather than '' when no plaintext is available.
				// Neither is an error — the caller retries via its own
				// deadline.
				$meta = stream_get_meta_data($this->stream);
				if (($meta['timed_out'] ?? false) || !($meta['blocked'] ?? true)) {
					break;
				}
				@fclose($this->stream);
				$this->stream = null;
				throw new \RuntimeException('WebSocket read error');
			}

			if ($chunk === '') {
				// Distinguish "no data right now" from peer close (EOF).
				// At EOF the fd stays permanently readable (level-triggered),
				// so leaving the stream open would spin the reactor forever.
				if (feof($this->stream)) {
					@fclose($this->stream);
					$this->stream = null;
					throw new \RuntimeException('WebSocket connection closed by peer');
				}
				break; // No more data available (non-blocking)
			}

			$this->buffer .= $chunk;
			$total += strlen($chunk);
		}

		return $total;
	}

	public function consume(int $length): string
	{
		if ($this->buffer === '') {
			return '';
		}

		if (strlen($this->buffer) <= $length) {
			$data = $this->buffer;
			$this->buffer = '';
			return $data;
		}

		$data = substr($this->buffer, 0, $length);
		$this->buffer = substr($this->buffer, $length);
		return $data;
	}

	public function buffered(): int
	{
		return strlen($this->buffer);
	}

	/**
	 * Prepend data to the front of the read buffer.
	 * Used to put back unconsumed bytes after partial frame parsing.
	 */
	public function prepend(string $data): void
	{
		$this->buffer = $data . $this->buffer;
	}

	public function write(string $data): int
	{
		if ($this->stream === null) {
			throw new \RuntimeException('WebSocket transport not connected');
		}

		$total = strlen($data);
		$written = 0;

		while ($written < $total) {
			$bytes = @fwrite($this->stream, substr($data, $written));

			if ($bytes === false || $bytes === 0) {
				@fclose($this->stream);
				$this->stream = null;
				throw new \RuntimeException('WebSocket write error');
			}

			$written += $bytes;
		}

		return $written;
	}

	public function getStream(): mixed
	{
		return $this->stream;
	}

	public function isConnected(): bool
	{
		if ($this->stream === null) {
			return false;
		}

		return !feof($this->stream);
	}

	public function close(): void
	{
		if ($this->stream !== null) {
			@fclose($this->stream);
			$this->stream = null;
		}
		$this->buffer = '';
	}
}
