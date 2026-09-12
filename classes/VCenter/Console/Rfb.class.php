<?php
/**
 * vCenter MCP Server — RFB 3.8 Codec
 *
 * Pure encode/decode for the RFB ("VNC") protocol spoken inside a
 * WebMKS WebSocket session — no I/O. Client messages are built by the
 * static encoders; server messages are decoded incrementally by
 * feed()/next(), which return "need more bytes" on partial input.
 * Only Raw encoding (0) framebuffer rectangles are supported.
 *
 * @package    VCenterMCP\VCenter\Console
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

namespace VCenter\Console;

use VCenter\VCenterException;

class Rfb
{
	public const VERSION = "RFB 003.008\n";

	// Server message types
	public const MSG_FRAMEBUFFER_UPDATE = 0;
	public const MSG_SET_COLOUR_MAP_ENTRIES = 1;
	public const MSG_BELL = 2;
	public const MSG_SERVER_CUT_TEXT = 3;

	/** @var string Undecoded server bytes */
	private string $in = '';

	/** @var int Bytes per pixel for Raw rects (4 for 32bpp) */
	private int $bpp;

	public function __construct(int $bytesPerPixel = 4)
	{
		$this->bpp = $bytesPerPixel;
	}

	// ── Client messages ────────────────────────────────────────────

	/** Server version banner -> e.g. '003.008'. */
	public static function parseVersion(string $buf): string
	{
		if (strlen($buf) < 12 || !str_starts_with($buf, 'RFB ') || $buf[11] !== "\n") {
			throw new VCenterException('RFB: bad version banner', 0, 'RfbProtocol');
		}
		return substr($buf, 4, 7);
	}

	/**
	 * Security-type list -> selected type. Chooses 1 (None); throws when
	 * the server does not offer it. A count of 0 is a connection failure
	 * and carries a reason string.
	 *
	 * @return int Selected security type
	 * @throws VCenterException
	 */
	public static function selectSecurityType(string $buf): int
	{
		$count = ord($buf[0]);
		if ($count === 0) {
			$reason = '';
			if (strlen($buf) >= 5) {
				$len = unpack('N', substr($buf, 1, 4))[1];
				$reason = substr($buf, 5, $len);
			}
			throw new VCenterException("RFB: server refused connection ({$reason})", 0, 'RfbSecurity');
		}
		$types = array_map('ord', str_split(substr($buf, 1, $count)));
		if (!in_array(1, $types, true)) {
			throw new VCenterException(
				'RFB: server offers no usable security type (offered: ' . implode(',', $types) . ')',
				0, 'RfbSecurity'
			);
		}
		return 1;
	}

	/** Client security-type selection (one byte). */
	public static function securityTypeSelection(int $type): string
	{
		return chr($type);
	}

	/**
	 * SecurityResult (u32; 0 = OK). On failure the reason string follows
	 * and is included in the exception.
	 *
	 * @throws VCenterException On authentication failure
	 */
	public static function checkSecurityResult(string $buf): void
	{
		$result = unpack('N', substr($buf, 0, 4))[1];
		if ($result === 0) {
			return;
		}
		$reason = 'unknown';
		if (strlen($buf) >= 8) {
			$len = unpack('N', substr($buf, 4, 4))[1];
			$reason = substr($buf, 8, $len) ?: $reason;
		}
		throw new VCenterException("RFB: security handshake failed ({$reason})", 0, 'RfbSecurity');
	}

	public static function clientInit(bool $shared = true): string
	{
		return chr($shared ? 1 : 0);
	}

	/**
	 * ServerInit -> [width, height, name]. Expects the 16-byte pixel
	 * format between the dimensions and the name.
	 */
	public static function parseServerInit(string $buf): array
	{
		if (strlen($buf) < 24) {
			throw new VCenterException('RFB: truncated ServerInit', 0, 'RfbProtocol');
		}
		[$width, $height] = array_values(unpack('n2', substr($buf, 0, 4)));
		$nameLen = unpack('N', substr($buf, 20, 4))[1];
		if (strlen($buf) < 24 + $nameLen) {
			throw new VCenterException('RFB: truncated ServerInit name', 0, 'RfbProtocol');
		}
		return ['width' => $width, 'height' => $height, 'name' => substr($buf, 24, $nameLen)];
	}

	/** Bytes needed for a full ServerInit given what has arrived so far. */
	public static function serverInitLength(string $buf): int
	{
		return strlen($buf) >= 24 ? 24 + unpack('N', substr($buf, 20, 4))[1] : 24;
	}

	/** SetPixelFormat: 32bpp, depth 24, little-endian, true-colour RGB. */
	public static function setPixelFormat(): string
	{
		return chr(0) . "\x00\x00\x00"   // type 0 + padding
			. pack('CCCCnnnCCCxxx',
				32,      // bits-per-pixel
				24,      // depth
				0,       // big-endian-flag (little)
				1,       // true-colour-flag
				255, 255, 255,   // red/green/blue max
				16, 8, 0         // red/green/blue shift
			);                              // + 3 bytes padding = 16-byte pixel format
	}

	/** @param int[] $encodings */
	public static function setEncodings(array $encodings): string
	{
		$out = chr(2) . "\x00" . pack('n', count($encodings));
		foreach ($encodings as $enc) {
			$out .= pack('N', $enc);
		}
		return $out;
	}

	public static function framebufferUpdateRequest(bool $incremental, int $x, int $y, int $w, int $h): string
	{
		return chr(3) . chr($incremental ? 1 : 0) . pack('nnnn', $x, $y, $w, $h);
	}

	public static function keyEvent(bool $down, int $keysym): string
	{
		return chr(4) . chr($down ? 1 : 0) . "\x00\x00" . pack('N', $keysym);
	}

	// ── Server messages (incremental) ──────────────────────────────

	/** Append received bytes to the decode buffer. */
	public function feed(string $data): void
	{
		$this->in .= $data;
	}

	public function buffered(): int
	{
		return strlen($this->in);
	}

	/**
	 * Decode the next complete server message, or null when the buffer
	 * holds only a partial message ("need more bytes").
	 *
	 * @return array|null
	 *   ['type'=>'framebuffer_update', 'rects'=>[['x','y','w','h','encoding','data'], ...]]
	 *   ['type'=>'bell'] | ['type'=>'set_colour_map_entries']
	 *   | ['type'=>'server_cut_text', 'text'=>string]
	 * @throws VCenterException On an unknown message or rectangle encoding
	 */
	public function next(): ?array
	{
		if ($this->in === '') {
			return null;
		}
		$type = ord($this->in[0]);

		switch ($type) {
			case self::MSG_FRAMEBUFFER_UPDATE:
				return $this->nextFramebufferUpdate();
			case self::MSG_SET_COLOUR_MAP_ENTRIES:
				if (strlen($this->in) < 6) {
					return null;
				}
				$count = unpack('n', substr($this->in, 4, 2))[1];
				$need = 6 + $count * 6;
				if (strlen($this->in) < $need) {
					return null;
				}
				$this->in = substr($this->in, $need);
				return ['type' => 'set_colour_map_entries'];
			case self::MSG_BELL:
				$this->in = substr($this->in, 1);
				return ['type' => 'bell'];
			case self::MSG_SERVER_CUT_TEXT:
				if (strlen($this->in) < 8) {
					return null;
				}
				$len = unpack('N', substr($this->in, 4, 4))[1];
				if (strlen($this->in) < 8 + $len) {
					return null;
				}
				$text = substr($this->in, 8, $len);
				$this->in = substr($this->in, 8 + $len);
				return ['type' => 'server_cut_text', 'text' => $text];
			default:
				throw new VCenterException("RFB: unknown server message type {$type}", 0, 'RfbProtocol');
		}
	}

	private function nextFramebufferUpdate(): ?array
	{
		if (strlen($this->in) < 4) {
			return null;
		}
		$count = unpack('n', substr($this->in, 2, 2))[1];
		$offset = 4;
		$rects = [];
		for ($i = 0; $i < $count; $i++) {
			if (strlen($this->in) < $offset + 12) {
				return null;
			}
			[$x, $y, $w, $h] = array_values(unpack('n4', substr($this->in, $offset, 8)));
			$encoding = unpack('N', substr($this->in, $offset + 8, 4))[1];
			if ($encoding !== 0) {
				throw new VCenterException(
					"RFB: unsupported rectangle encoding {$encoding} (only Raw is negotiated)",
					0, 'RfbEncoding'
				);
			}
			$offset += 12;
			$payload = $w * $h * $this->bpp;
			if (strlen($this->in) < $offset + $payload) {
				return null;
			}
			$rects[] = [
				'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h,
				'encoding' => 0,
				'data' => substr($this->in, $offset, $payload),
			];
			$offset += $payload;
		}
		$this->in = substr($this->in, $offset);
		return ['type' => 'framebuffer_update', 'rects' => $rects];
	}
}
