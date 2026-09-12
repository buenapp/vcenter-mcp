#!/usr/bin/env php
<?php
/**
 * vCenter MCP Server — PHAR Builder
 *
 * Builds a self-contained .phar archive for distribution.
 *
 * Usage: php -d phar.readonly=0 bin/build-phar.php
 *
 * @package    VCenterMCP
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

if (ini_get('phar.readonly')) {
	echo "Error: phar.readonly must be disabled. Run with:\n";
	echo "  php -d phar.readonly=0 bin/build-phar.php\n";
	exit(1);
}

// ── Configuration ──────────────────────────────────────────────

$baseDir    = dirname(__DIR__);
$pharName   = 'vcenter-mcp.phar';
$pharPath   = $baseDir . '/' . $pharName;
$entryPoint = 'bin/vcenter-mcp';

// Directories to include in the PHAR
$includeDirs = ['system', 'classes', 'libraries', 'tools'];

// Extra files to include (relative to base)
$extraFiles = [
	'config/instances.json.sample',
	'config/instructions.txt',
];

// ── Build ──────────────────────────────────────────────────────

if (file_exists($pharPath)) {
	unlink($pharPath);
}

echo "Building {$pharName}...\n";

$phar = new Phar($pharPath);
$phar->startBuffering();

// Add source directories
$fileCount = 0;
foreach ($includeDirs as $dir) {
	$fullDir = $baseDir . '/' . $dir;
	if (!is_dir($fullDir)) continue;
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($fullDir, FilesystemIterator::SKIP_DOTS)
	);
	foreach ($iterator as $file) {
		$ext = $file->getExtension();
		if (in_array($ext, ['php', 'inc', 'txt'])) {
			$localPath = $dir . '/' . $iterator->getSubPathname();
			$phar->addFile($file->getPathname(), $localPath);
			$fileCount++;
		}
	}
}

// Add entry point (strip shebang so require_once doesn't output it)
$entrySource = file_get_contents($baseDir . '/' . $entryPoint);
if (str_starts_with($entrySource, '#!')) {
	$entrySource = substr($entrySource, strpos($entrySource, "\n") + 1);
}
$phar->addFromString($entryPoint, $entrySource);
$fileCount++;

// Add extra files
foreach ($extraFiles as $f) {
	$fullPath = $baseDir . '/' . $f;
	if (file_exists($fullPath)) {
		$phar->addFile($fullPath, $f);
		$fileCount++;
	}
}

// ── Read app.conf.php to extract version ─────────────────────────

$appConf = file_get_contents($baseDir . '/system/app.conf.php');
preg_match("/define\('APPLICATION_VERSION',\s*'([^']+)'\)/", $appConf, $vMatch);
$version = $vMatch[1] ?? 'unknown';

// ── Generate Stub ──────────────────────────────────────────────

$stub = <<<STUB
#!/usr/bin/env php
<?php
Phar::mapPhar('{$pharName}');
require 'phar://{$pharName}/{$entryPoint}';
__HALT_COMPILER();
STUB;

$phar->setStub($stub);
$phar->stopBuffering();

// Make executable
chmod($pharPath, 0755);

$size = round(filesize($pharPath) / 1024, 1);
echo "Built: {$pharPath} ({$size} KB, {$fileCount} files)\n";
echo "Version: {$version}\n";
echo "Test:  php {$pharName} --config=/path/to/instances.json\n";
