<?php

use PHPUnit\Framework\TestCase;
use VCenter\Console\Framebuffer;
use VCenter\VCenterException;

class FramebufferTest extends TestCase
{
	public function testBlitCoverageAndToRgb(): void
	{
		$fb = new Framebuffer(4, 4);
		$this->assertSame(0.0, $fb->coverage());

		// left half: green (v = g<<8 -> LE bytes b=0,g=FF,r=0,pad)
		$fb->blit(0, 0, 2, 4, str_repeat(pack('V', 0x0000FF00), 8));
		$this->assertSame(0.5, $fb->coverage());
		$this->assertFalse($fb->complete());

		// right half: red
		$fb->blit(2, 0, 2, 4, str_repeat(pack('V', 0x00FF0000), 8));
		$this->assertTrue($fb->complete());
		$this->assertSame(1.0, $fb->coverage());

		$rgb = $fb->toRgb();
		$this->assertSame(4 * 4 * 3, strlen($rgb));
		$this->assertSame("\x00\xFF\x00", substr($rgb, 0, 3));   // pixel (0,0) green
		$this->assertSame("\xFF\x00\x00", substr($rgb, 2 * 3, 3)); // pixel (2,0) red
	}

	public function testPartialRowBlitsAndOverlapCountOnce(): void
	{
		$fb = new Framebuffer(4, 2);
		$fb->blit(1, 0, 2, 2, str_repeat(pack('V', 0x00FF0000), 4));  // red middle columns
		$this->assertSame(0.5, $fb->coverage());

		// overlaps column 2; only column 3 counts as newly painted
		$fb->blit(2, 0, 2, 2, str_repeat(pack('V', 0x000000FF), 4));  // blue right columns
		$this->assertSame(0.75, $fb->coverage());

		$fb->blit(0, 0, 1, 2, str_repeat(pack('V', 0x0000FF00), 2));  // green left column
		$this->assertTrue($fb->complete());

		$this->assertSame(
			"\x00\xFF\x00" . "\xFF\x00\x00" . "\x00\x00\xFF" . "\x00\x00\xFF",
			substr($fb->toRgb(), 0, 12)
		);
	}

	public function testFullFrameBlitIsLinear(): void
	{
		// 1024x768 in six ESXi-shaped tiles: per-row storage keeps this
		// well under a second (whole-buffer copies took ~4 s).
		$fb = new Framebuffer(1024, 768);
		$start = microtime(true);
		foreach ([[0, 0, 304], [512, 0, 304], [0, 304, 224], [512, 304, 224], [0, 528, 240], [512, 528, 240]] as [$x, $y, $h]) {
			$fb->blit($x, $y, 512, $h, str_repeat("\x10\x20\x30\x00", 512 * $h));
		}
		$rgb = $fb->toRgb();
		$this->assertTrue($fb->complete());
		$this->assertSame("\x30\x20\x10", substr($rgb, -3));
		$this->assertLessThan(1.0, microtime(true) - $start);
	}

	public function testBlitRejectsOutOfBounds(): void
	{
		$fb = new Framebuffer(4, 4);
		$this->expectException(VCenterException::class);
		$fb->blit(3, 0, 2, 1, str_repeat("\0", 8));
	}

	public function testBlitRejectsBadLength(): void
	{
		$fb = new Framebuffer(4, 4);
		$this->expectException(VCenterException::class);
		$fb->blit(0, 0, 2, 2, 'short');
	}
}
