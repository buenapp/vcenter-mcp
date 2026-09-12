<?php

use PHPUnit\Framework\TestCase;
use VCenter\Console\Keysyms;
use VCenter\VCenterException;

class KeysymsTest extends TestCase
{
	/** Flatten strokes to a list of [down, keysym]. */
	private function flat(array $strokes): array
	{
		$out = [];
		foreach ($strokes as $stroke) {
			foreach ($stroke as $ev) {
				$out[] = [$ev['down'], $ev['keysym']];
			}
		}
		return $out;
	}

	public function testLowercaseLetter(): void
	{
		$this->assertSame([[true, 0x61], [false, 0x61]], $this->flat(Keysyms::fromText('a')));
	}

	public function testUppercaseLetterUsesShift(): void
	{
		$this->assertSame(
			[[true, 0xFFE1], [true, 0x41], [false, 0x41], [false, 0xFFE1]],
			$this->flat(Keysyms::fromText('A'))
		);
	}

	public function testShiftedSymbolUsesShift(): void
	{
		$this->assertSame(
			[[true, 0xFFE1], [true, 0x21], [false, 0x21], [false, 0xFFE1]],
			$this->flat(Keysyms::fromText('!'))
		);
	}

	public function testEnterAndTabInText(): void
	{
		$this->assertSame(
			[[true, 0xFF0D], [false, 0xFF0D], [true, 0xFF09], [false, 0xFF09]],
			$this->flat(Keysyms::fromText("\n\t"))
		);
	}

	public function testNamedKeys(): void
	{
		$this->assertSame([[true, 0xFF0D], [false, 0xFF0D]], $this->flat(Keysyms::fromKeys(['enter'])));
		$this->assertSame([[true, 0xFF0D], [false, 0xFF0D]], $this->flat(Keysyms::fromKeys(['return'])));
		$this->assertSame([[true, 0xFFFF], [false, 0xFFFF]], $this->flat(Keysyms::fromKeys(['delete'])));
		$this->assertSame([[true, 0xFFFF], [false, 0xFFFF]], $this->flat(Keysyms::fromKeys(['del'])));
		$this->assertSame([[true, 0xFF52], [false, 0xFF52]], $this->flat(Keysyms::fromKeys(['up'])));
		$this->assertSame([[true, 0xFFC9], [false, 0xFFC9]], $this->flat(Keysyms::fromKeys(['F12'])));
	}

	public function testCtrlAltDelOrdering(): void
	{
		$this->assertSame(
			[
				[true, 0xFFE3],  // ctrl down
				[true, 0xFFE9],  // alt down
				[true, 0xFFFF],  // del down
				[false, 0xFFFF], // del up
				[false, 0xFFE9], // alt up (reverse order)
				[false, 0xFFE3], // ctrl up
			],
			$this->flat(Keysyms::fromKeys(['ctrl-alt-del']))
		);
	}

	public function testShiftTab(): void
	{
		$this->assertSame(
			[[true, 0xFFE1], [true, 0xFF09], [false, 0xFF09], [false, 0xFFE1]],
			$this->flat(Keysyms::fromKeys(['shift+tab']))
		);
	}

	public function testUnknownKeyThrows(): void
	{
		$this->expectException(VCenterException::class);
		Keysyms::fromKeys(['ctrl-bogus']);
	}

	public function testUnmappableCharThrows(): void
	{
		$this->expectException(VCenterException::class);
		Keysyms::fromText("\x01");
	}
}
