<?php
/**
 * vCenter MCP Server — .netrc Parser
 *
 * Minimal ~/.netrc reader for credential lookup. Supports the classic
 * single-line and multi-line layouts plus the 'default' fallback entry;
 * 'macdef' blocks are skipped per netrc(5). Passwords are returned to
 * callers, never logged.
 *
 * @package    VCenterMCP\VCenter
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

namespace VCenter;

class Netrc
{
	/**
	 * Look up credentials for a host.
	 *
	 * @param  string      $host Hostname to match against 'machine' entries
	 * @param  string|null $path netrc file path (default ~/.netrc)
	 * @return array{login:string,password:string}|null Credentials, or null when absent
	 */
	public static function lookup(string $host, ?string $path = null): ?array
	{
		$entries = self::parse($path);
		foreach ($entries as $entry) {
			if ($entry['machine'] === $host) {
				return ['login' => $entry['login'] ?? '', 'password' => $entry['password'] ?? ''];
			}
		}
		foreach ($entries as $entry) {
			if ($entry['machine'] === 'default') {
				return ['login' => $entry['login'] ?? '', 'password' => $entry['password'] ?? ''];
			}
		}
		return null;
	}

	/**
	 * Parse a netrc file into a list of machine entries.
	 *
	 * @param  string|null $path File path (default ~/.netrc)
	 * @return array<int,array{machine:string,login?:string,password?:string,account?:string}>
	 */
	public static function parse(?string $path = null): array
	{
		if ($path === null) {
			$path = (getenv('HOME') ?: ($_SERVER['HOME'] ?? '')) . '/.netrc';
		}
		if (!is_file($path)) {
			return [];
		}
		$content = file_get_contents($path);
		if ($content === false) {
			return [];
		}

		// macdef blocks run until a blank line; strip them before tokenizing
		$lines = preg_split('/\r?\n/', $content);
		$clean = [];
		$inMacro = false;
		foreach ($lines as $line) {
			if ($inMacro) {
				if (trim($line) === '') {
					$inMacro = false;
				}
				continue;
			}
			if (preg_match('/^\s*macdef\b/', $line)) {
				$inMacro = true;
				continue;
			}
			$clean[] = $line;
		}

		$tokens = preg_split('/\s+/', implode("\n", $clean), -1, PREG_SPLIT_NO_EMPTY);
		$entries = [];
		$current = null;

		for ($i = 0, $n = count($tokens); $i < $n; $i++) {
			$token = $tokens[$i];
			if ($token === 'machine' || $token === 'default') {
				if ($current !== null) {
					$entries[] = $current;
				}
				$current = ['machine' => ($token === 'default') ? 'default' : ($tokens[++$i] ?? '')];
			} elseif ($current !== null && in_array($token, ['login', 'password', 'account'], true)) {
				$current[$token] = $tokens[++$i] ?? '';
			}
			// unknown keywords consume their value silently (netrc convention)
		}
		if ($current !== null) {
			$entries[] = $current;
		}

		return $entries;
	}
}
