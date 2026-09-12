<?php

use PHPUnit\Framework\TestCase;
use VCenter\Console\Png;
use VCenter\VCenterException;

class PngTest extends TestCase
{
	private function chunks(string $png): array
	{
		$this->assertSame("\x89PNG\r\n\x1a\n", substr($png, 0, 8));
		$out = [];
		for ($off = 8; $off < strlen($png);) {
			$len = unpack('N', substr($png, $off, 4))[1];
			$type = substr($png, $off + 4, 4);
			$data = substr($png, $off + 8, $len);
			$crc = unpack('N', substr($png, $off + 8 + $len, 4))[1];
			$this->assertSame(crc32($type . $data), $crc, "CRC of {$type}");
			$out[$type] = $data;
			$off += 12 + $len;
		}
		return $out;
	}

	/** Decode the hand-rolled stored-block zlib stream by hand. */
	private function decodeStoredZlib(string $zlib): string
	{
		$this->assertSame("\x78\x01", substr($zlib, 0, 2));
		$off = 2;
		$raw = '';
		do {
			$hdr = ord($zlib[$off]);
			$final = $hdr & 1;
			$this->assertSame(0, ($hdr >> 1) & 3, 'deflate block must be stored (BTYPE=00)');
			[$len, $nlen] = array_values(unpack('v2', substr($zlib, $off + 1, 4)));
			$this->assertSame((~$len) & 0xFFFF, $nlen, 'NLEN');
			$raw .= substr($zlib, $off + 5, $len);
			$off += 5 + $len;
		} while (!$final);
		$this->assertSame(unpack('N', substr($zlib, $off, 4))[1], hexdec(hash('adler32', $raw)));
		return $raw;
	}

	public function testEncodeStructure(): void
	{
		$rgb = str_repeat("\x11\x22\x33", 6); // 3x2
		$png = Png::encode(3, 2, $rgb);
		$chunks = $this->chunks($png);

		$this->assertArrayHasKey('IHDR', $chunks);
		$this->assertArrayHasKey('IDAT', $chunks);
		$this->assertArrayHasKey('IEND', $chunks);
		$this->assertSame('', $chunks['IEND']);

		$ihdr = unpack('Nw/Nh/Cdepth/Ccolour', $chunks['IHDR']);
		[$w, $h, $depth, $colour] = [$ihdr['w'], $ihdr['h'], $ihdr['depth'], $ihdr['colour']];
		$this->assertSame(3, $w);
		$this->assertSame(2, $h);
		$this->assertSame(8, $depth);
		$this->assertSame(2, $colour);

		if (function_exists('gzcompress')) {
			$raw = gzuncompress($chunks['IDAT']);
		} else {
			$raw = $this->decodeStoredZlib($chunks['IDAT']);
		}
		$this->assertSame("\x00" . substr($rgb, 0, 9) . "\x00" . substr($rgb, 9, 9), $raw);
	}

	public function testEncodeRejectsBadInput(): void
	{
		$this->expectException(VCenterException::class);
		Png::encode(0, 2, 'xxx');
	}

	public function testEncodeRejectsShortData(): void
	{
		$this->expectException(VCenterException::class);
		Png::encode(3, 2, 'short');
	}
}
