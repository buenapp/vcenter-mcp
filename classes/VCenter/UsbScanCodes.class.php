<?php
/**
 * vCenter MCP Server — USB HID Scan Codes
 *
 * Text and key names -> UsbScanCodeSpec key events for PutUsbScanCodes.
 * US layout. usbHidCode = (hid << 16) | 0x0007; modifiers are the eight
 * UsbScanCodeSpecModifierType fields.
 *
 * @package    VCenterMCP\VCenter
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

namespace VCenter;

class UsbScanCodes
{
	/** Canonical modifier (from KeyNames) -> modifiers field. */
	private const MODIFIER_FIELDS = [
		'ctrl' => 'leftControl',
		'alt' => 'leftAlt',
		'shift' => 'leftShift',
		'gui' => 'leftGui',
	];

	/** Printable characters -> [hid, needsShift]. US layout. */
	private const CHARS = [
		'a' => [0x04, false], 'b' => [0x05, false], 'c' => [0x06, false], 'd' => [0x07, false],
		'e' => [0x08, false], 'f' => [0x09, false], 'g' => [0x0A, false], 'h' => [0x0B, false],
		'i' => [0x0C, false], 'j' => [0x0D, false], 'k' => [0x0E, false], 'l' => [0x0F, false],
		'm' => [0x10, false], 'n' => [0x11, false], 'o' => [0x12, false], 'p' => [0x13, false],
		'q' => [0x14, false], 'r' => [0x15, false], 's' => [0x16, false], 't' => [0x17, false],
		'u' => [0x18, false], 'v' => [0x19, false], 'w' => [0x1A, false], 'x' => [0x1B, false],
		'y' => [0x1C, false], 'z' => [0x1D, false],
		'1' => [0x1E, false], '2' => [0x1F, false], '3' => [0x20, false], '4' => [0x21, false],
		'5' => [0x22, false], '6' => [0x23, false], '7' => [0x24, false], '8' => [0x25, false],
		'9' => [0x26, false], '0' => [0x27, false],
		'!' => [0x1E, true], '@' => [0x1F, true], '#' => [0x20, true], '$' => [0x21, true],
		'%' => [0x22, true], '^' => [0x23, true], '&' => [0x24, true], '*' => [0x25, true],
		'(' => [0x26, true], ')' => [0x27, true],
		'-' => [0x2D, false], '=' => [0x2E, false], '[' => [0x2F, false], ']' => [0x30, false],
		'\\' => [0x31, false], ';' => [0x33, false], "'" => [0x34, false], '`' => [0x35, false],
		',' => [0x36, false], '.' => [0x37, false], '/' => [0x38, false],
		'_' => [0x2D, true], '+' => [0x2E, true], '{' => [0x2F, true], '}' => [0x30, true],
		'|' => [0x31, true], ':' => [0x33, true], '"' => [0x34, true], '~' => [0x35, true],
		'<' => [0x36, true], '>' => [0x37, true], '?' => [0x38, true],
	];

	/** Canonical key names (from KeyNames) -> hid. */
	private const KEYS = [
		'enter' => 0x28, 'esc' => 0x29,
		'backspace' => 0x2A, 'tab' => 0x2B, 'space' => 0x2C,
		'capslock' => 0x39,
		'f1' => 0x3A, 'f2' => 0x3B, 'f3' => 0x3C, 'f4' => 0x3D, 'f5' => 0x3E, 'f6' => 0x3F,
		'f7' => 0x40, 'f8' => 0x41, 'f9' => 0x42, 'f10' => 0x43, 'f11' => 0x44, 'f12' => 0x45,
		'printscreen' => 0x46, 'scrolllock' => 0x47, 'pause' => 0x48,
		'insert' => 0x49, 'home' => 0x4A, 'pageup' => 0x4B,
		'delete' => 0x4C, 'end' => 0x4D, 'pagedown' => 0x4E,
		'right' => 0x4F, 'left' => 0x50, 'down' => 0x51, 'up' => 0x52,
	];

	/**
	 * Literal text -> key events. '\n' = Enter, '\t' = Tab; uppercase
	 * letters and shifted symbols get leftShift.
	 *
	 * @return array<int,array{usbHidCode:int,modifiers:array}>
	 * @throws VCenterException On a character with no mapping
	 */
	public static function fromText(string $text): array
	{
		$events = [];
		foreach (str_split($text) as $ch) {
			if ($ch === "\n") {
				$events[] = self::event(0x28);
				continue;
			}
			if ($ch === "\t") {
				$events[] = self::event(0x2B);
				continue;
			}
			if ($ch === ' ') {
				$events[] = self::event(0x2C);
				continue;
			}
			$lower = strtolower($ch);
			if (isset(self::CHARS[$lower])) {
				[$hid, $shift] = self::CHARS[$lower];
				if ($ch !== $lower) {
					$shift = true;
				}
				$events[] = self::event($hid, $shift);
				continue;
			}
			throw new VCenterException("No USB HID mapping for character " . json_encode($ch), 0, 'InvalidKey');
		}
		return $events;
	}

	/**
	 * Key names -> key events. Accepts single names ('enter', 'f2',
	 * 'delete') and combos joined with '-' or '+' ('ctrl-c',
	 * 'ctrl-alt-del', 'shift-tab'). Case-insensitive.
	 *
	 * @param  string[] $keys
	 * @return array<int,array{usbHidCode:int,modifiers:array}>
	 * @throws VCenterException On an unknown key name
	 */
	public static function fromKeys(array $keys): array
	{
		$events = [];
		foreach ($keys as $combo) {
			$events[] = self::comboEvent($combo);
		}
		return $events;
	}

	/**
	 * One key name or combo -> a single key event.
	 *
	 * @throws VCenterException
	 */
	private static function comboEvent(string $combo): array
	{
		$parsed = KeyNames::parseCombo($combo);

		$modifiers = [];
		foreach ($parsed['modifiers'] as $mod => $_) {
			$modifiers[self::MODIFIER_FIELDS[$mod]] = true;
		}

		$key = $parsed['key'];
		if (strlen($key) === 1) {
			if (!isset(self::CHARS[$key])) {
				throw new VCenterException("No USB HID mapping for key '{$key}' in '{$combo}'", 0, 'InvalidKey');
			}
			[$hid, $shift] = self::CHARS[$key];
			if ($shift) {
				$modifiers['leftShift'] = true;
			}
		} else {
			$hid = self::KEYS[$key];
		}

		return ['usbHidCode' => ($hid << 16) | 0x0007, 'modifiers' => $modifiers];
	}

	private static function event(int $hid, bool $shift = false): array
	{
		return [
			'usbHidCode' => ($hid << 16) | 0x0007,
			'modifiers' => $shift ? ['leftShift' => true] : [],
		];
	}
}
