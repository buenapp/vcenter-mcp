#!/usr/bin/env php
<?php
/**
 * One door to curl: fails when curl is called outside its owner.
 *
 *   curl_multi_*()   only in EnchiladaMultiHTTP.class.php
 *   any other curl_*() only in EnchiladaCurl.class.php
 *
 * EnchiladaHTTP.class.php is exempt until it is rebuilt on EnchiladaCurl
 * (engineering-docs PLAN-ENCHILADA-CURL-SPLIT.md, decision 5).
 *
 * Calls are found with the tokenizer, so comments and strings never
 * match. Scans the directories given, e.g. an Extras checkout's HTTP/
 * and Tortilla's src/, or a consumer's vendored copies:
 *
 *   php lint_curl_door.php ../Extras/HTTP ../Tortilla/src
 *   php lint_curl_door.php libraries/EnchiladaCurl libraries/EnchiladaMultiHTTP \
 *       libraries/EnchiladaWorkerHTTP libraries/Enchilada/Tortilla
 */

const OWNERS = ['multi' => 'EnchiladaMultiHTTP.class.php', 'easy' => 'EnchiladaCurl.class.php'];
const EXEMPT = ['EnchiladaHTTP.class.php'];

/**
 * curl calls in PHP source that its file may not make.
 *
 * @return array<int,string> "line: function()" entries
 */
function violations(string $code, string $basename): array
{
	if (in_array($basename, EXEMPT, true)) {
		return [];
	}
	$tokens = array_values(array_filter(token_get_all($code),
		fn($t) => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
	$found = [];
	foreach ($tokens as $i => $t) {
		if (!is_array($t) || !in_array($t[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
			continue;
		}
		$name = strtolower(ltrim($t[1], '\\'));
		if (!str_starts_with($name, 'curl_') || ($tokens[$i + 1] ?? null) !== '(') {
			continue;
		}
		$prev = $tokens[$i - 1] ?? null;
		if (is_array($prev) && in_array($prev[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true)) {
			continue; // a method named curl_*, not the extension function
		}
		$owner = str_starts_with($name, 'curl_multi_') ? OWNERS['multi'] : OWNERS['easy'];
		if ($basename !== $owner) {
			$found[] = "{$t[2]}: {$name}() belongs in {$owner}";
		}
	}
	return $found;
}

$failed = 0;

// The rule itself, on known input
$probe = "<?php\n// curl_init() in a comment\n\$s = 'curl_exec()';\n\$h = curl_init();\n\\curl_setopt(\$h, 1, 2);\n"
	. "\$m = curl_multi_init();\n\$o->curl_exec();\nfunction curl_wrapper() {}\n";
$expect = [
	'Other.class.php' => ['4: curl_init() belongs in EnchiladaCurl.class.php', '5: curl_setopt() belongs in EnchiladaCurl.class.php',
		'6: curl_multi_init() belongs in EnchiladaMultiHTTP.class.php'],
	'EnchiladaCurl.class.php' => ['6: curl_multi_init() belongs in EnchiladaMultiHTTP.class.php'],
	'EnchiladaMultiHTTP.class.php' => ['4: curl_init() belongs in EnchiladaCurl.class.php', '5: curl_setopt() belongs in EnchiladaCurl.class.php'],
	'EnchiladaHTTP.class.php' => [],
];
foreach ($expect as $basename => $want) {
	$got = violations($probe, $basename);
	if ($got !== $want) {
		$failed++;
		echo "FAIL self-check for {$basename}: got " . json_encode($got) . "\n";
	}
}

$dirs = array_slice($argv, 1);
if ($dirs === []) {
	fwrite(STDERR, "usage: php lint_curl_door.php <dir> [<dir> ...]\n");
	exit(2);
}
$files = 0;
foreach ($dirs as $dir) {
	if (!is_dir($dir)) {
		$failed++;
		echo "FAIL not a directory: {$dir}\n";
		continue;
	}
	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
	foreach ($it as $file) {
		if ($file->getExtension() !== 'php') {
			continue;
		}
		$files++;
		foreach (violations(file_get_contents($file->getPathname()), $file->getFilename()) as $v) {
			$failed++;
			echo "FAIL {$file->getPathname()}:{$v}\n";
		}
	}
}

echo "Scanned {$files} files in " . implode(', ', $dirs) . "\n";
echo $failed === 0 ? "One door to curl: ok\n" : "One door to curl: {$failed} violation(s)\n";
exit($failed > 0 ? 1 : 0);
