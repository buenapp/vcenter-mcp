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

	public function testResetClearsCoverageKeepsPixels(): void
	{
		$fb = new Framebuffer(2, 1);
		$fb->blit(0, 0, 2, 1, str_repeat(pack('V', 0x00FF0000), 2));
		$this->assertTrue($fb->complete());

		$fb->reset();
		$this->assertFalse($fb->complete());
		$this->assertSame(0.0, $fb->coverage());

		// last-known pixels survive until fresh rectangles overwrite them
		$this->assertSame("\xFF\x00\x00\xFF\x00\x00", $fb->toRgb());
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
