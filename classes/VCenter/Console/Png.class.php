<?php
/**
 * vCenter MCP Server — PNG Encoder
 *
 * Packed 8-bit RGB -> PNG without the GD or zlib extensions. Rows use
 * filter type 0. The zlib stream is built by hand: 0x78 0x01 header,
 * deflate *stored* blocks (BFINAL + BTYPE=00, LEN/NLEN little-endian,
 * max 65535 bytes per block), then the Adler-32 of the raw data. When
 * gzcompress() is available (host has zlib) it is used instead for
 * smaller output.
 *
 * @package    VCenterMCP\VCenter\Console
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

namespace VCenter\Console;

use VCenter\VCenterException;

class Png
{
	/**
	 * Encode a packed RGB image (3 bytes per pixel, row-major, no
	 * padding) as a PNG binary string.
	 *
	 * @throws VCenterException On bad dimensions or data length
	 */
	public static function encode(int $width, int $height, string $rgb): string
	{
		if ($width <= 0 || $height <= 0) {
			throw new VCenterException("PNG: invalid dimensions {$width}x{$height}", 0, 'PngEncode');
		}
		if (strlen($rgb) !== $width * $height * 3) {
			throw new VCenterException(
				'PNG: expected ' . ($width * $height * 3) . ' RGB bytes, got ' . strlen($rgb),
				0, 'PngEncode'
			);
		}

		// Filter byte 0 at the start of each row
		$raw = '';
		$rowLen = $width * 3;
		for ($y = 0; $y < $height; $y++) {
			$raw .= "\x00" . substr($rgb, $y * $rowLen, $rowLen);
		}

		$ihdr = pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);

		return "\x89PNG\r\n\x1a\n"
			. self::chunk('IHDR', $ihdr)
			. self::chunk('IDAT', self::zlib($raw))
			. self::chunk('IEND', '');
	}

	/** PNG chunk: length + type + data + CRC32(type . data). */
	private static function chunk(string $type, string $data): string
	{
		return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
	}

	/**
	 * zlib stream (RFC 1950) wrapping $data. Uses gzcompress() when the
	 * host has zlib; otherwise emits uncompressed deflate stored blocks.
	 */
	private static function zlib(string $data): string
	{
		if (function_exists('gzcompress')) {
			return gzcompress($data);
		}

		$out = "\x78\x01"; // CMF/FLG: 32K window, no compression, check bits
		$len = strlen($data);
		for ($off = 0; $off < $len || ($len === 0 && $off === 0); $off += 65535) {
			$block = substr($data, $off, 65535);
			$final = ($off + strlen($block) >= $len) ? 1 : 0;
			$n = strlen($block);
			$out .= chr($final)                      // BFINAL | BTYPE=00
				. pack('v', $n) . pack('v', ~$n & 0xFFFF)
				. $block;
			if ($len === 0) {
				break;
			}
		}
		$out .= pack('N', self::adler32($data));
		return $out;
	}

	/** Adler-32 of $data — hash('adler32') when present, else manual. */
	private static function adler32(string $data): int
	{
		if (in_array('adler32', hash_algos(), true)) {
			return hexdec(hash('adler32', $data));
		}
		$a = 1;
		$b = 0;
		foreach (str_split($data, 5552) as $chunk) { // 5552 = largest n with n*(n+1)/2*(255+a) < 2^32
			$l = strlen($chunk);
			for ($i = 0; $i < $l; $i++) {
				$a += ord($chunk[$i]);
				$b += $a;
			}
			$a %= 65521;
			$b %= 65521;
		}
		return ($b << 16) | $a;
	}
}
