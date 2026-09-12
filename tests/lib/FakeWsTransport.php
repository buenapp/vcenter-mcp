<?php

use EnchiladaWebSocket\WebSocketTransportInterface;

/**
 * Scripted WebSocket transport for offline WebMKS tests. Inbound bytes
 * are queued via feed() or returned from the onWrite callback (which
 * sees every client write — used to answer the HTTP upgrade with a
 * correctly keyed 101). drain() moves queued bytes into the read
 * buffer; getStream() returns null (no real socket).
 */
class FakeWsTransport implements WebSocketTransportInterface
{
	/** @var string[] Every client write, in order */
	public array $writes = [];

	/** @var array|null connect() arguments */
	public ?array $connectArgs = null;

	/** @var string Bytes the server will send next */
	private string $queue = '';

	/** @var string Drained bytes awaiting consume() */
	private string $buffer = '';

	/** @var bool */
	private bool $connected = false;

	/** @var resource|\OpenSSLCertificate|null */
	private $cert;

	/** @var callable|null fn(FakeWsTransport, string $data): void — may feed() replies */
	private $onWrite;

	public function __construct(?string $certPem = null, ?callable $onWrite = null)
	{
		$this->cert = $certPem !== null ? openssl_x509_read($certPem) : null;
		$this->onWrite = $onWrite;
	}

	/** Queue server->client bytes. */
	public function feed(string $bytes): void
	{
		$this->queue .= $bytes;
	}

	public function connect(string $host, int $port, bool $tls = false, float $timeout = 5.0): void
	{
		$this->connectArgs = [$host, $port, $tls, $timeout];
		$this->connected = true;
	}

	public function drain(): int
	{
		$n = strlen($this->queue);
		$this->buffer .= $this->queue;
		$this->queue = '';
		return $n;
	}

	public function consume(int $length): string
	{
		$out = substr($this->buffer, 0, $length);
		$this->buffer = substr($this->buffer, $length);
		return $out;
	}

	public function buffered(): int
	{
		return strlen($this->buffer);
	}

	public function prepend(string $data): void
	{
		$this->buffer = $data . $this->buffer;
	}

	public function write(string $data): int
	{
		$this->writes[] = $data;
		if ($this->onWrite !== null) {
			($this->onWrite)($this, $data);
		}
		return strlen($data);
	}

	public function getStream(): mixed
	{
		return null;
	}

	public function getPeerCertificate(): mixed
	{
		return $this->cert;
	}

	public function isConnected(): bool
	{
		return $this->connected;
	}

	public function close(): void
	{
		$this->connected = false;
	}
}
