<?php
/**
 * vCenter MCP Server — Key Name Parser
 *
 * Shared parsing of key names and combos ('ctrl-c', 'ctrl-alt-del',
 * 'shift-tab') for the console paths: UsbScanCodes (USB HID) and
 * Console\Keysyms (X11 keysyms) map the parsed result onto their own
 * code tables.
 *
 * @package    VCenterMCP\VCenter
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

namespace VCenter;

class KeyNames
{
	/** Modifier alias -> canonical modifier name. */
	public const MODIFIERS = [
		'ctrl' => 'ctrl', 'control' => 'ctrl',
		'alt' => 'alt',
		'shift' => 'shift',
		'meta' => 'gui', 'win' => 'gui', 'gui' => 'gui',
	];

	/** Key name alias -> canonical key name. */
	public const ALIASES = [
		'return' => 'enter', 'escape' => 'esc', 'del' => 'delete',
	];

	/** Canonical (non-character) key names accepted in combos. */
	public const NAMED = [
		'enter', 'esc', 'backspace', 'tab', 'space', 'capslock',
		'f1', 'f2', 'f3', 'f4', 'f5', 'f6', 'f7', 'f8', 'f9', 'f10', 'f11', 'f12',
		'printscreen', 'scrolllock', 'pause',
		'insert', 'home', 'pageup', 'delete', 'end', 'pagedown',
		'right', 'left', 'down', 'up',
	];

	/**
	 * Parse a key name or combo ('ctrl-c', 'ctrl-alt-del', 'shift-tab';
	 * '-' or '+' separated, case-insensitive).
	 *
	 * @return array{modifiers: array<string,bool>, key: string}
	 *         modifiers: canonical names ctrl/alt/shift/gui => true;
	 *         key: canonical key name, or a single literal character
	 * @throws VCenterException On an unknown part, more than one key,
	 *         or a combo of modifiers only
	 */
	public static function parseCombo(string $combo): array
	{
		$parts = preg_split('/[-+]/', strtolower(trim($combo)), -1, PREG_SPLIT_NO_EMPTY);
		if ($parts === false || $parts === []) {
			throw new VCenterException("Empty key name '{$combo}'", 0, 'InvalidKey');
		}

		$modifiers = [];
		$key = null;
		foreach ($parts as $part) {
			if (isset(self::MODIFIERS[$part])) {
				$modifiers[self::MODIFIERS[$part]] = true;
				continue;
			}
			$canonical = self::ALIASES[$part] ?? $part;
			if (in_array($canonical, self::NAMED, true) || strlen($part) === 1) {
				if ($key !== null) {
					throw new VCenterException("Combo '{$combo}' has more than one key", 0, 'InvalidKey');
				}
				$key = $canonical;
				continue;
			}
			throw new VCenterException("Unknown key '{$part}' in '{$combo}'", 0, 'InvalidKey');
		}
		if ($key === null) {
			throw new VCenterException("Combo '{$combo}' names no key (only modifiers)", 0, 'InvalidKey');
		}

		return ['modifiers' => $modifiers, 'key' => $key];
	}
}
