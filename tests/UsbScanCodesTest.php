<?php

use PHPUnit\Framework\TestCase;
use VCenter\UsbScanCodes;
use VCenter\VCenterException;

class UsbScanCodesTest extends TestCase
{
	private function hidOf(array $event): int
	{
		return ($event['usbHidCode'] & 0xFFFF0000) >> 16;
	}

	public function testLowercaseLetter(): void
	{
		$events = UsbScanCodes::fromText('a');
		$this->assertCount(1, $events);
		$this->assertSame(0x04, $this->hidOf($events[0]));
		$this->assertSame((0x04 << 16) | 0x0007, $events[0]['usbHidCode']);
		$this->assertSame([], $events[0]['modifiers']);
	}

	public function testUppercaseLetterShifted(): void
	{
		$e = UsbScanCodes::fromText('A')[0];
		$this->assertSame(0x04, $this->hidOf($e));
		$this->assertSame(['leftShift' => true], $e['modifiers']);
	}

	public function testShiftedSymbol(): void
	{
		$e = UsbScanCodes::fromText('!')[0];
		$this->assertSame(0x1E, $this->hidOf($e));
		$this->assertSame(['leftShift' => true], $e['modifiers']);
	}

	public function testEnterAndTabAndNewline(): void
	{
		$this->assertSame(0x28, $this->hidOf(UsbScanCodes::fromKeys(['enter'])[0]));
		$this->assertSame(0x28, $this->hidOf(UsbScanCodes::fromText("\n")[0]));
		$this->assertSame(0x2B, $this->hidOf(UsbScanCodes::fromText("\t")[0]));
		$this->assertSame(0x2C, $this->hidOf(UsbScanCodes::fromText(' ')[0]));
	}

	public function testCombos(): void
	{
		$e = UsbScanCodes::fromKeys(['ctrl-alt-del'])[0];
		$this->assertSame(0x4C, $this->hidOf($e));
		$this->assertTrue($e['modifiers']['leftControl']);
		$this->assertTrue($e['modifiers']['leftAlt']);

		$e = UsbScanCodes::fromKeys(['shift-tab'])[0];
		$this->assertSame(0x2B, $this->hidOf($e));
		$this->assertTrue($e['modifiers']['leftShift']);

		$e = UsbScanCodes::fromKeys(['ctrl+c'])[0];
		$this->assertSame(0x06, $this->hidOf($e));
		$this->assertTrue($e['modifiers']['leftControl']);
	}

	public function testFunctionKeysAndArrows(): void
	{
		$this->assertSame(0x3A, $this->hidOf(UsbScanCodes::fromKeys(['f1'])[0]));
		$this->assertSame(0x45, $this->hidOf(UsbScanCodes::fromKeys(['f12'])[0]));
		$this->assertSame(0x52, $this->hidOf(UsbScanCodes::fromKeys(['UP'])[0]));
	}

	public function testUnknownKeyThrows(): void
	{
		$this->expectException(VCenterException::class);
		UsbScanCodes::fromKeys(['hyper']);
	}

	public function testUnmappedCharThrows(): void
	{
		$this->expectException(VCenterException::class);
		UsbScanCodes::fromText('€');
	}

	public function testMixedTextOrder(): void
	{
		$events = UsbScanCodes::fromText("ab\n");
		$this->assertCount(3, $events);
		$this->assertSame(0x04, $this->hidOf($events[0]));
		$this->assertSame(0x05, $this->hidOf($events[1]));
		$this->assertSame(0x28, $this->hidOf($events[2]));
	}
}
