<?php
/**
 * vCenter MCP Server — WebMKS Console Client
 *
 * AcquireTicket('webmks') -> TLS to the ESXi host -> WebSocket
 * handshake on /ticket/{ticket} with subprotocol 'binary' -> RFB 3.8.
 *
 * TLS trust for the ESXi hop: DANE TLSA for _<port>._tcp.<host> first
 * (via the instance TlsPolicy); when no TLSA records exist, the leaf
 * certificate must match the ticket's sslThumbprint (SHA-1) or an entry
 * in certThumbprintList — the vSphere-sanctioned trust anchor.
 *
 * I/O is blocking reads with a deadline: the transport stream is set to
 * blocking mode with stream_set_timeout; a wall-clock cap aborts the
 * operation between reads. No polling loops.
 *
 * @package    VCenterMCP\VCenter\Console
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

namespace VCenter\Console;

use EnchiladaWebSocket\StreamTransport;
use EnchiladaWebSocket\WebSocketFrame;
use EnchiladaWebSocket\WebSocketHandshake;
use EnchiladaWebSocket\WebSocketTransportInterface;
use VCenter\Instance;
use VCenter\VCenterException;

class WebMksClient
{
	private Instance $instance;
	private string $vmId;

	/** @var callable|null fn(): WebSocketTransportInterface — test seam */
	private $transportFactory;

	private ?WebSocketTransportInterface $transport = null;

	/** @var string Undecoded WebSocket frame bytes */
	private string $frameBuf = '';

	/** @var string Accumulated binary payload of a fragmented message */
	private string $fragmentBuf = '';

	private Rfb $rfb;
	private ?Framebuffer $framebuffer = null;

	private array $ticket = [];
	private string $protocol = '';
	private string $trust = '';
	private int $fbWidth = 0;
	private int $fbHeight = 0;
	private string $consoleName = '';

	/**
	 * @param callable|null $transportFactory fn(): WebSocketTransportInterface
	 */
	public function __construct(Instance $instance, string $vmId, ?callable $transportFactory = null)
	{
		$this->instance = $instance;
		$this->vmId = $vmId;
		$this->transportFactory = $transportFactory;
		$this->rfb = new Rfb(4);
	}

	public function isOpen(): bool
	{
		return $this->transport !== null && $this->transport->isConnected();
	}

	/** Ticket/negotiation details for diagnostics (after open()). */
	public function info(): array
	{
		return [
			'host' => $this->ticket['host'] ?? null,
			'port' => $this->ticket['port'] ?? null,
			'protocol' => $this->protocol,
			'tls_trust' => $this->trust,
			'width' => $this->fbWidth,
			'height' => $this->fbHeight,
			'console_name' => $this->consoleName,
		];
	}

	/**
	 * Acquire a ticket, connect TLS, authenticate the peer, run the
	 * WebSocket and RFB handshakes.
	 *
	 * @throws VCenterException On ticket/TLS/handshake failure
	 */
	public function open(float $timeout = 10.0): void
	{
		$this->ticket = $this->instance->soap()->acquireTicket(
			['type' => 'VirtualMachine', 'id' => $this->vmId], 'webmks');
		$host = (string) ($this->ticket['host'] ?? '');
		$port = (int) ($this->ticket['port'] ?? 443);
		if ($host === '' || $port <= 0) {
			throw new VCenterException('WebMKS ticket carried no usable host/port', 0, 'WebMksTicket');
		}

		$deadline = microtime(true) + $timeout;
		try {
			$this->transport = $this->transportFactory !== null
				? ($this->transportFactory)()
				: new StreamTransport([
					'verify_peer' => false,
					'verify_peer_name' => false,
					'capture_peer_cert' => true,
					'peer_name' => $host,
				]);
			$this->transport->connect($host, $port, true, max(1.0, $deadline - microtime(true)));

			$this->verifyTlsTrust($host, $port);
			$this->websocketHandshake($host, $port, (string) $this->ticket['ticket'], $deadline);
			$this->rfbHandshake($deadline);
		} catch (\Throwable $e) {
			$this->close();
			throw $e instanceof VCenterException ? $e
				: new VCenterException('WebMKS connect failed: ' . $e->getMessage(), 0, 'WebMks');
		}
	}

	/**
	 * TLS trust for the ESXi hop: TLSA first, else the ticket
	 * thumbprint(s). Throws and leaves the caller to close on mismatch.
	 *
	 * @throws VCenterException
	 */
	private function verifyTlsTrust(string $host, int $port): void
	{
		$policy = $this->instance->tlsPolicy();

		$cert = method_exists($this->transport, 'getPeerCertificate')
			? $this->transport->getPeerCertificate()
			: null;
		if ($cert === null) {
			throw new VCenterException(
				"WebMKS: no peer certificate captured for {$host}:{$port}", 0, 'TlsProbe');
		}
		$leafPem = '';
		if (!openssl_x509_export($cert, $leafPem)) {
			throw new VCenterException('WebMKS: could not export peer certificate', 0, 'TlsProbe');
		}

		$tlsa = $policy->tlsaRecords($host, $port);
		if (!empty($tlsa)) {
			$policy->assertTlsaMatch($leafPem, $tlsa, "{$host}:{$port}");
			$this->trust = 'tlsa';
			return;
		}

		// Fall back to the ticket trust anchor: sslThumbprint (SHA-1) or
		// any certThumbprintList entry (SHA-256 on vSphere 8).
		$fingerprints = [
			'sha1' => strtoupper(implode(':', str_split(openssl_x509_fingerprint($leafPem, 'sha1'), 2))),
			'sha256' => strtoupper(implode(':', str_split(openssl_x509_fingerprint($leafPem, 'sha256'), 2))),
		];
		$candidates = [];
		if (($this->ticket['sslThumbprint'] ?? '') !== '') {
			$candidates['sha1'] = $this->ticket['sslThumbprint'];
		}
		foreach ((array) ($this->ticket['certThumbprintList'] ?? []) as $row) {
			if (!is_array($row)) {
				continue;
			}
			$algo = strtolower((string) ($row['hashAlgorithm'] ?? $row['algo'] ?? $row['algorithm'] ?? ''));
			$thumb = (string) ($row['thumbprint'] ?? '');
			if ($thumb !== '' && isset($fingerprints[$algo])) {
				$candidates[$algo] = $thumb;
			}
		}
		foreach ($candidates as $algo => $expected) {
			if (strcasecmp($fingerprints[$algo], $expected) === 0) {
				$this->trust = 'thumbprint:' . $algo;
				return;
			}
		}
		throw new VCenterException(
			"WebMKS: ESXi certificate for {$host}:{$port} matches no ticket thumbprint",
			0, 'ThumbprintMismatch'
		);
	}

	/**
	 * HTTP Upgrade on /ticket/{ticket} offering only the 'binary'
	 * subprotocol. Records the server's selected protocol; anything
	 * other than 'binary' is a hard failure (the vmware-vvc framing
	 * hypothesis in PLAN).
	 *
	 * @throws VCenterException
	 */
	private function websocketHandshake(string $host, int $port, string $ticket, float $deadline): void
	{
		$hostHeader = $host . ($port !== 443 ? ":{$port}" : '');
		$handshake = new WebSocketHandshake($hostHeader, '/ticket/' . $ticket,
			['Sec-WebSocket-Protocol' => 'binary']);
		$this->transport->write($handshake->buildRequest());

		$response = $this->readHeaders($deadline);
		try {
			$handshake->validateResponse($response);
		} catch (\RuntimeException $e) {
			throw new VCenterException('WebMKS: ' . $e->getMessage(), 0, 'WebMksHandshake');
		}

		$protocol = null;
		if (preg_match('/^Sec-WebSocket-Protocol:\s*(\S+)/mi', $response, $m)) {
			$protocol = $m[1];
		}
		$this->protocol = $protocol ?? '';
		if ($protocol !== 'binary') {
			throw new VCenterException(
				'WebMKS: server selected unexpected subprotocol ' . var_export($protocol, true)
					. ' (only "binary" was offered)',
				0, 'WebMksHandshake'
			);
		}
	}

	/** RFB 3.8 handshake: version, security None, ClientInit, ServerInit. */
	private function rfbHandshake(float $deadline): void
	{
		$this->readRfb(12, $deadline);
		Rfb::parseVersion($this->takeRfb(12));
		$this->sendRfb(Rfb::VERSION);

		// Security types; count 0 means refusal with a reason string
		$this->readRfb(1, $deadline);
		$count = ord($this->peekRfb(1));
		if ($count === 0) {
			$this->readRfb(5, $deadline);
			$len = unpack('N', substr($this->rfbIn, 1, 4))[1];
			$this->readRfb(5 + $len, $deadline);
			Rfb::selectSecurityType($this->takeRfb(5 + $len)); // throws
		}
		$this->readRfb(1 + $count, $deadline);
		$type = Rfb::selectSecurityType($this->takeRfb(1 + $count));
		$this->sendRfb(Rfb::securityTypeSelection($type));

		// SecurityResult; on failure a reason string follows the u32
		$this->readRfb(4, $deadline);
		if (unpack('N', $this->peekRfb(4))[1] !== 0) {
			$this->readRfb(8, $deadline);
			$len = unpack('N', substr($this->rfbIn, 4, 4))[1];
			$this->readRfb(8 + $len, $deadline);
			Rfb::checkSecurityResult($this->takeRfb(8 + $len)); // throws
		}
		$this->takeRfb(4);
		$this->sendRfb(Rfb::clientInit(true));

		$this->readRfb(24, $deadline);
		$initLen = Rfb::serverInitLength($this->peekRfb(24));
		$this->readRfb($initLen, $deadline);
		$init = Rfb::parseServerInit($this->takeRfb($initLen));
		$this->fbWidth = $init['width'];
		$this->fbHeight = $init['height'];
		$this->consoleName = $init['name'];
		$this->framebuffer = new Framebuffer($init['width'], $init['height']);
	}

	/**
	 * Full-frame screenshot: negotiate our pixel format and Raw-only
	 * encodings, request a non-incremental update, paint rectangles
	 * until the framebuffer is complete or the deadline expires.
	 *
	 * @return array{png:string,width:int,height:int}
	 * @throws VCenterException
	 */
	public function screenshot(float $timeout = 10.0): array
	{
		$this->ensureOpen();
		$deadline = microtime(true) + $timeout;

		// Repaint from scratch: without this the previous capture's
		// complete state shortcuts the update loop with stale pixels.
		$this->framebuffer->reset();

		$this->sendRfb(Rfb::setPixelFormat() . Rfb::setEncodings([0])
			. Rfb::framebufferUpdateRequest(false, 0, 0, $this->fbWidth, $this->fbHeight));
		$lastRequestAt = microtime(true);

		while (!$this->framebuffer->complete()) {
			if (microtime(true) > $deadline) {
				throw new VCenterException(
					'WebMKS: framebuffer incomplete at deadline ('
						. round($this->framebuffer->coverage() * 100, 1) . '% painted)',
					0, 'WebMksTimeout');
			}
			$this->pump($deadline);
			if ($this->rfbIn !== '') {
				$this->rfb->feed($this->takeRfb(strlen($this->rfbIn)));
			}
			$gotUpdate = false;
			while (($msg = $this->rfb->next()) !== null) {
				if ($msg['type'] !== 'framebuffer_update') {
					continue;
				}
				$gotUpdate = true;
				foreach ($msg['rects'] as $r) {
					$this->framebuffer->blit($r['x'], $r['y'], $r['w'], $r['h'], $r['data']);
				}
			}
			// webmks answers each request with only the dirty region, so
			// a partial frame needs another non-incremental request —
			// one outstanding at a time, resent when an update arrives or
			// the request looks lost.
			if (!$this->framebuffer->complete()
				&& ($gotUpdate || microtime(true) - $lastRequestAt > 1.0)) {
				$this->sendRfb(Rfb::framebufferUpdateRequest(false, 0, 0, $this->fbWidth, $this->fbHeight));
				$lastRequestAt = microtime(true);
			}
		}

		$rgb = $this->framebuffer->toRgb();
		return [
			'png' => Png::encode($this->fbWidth, $this->fbHeight, $rgb),
			'width' => $this->fbWidth,
			'height' => $this->fbHeight,
		];
	}

	/**
	 * Send key strokes (from Keysyms::fromText/fromKeys): each stroke's
	 * events are written as RFB KeyEvent messages; delay_ms paces the
	 * gap between strokes.
	 *
	 * @param array<int,array<int,array{down:bool,keysym:int}>> $strokes
	 * @return int Number of strokes sent
	 * @throws VCenterException
	 */
	public function sendKeys(array $strokes, int $delayMs = 20): int
	{
		$this->ensureOpen();
		$sent = 0;
		foreach ($strokes as $i => $stroke) {
			if ($i > 0 && $delayMs > 0) {
				usleep($delayMs * 1000); // pacing between remote key events
			}
			$out = '';
			foreach ($stroke as $ev) {
				$out .= Rfb::keyEvent($ev['down'], $ev['keysym']);
			}
			$this->sendRfb($out);
			$sent++;
		}
		return $sent;
	}

	public function close(): void
	{
		if ($this->transport !== null) {
			try {
				$this->transport->write(WebSocketFrame::close()->encode());
			} catch (\Throwable $e) {
				// already gone
			}
			$this->transport->close();
			$this->transport = null;
		}
	}

	// ── Wire I/O ───────────────────────────────────────────────────

	/** @var string RFB-layer undecoded bytes */
	private string $rfbIn = '';

	private function peekRfb(int $n): string
	{
		return substr($this->rfbIn, 0, $n);
	}

	private function takeRfb(int $n): string
	{
		$out = substr($this->rfbIn, 0, $n);
		$this->rfbIn = substr($this->rfbIn, $n);
		return $out;
	}

	/** Block until the RFB buffer holds $n bytes or the deadline passes. */
	private function readRfb(int $n, float $deadline): void
	{
		while (strlen($this->rfbIn) < $n) {
			if (microtime(true) > $deadline) {
				throw new VCenterException('WebMKS: timed out waiting for RFB data', 0, 'WebMksTimeout');
			}
			$this->decodeFrames();
			if (strlen($this->rfbIn) >= $n) {
				break;
			}
			$this->pump($deadline);
		}
	}

	/** Send RFB bytes inside a binary WebSocket frame. */
	private function sendRfb(string $data): void
	{
		try {
			$this->transport->write(WebSocketFrame::binary($data)->encode());
		} catch (\Throwable $e) {
			throw new VCenterException('WebMKS: write failed: ' . $e->getMessage(), 0, 'WebMks');
		}
	}

	/**
	 * One blocking read cycle: drain the transport (the stream is in
	 * blocking mode with a per-read timeout), decode complete WebSocket
	 * frames, append binary payloads to the RFB buffer.
	 *
	 * @throws VCenterException On close/read error
	 */
	private function pump(float $deadline): void
	{
		// Decode anything already buffered first — a blocking read would
		// wait out the whole stream timeout even when frameBuf already
		// holds a complete frame (the RFB banner typically rides in the
		// same segment as the 101 response).
		$this->decodeFrames();
		$this->frameBuf .= $this->readOnce($deadline);
		$this->decodeFrames();
	}

	/**
	 * One blocking read: a single fread() on the transport stream with a
	 * per-call timeout (a blocking fread returns as soon as any data
	 * arrives — unlike drain(), which loops until the stream is empty
	 * and would burn the whole deadline). Falls back to drain() for
	 * transports that do not expose a stream resource.
	 */
	private function readOnce(float $deadline): string
	{
		$stream = $this->transport->getStream();
		if (!is_resource($stream)) {
			try {
				$this->transport->drain();
			} catch (\Throwable $e) {
				throw new VCenterException('WebMKS: read failed: ' . $e->getMessage(), 0, 'WebMks');
			}
			return $this->transport->consume($this->transport->buffered());
		}

		$remaining = max(0.001, $deadline - microtime(true));
		stream_set_blocking($stream, true);
		stream_set_timeout($stream, (int) $remaining, (int) (($remaining - (int) $remaining) * 1e6));
		$chunk = @fread($stream, 65536);
		if ($chunk === false) {
			if (feof($stream)) {
				throw new VCenterException('WebMKS: connection closed by peer', 0, 'WebMks');
			}
			// timeout expiry surfaces as false on TLS streams — not an error
			return '';
		}
		if ($chunk === '' && feof($stream)) {
			throw new VCenterException('WebMKS: connection closed by peer', 0, 'WebMks');
		}
		return $chunk;
	}

	/** Decode all complete WebSocket frames in frameBuf into rfbIn. */
	private function decodeFrames(): void
	{
		while (($decoded = WebSocketFrame::tryDecode($this->frameBuf)) !== null) {
			[$frame, $consumed] = $decoded;
			$this->frameBuf = substr($this->frameBuf, $consumed);

			if ($frame->opcode === WebSocketFrame::OPCODE_CLOSE) {
				throw new VCenterException('WebMKS: server closed the WebSocket', 0, 'WebMks');
			}
			if ($frame->opcode === WebSocketFrame::OPCODE_PING) {
				try {
					$this->transport->write(WebSocketFrame::pong($frame->payload)->encode());
				} catch (\Throwable $e) {
					// peer is gone; the next read will surface it
				}
				continue;
			}
			if ($frame->opcode === WebSocketFrame::OPCODE_PONG) {
				continue;
			}

			$this->fragmentBuf .= $frame->payload;
			if ($frame->fin) {
				$this->rfbIn .= $this->fragmentBuf;
				$this->fragmentBuf = '';
			}
		}
	}

	/**
	 * Read the HTTP handshake response headers (through "\r\n\r\n").
	 * Bytes past the header terminator belong to WebSocket frames and
	 * are kept in $this->frameBuf.
	 */
	private function readHeaders(float $deadline): string
	{
		$response = '';
		while (($end = strpos($response, "\r\n\r\n")) === false) {
			if (microtime(true) > $deadline || strlen($response) > 16384) {
				throw new VCenterException('WebMKS: timed out in WebSocket handshake', 0, 'WebMksTimeout');
			}
			$response .= $this->readOnce($deadline);
		}
		$this->frameBuf = substr($response, $end + 4);
		return substr($response, 0, $end + 4);
	}

	private function ensureOpen(): void
	{
		if (!$this->isOpen()) {
			throw new VCenterException('WebMKS: console session is not open', 0, 'WebMks');
		}
	}
}
