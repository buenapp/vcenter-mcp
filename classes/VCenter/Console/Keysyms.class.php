<?php
/**
 * vCenter MCP Server — X11 Keysyms
 *
 * Text and key names -> RFB KeyEvent sequences for the WebMKS console.
 * Same key-name vocabulary as UsbScanCodes (shared KeyNames parser).
 * Each "stroke" is a list of ['down' => bool, 'keysym' => int] events;
 * combos press modifiers first and release them in reverse.
 *
 * @package    VCenterMCP\VCenter\Console
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

namespace VCenter\Console;

use VCenter\KeyNames;
use VCenter\VCenterException;

class Keysyms
{
	public const SHIFT = 0xFFE1;   // Shift_L
	public const CTRL = 0xFFE3;    // Control_L
	public const ALT = 0xFFE9;     // Alt_L
	public const GUI = 0xFFEB;     // Super_L

	/** Canonical modifier (from KeyNames) -> keysym, press order. */
	private const MODIFIERS = [
		'ctrl' => self::CTRL,
		'alt' => self::ALT,
		'shift' => self::SHIFT,
		'gui' => self::GUI,
	];

	/** Canonical key names (from KeyNames) -> keysym. */
	private const KEYS = [
		'enter' => 0xFF0D, 'tab' => 0xFF09, 'esc' => 0xFF1B,
		'backspace' => 0xFF08, 'space' => 0x20,
		'delete' => 0xFFFF, 'insert' => 0xFF63,
		'home' => 0xFF50, 'end' => 0xFF57,
		'pageup' => 0xFF55, 'pagedown' => 0xFF56,
		'left' => 0xFF51, 'up' => 0xFF52, 'right' => 0xFF53, 'down' => 0xFF54,
		'f1' => 0xFFBE, 'f2' => 0xFFBF, 'f3' => 0xFFC0, 'f4' => 0xFFC1,
		'f5' => 0xFFC2, 'f6' => 0xFFC3, 'f7' => 0xFFC4, 'f8' => 0xFFC5,
		'f9' => 0xFFC6, 'f10' => 0xFFC7, 'f11' => 0xFFC8, 'f12' => 0xFFC9,
		'capslock' => 0xFFE5, 'printscreen' => 0xFF61,
		'scrolllock' => 0xFF14, 'pause' => 0xFF13,
	];

	/** Characters needing Shift on a US layout. */
	private const SHIFTED = '!@#$%^&*()_+{}|:"~<>?';

	/**
	 * Literal text -> strokes. '\n' = Enter, '\t' = Tab; uppercase
	 * letters and shifted symbols are wrapped in Shift_L down/up.
	 *
	 * @return array<int,array<int,array{down:bool,keysym:int}>>
	 * @throws VCenterException On a character with no mapping
	 */
	public static function fromText(string $text): array
	{
		$strokes = [];
		foreach (str_split($text) as $ch) {
			if ($ch === "\n") {
				$strokes[] = self::stroke(self::KEYS['enter']);
				continue;
			}
			if ($ch === "\t") {
				$strokes[] = self::stroke(self::KEYS['tab']);
				continue;
			}
			$strokes[] = self::charStroke($ch);
		}
		return $strokes;
	}

	/**
	 * Key names/combos -> strokes ('enter', 'f2', 'ctrl-c',
	 * 'ctrl-alt-del', 'shift-tab'; case-insensitive).
	 *
	 * @param  string[] $keys
	 * @return array<int,array<int,array{down:bool,keysym:int}>>
	 * @throws VCenterException On an unknown key name
	 */
	public static function fromKeys(array $keys): array
	{
		$strokes = [];
		foreach ($keys as $combo) {
			$parsed = KeyNames::parseCombo($combo);

			$events = [];
			$pressed = [];
			foreach ($parsed['modifiers'] as $mod => $_) {
				$events[] = ['down' => true, 'keysym' => self::MODIFIERS[$mod]];
				$pressed[] = self::MODIFIERS[$mod];
			}

			$stroke = self::keyStroke($parsed['key'], $combo);
			$shifted = $stroke[0]['shift'] ?? false;
			if ($shifted) {
				$events[] = ['down' => true, 'keysym' => self::SHIFT];
				$pressed[] = self::SHIFT;
			}
			$events[] = ['down' => true, 'keysym' => $stroke[0]['keysym']];
			$events[] = ['down' => false, 'keysym' => $stroke[0]['keysym']];
			foreach (array_reverse($pressed) as $sym) {
				$events[] = ['down' => false, 'keysym' => $sym];
			}
			$strokes[] = $events;
		}
		return $strokes;
	}

	/** One character -> stroke, honouring Shift. */
	private static function charStroke(string $ch): array
	{
		$o = ord($ch);
		if ($o < 0x20 || $o > 0x7E) {
			throw new VCenterException("No keysym mapping for character " . json_encode($ch), 0, 'InvalidKey');
		}
		if (ctype_upper($ch) || str_contains(self::SHIFTED, $ch)) {
			return [
				['down' => true, 'keysym' => self::SHIFT],
				['down' => true, 'keysym' => $o],
				['down' => false, 'keysym' => $o],
				['down' => false, 'keysym' => self::SHIFT],
			];
		}
		return self::stroke($o);
	}

	private static function stroke(int $keysym): array
	{
		return [
			['down' => true, 'keysym' => $keysym],
			['down' => false, 'keysym' => $keysym],
		];
	}

	/**
	 * Key portion of a parsed combo -> ['keysym' => int, 'shift' => bool].
	 *
	 * @throws VCenterException
	 */
	private static function keyStroke(string $key, string $combo): array
	{
		if (strlen($key) === 1) {
			$o = ord($key);
			if ($o < 0x20 || $o > 0x7E) {
				throw new VCenterException("No keysym mapping for key '{$key}' in '{$combo}'", 0, 'InvalidKey');
			}
			return [['keysym' => $o, 'shift' => ctype_upper($key) || str_contains(self::SHIFTED, $key)]];
		}
		return [['keysym' => self::KEYS[$key], 'shift' => false]];
	}
}
