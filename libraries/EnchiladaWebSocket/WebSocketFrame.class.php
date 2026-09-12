<?php
/**
 * Enchilada WebSocket — Frame Encoder/Decoder
 *
 * Pure data transformation: encodes and decodes RFC 6455 WebSocket frames.
 * No I/O — operates only on byte strings.
 *
 * @package    EnchiladaWebSocket
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

namespace EnchiladaWebSocket;

class WebSocketFrame
{
	// Opcodes (RFC 6455 Section 5.2)
	const OPCODE_CONTINUATION = 0x0;
	const OPCODE_TEXT         = 0x1;
	const OPCODE_BINARY       = 0x2;
	const OPCODE_CLOSE        = 0x8;
	const OPCODE_PING         = 0x9;
	const OPCODE_PONG         = 0xA;

	public int $opcode;
	public string $payload;
	public bool $fin;
	public bool $masked;

	public function __construct(int $opcode, string $payload, bool $fin = true, bool $masked = true)
	{
		$this->opcode = $opcode;
		$this->payload = $payload;
		$this->fin = $fin;
		$this->masked = $masked;
	}

	/**
	 * Encode this frame into a wire-format byte string.
	 *
	 * Clients MUST mask frames per RFC 6455 Section 5.3.
	 */
	public function encode(): string
	{
		$payload = $this->payload;
		$length = strlen($payload);
		$frame = '';

		// First byte: FIN + opcode
		$firstByte = ($this->fin ? 0x80 : 0x00) | ($this->opcode & 0x0F);
		$frame .= chr($firstByte);

		// Second byte: MASK flag + payload length
		$maskBit = $this->masked ? 0x80 : 0x00;

		if ($length < 126) {
			$frame .= chr($maskBit | $length);
		} elseif ($length < 65536) {
			$frame .= chr($maskBit | 126);
			$frame .= pack('n', $length);
		} else {
			$frame .= chr($maskBit | 127);
			$frame .= pack('J', $length);
		}

		// Masking key + masked payload (client frames)
		if ($this->masked) {
			$maskKey = random_bytes(4);
			$frame .= $maskKey;

			$maskedPayload = '';
			for ($i = 0; $i < $length; $i++) {
				$maskedPayload .= chr(ord($payload[$i]) ^ ord($maskKey[$i % 4]));
			}
			$frame .= $maskedPayload;
		} else {
			$frame .= $payload;
		}

		return $frame;
	}

	/**
	 * Try to decode a complete frame from a byte buffer.
	 *
	 * Returns [frame, bytesConsumed] if a complete frame is available,
	 * or null if the buffer contains an incomplete frame. Never blocks.
	 *
	 * @param string $buffer Raw bytes (not modified — caller advances by bytesConsumed)
	 * @return array{0: self, 1: int}|null [frame, bytesConsumed] or null if incomplete
	 */
	public static function tryDecode(string $buffer): ?array
	{
		$bufLen = strlen($buffer);
		$offset = 0;

		// Need at least 2 bytes for the minimal header
		if ($bufLen < 2) {
			return null;
		}

		$firstByte = ord($buffer[0]);
		$secondByte = ord($buffer[1]);
		$offset = 2;

		$fin = (bool) ($firstByte & 0x80);
		$opcode = $firstByte & 0x0F;
		$masked = (bool) ($secondByte & 0x80);
		$length = $secondByte & 0x7F;

		// Extended payload length
		if ($length === 126) {
			if ($bufLen < $offset + 2) return null;
			$length = unpack('n', substr($buffer, $offset, 2))[1];
			$offset += 2;
		} elseif ($length === 127) {
			if ($bufLen < $offset + 8) return null;
			$length = unpack('J', substr($buffer, $offset, 8))[1];
			$offset += 8;
		}

		// Masking key
		$maskKey = null;
		if ($masked) {
			if ($bufLen < $offset + 4) return null;
			$maskKey = substr($buffer, $offset, 4);
			$offset += 4;
		}

		// Payload
		if ($bufLen < $offset + $length) {
			return null; // Incomplete payload
		}

		$payload = substr($buffer, $offset, $length);
		$offset += $length;

		if ($masked && $maskKey !== null) {
			$unmasked = '';
			for ($i = 0; $i < $length; $i++) {
				$unmasked .= chr(ord($payload[$i]) ^ ord($maskKey[$i % 4]));
			}
			$payload = $unmasked;
		}

		return [new self($opcode, $payload, $fin, $masked), $offset];
	}

	/**
	 * Create a text frame.
	 */
	public static function text(string $payload, bool $masked = true): self
	{
		return new self(self::OPCODE_TEXT, $payload, true, $masked);
	}

	/**
	 * Create a binary frame.
	 */
	public static function binary(string $payload, bool $masked = true): self
	{
		return new self(self::OPCODE_BINARY, $payload, true, $masked);
	}

	/**
	 * Create a close frame.
	 *
	 * @param int $code Close status code (1000 = normal)
	 * @param string $reason Optional close reason
	 */
	public static function close(int $code = 1000, string $reason = '', bool $masked = true): self
	{
		$payload = pack('n', $code) . $reason;
		return new self(self::OPCODE_CLOSE, $payload, true, $masked);
	}

	/**
	 * Create a ping frame.
	 */
	public static function ping(string $payload = '', bool $masked = true): self
	{
		return new self(self::OPCODE_PING, $payload, true, $masked);
	}

	/**
	 * Create a pong frame (response to ping).
	 */
	public static function pong(string $payload = '', bool $masked = true): self
	{
		return new self(self::OPCODE_PONG, $payload, true, $masked);
	}

	/**
	 * Check if this is a control frame.
	 */
	public function isControl(): bool
	{
		return ($this->opcode & 0x08) !== 0;
	}
}
