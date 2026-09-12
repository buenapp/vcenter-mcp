<?php

use PHPUnit\Framework\TestCase;
use VCenter\Console\Framebuffer;
use VCenter\Console\Rfb;
use VCenter\VCenterException;

class RfbTest extends TestCase
{
	private function serverInit(int $w = 640, int $h = 480, string $name = 'vm console'): string
	{
		$pixelFormat = pack('CCCCnnnCCCxxx', 32, 24, 0, 1, 255, 255, 255, 16, 8, 0);
		return pack('nn', $w, $h) . $pixelFormat . pack('N', strlen($name)) . $name;
	}

	private function rawRect(int $x, int $y, int $w, int $h, string $pixels): string
	{
		return pack('nnnnN', $x, $y, $w, $h, 0) . $pixels;
	}

	public function testVersionParse(): void
	{
		$this->assertSame('003.008', Rfb::parseVersion("RFB 003.008\n"));
		$this->expectException(VCenterException::class);
		Rfb::parseVersion("nope");
	}

	public function testSecurityTypeSelection(): void
	{
		$this->assertSame(1, Rfb::selectSecurityType("\x02\x01\x02")); // None, VNC offered
		$this->assertSame("\x01", Rfb::securityTypeSelection(1));
	}

	public function testSecurityTypeRefusalCarriesReason(): void
	{
		$reason = 'Too many sessions';
		$buf = "\x00" . pack('N', strlen($reason)) . $reason;
		try {
			Rfb::selectSecurityType($buf);
			$this->fail('expected exception');
		} catch (VCenterException $e) {
			$this->assertStringContainsString($reason, $e->getMessage());
		}
	}

	public function testSecurityTypeNoneNotOffered(): void
	{
		$this->expectException(VCenterException::class);
		Rfb::selectSecurityType("\x01\x02"); // VNC auth only
	}

	public function testSecurityResult(): void
	{
		Rfb::checkSecurityResult("\x00\x00\x00\x00");
		$reason = 'auth failed';
		try {
			Rfb::checkSecurityResult(pack('N', 1) . pack('N', strlen($reason)) . $reason);
			$this->fail('expected exception');
		} catch (VCenterException $e) {
			$this->assertStringContainsString($reason, $e->getMessage());
		}
	}

	public function testClientInitAndServerInit(): void
	{
		$this->assertSame("\x01", Rfb::clientInit(true));
		$init = Rfb::parseServerInit($this->serverInit(800, 600, 'testvm'));
		$this->assertSame(800, $init['width']);
		$this->assertSame(600, $init['height']);
		$this->assertSame('testvm', $init['name']);
	}

	public function testClientMessageEncodings(): void
	{
		$spf = Rfb::setPixelFormat();
		$this->assertSame(20, strlen($spf));
		$this->assertSame(0, ord($spf[0]));
		$this->assertSame(32, ord($spf[4]));  // bpp
		$this->assertSame(24, ord($spf[5]));  // depth
		$this->assertSame(0, ord($spf[6]));   // little-endian
		$this->assertSame(1, ord($spf[7]));   // true colour
		$this->assertSame([255, 255, 255], array_values(unpack('n3', substr($spf, 8, 6))));
		$this->assertSame([16, 8, 0], array_values(unpack('C3', substr($spf, 14, 3))));

		$this->assertSame("\x02\x00\x00\x01" . pack('N', 0), Rfb::setEncodings([0]));
		$this->assertSame("\x03\x00" . pack('nnnn', 0, 0, 640, 480), Rfb::framebufferUpdateRequest(false, 0, 0, 640, 480));
		$this->assertSame("\x03\x01" . pack('nnnn', 10, 10, 8, 8), Rfb::framebufferUpdateRequest(true, 10, 10, 8, 8));
		$this->assertSame("\x04\x01\x00\x00" . pack('N', 0xFF0D), Rfb::keyEvent(true, 0xFF0D));
		$this->assertSame("\x04\x00\x00\x00" . pack('N', 0x61), Rfb::keyEvent(false, 0x61));
	}

	public function testFramebufferUpdateTwoRects(): void
	{
		$fb = new Framebuffer(4, 2);
		$rfb = new Rfb(4);

		$px1 = str_repeat(pack('V', 0x00FF0000), 4); // row 0: red
		$px2 = str_repeat(pack('V', 0x000000FF), 4); // row 1: blue
		$msg = chr(0) . "\x00" . pack('n', 2)
			. $this->rawRect(0, 0, 4, 1, $px1)
			. $this->rawRect(0, 1, 4, 1, $px2);

		$rfb->feed($msg);
		$decoded = $rfb->next();
		$this->assertSame('framebuffer_update', $decoded['type']);
		$this->assertCount(2, $decoded['rects']);
		foreach ($decoded['rects'] as $r) {
			$fb->blit($r['x'], $r['y'], $r['w'], $r['h'], $r['data']);
		}
		$this->assertTrue($fb->complete());
		$this->assertSame(str_repeat("\xFF\x00\x00", 4) . str_repeat("\x00\x00\xFF", 4), $fb->toRgb());
		$this->assertNull($rfb->next());
	}

	public function testPartialBufferResumes(): void
	{
		$rfb = new Rfb(4);
		$msg = chr(0) . "\x00" . pack('n', 1) . $this->rawRect(0, 0, 2, 2, str_repeat("\x00", 16));

		$rfb->feed(substr($msg, 0, 10)); // header + partial rect header
		$this->assertNull($rfb->next());
		$rfb->feed(substr($msg, 10, 20)); // rect header done, partial payload
		$this->assertNull($rfb->next());
		$rfb->feed(substr($msg, 30));
		$decoded = $rfb->next();
		$this->assertSame('framebuffer_update', $decoded['type']);
		$this->assertSame(2, $decoded['rects'][0]['w']);
	}

	public function testUnknownEncodingThrows(): void
	{
		$rfb = new Rfb(4);
		$rfb->feed(chr(0) . "\x00" . pack('n', 1) . pack('nnnnN', 0, 0, 1, 1, 16)); // ZRLE
		$this->expectException(VCenterException::class);
		$this->expectExceptionMessageMatches('/16/');
		$rfb->next();
	}

	public function testUnknownMessageTypeThrows(): void
	{
		$rfb = new Rfb(4);
		$rfb->feed("\x7F");
		$this->expectException(VCenterException::class);
		$rfb->next();
	}

	public function testBellAndCutTextSkipped(): void
	{
		$rfb = new Rfb(4);
		$rfb->feed("\x02"); // Bell
		$rfb->feed("\x03\x00\x00\x00" . pack('N', 5) . 'hello');
		$this->assertSame('bell', $rfb->next()['type']);
		$cut = $rfb->next();
		$this->assertSame('server_cut_text', $cut['type']);
		$this->assertSame('hello', $cut['text']);
	}
}
