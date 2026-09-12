<?php

use PHPUnit\Framework\TestCase;

/**
 * Vendored-library layout check: every vendored file sits at the path the
 * Enchilada autoloader resolves for its class/namespace, and (when the
 * canonical checkouts exist on this machine) is byte-identical to the
 * canonical source.
 */
class VendoredLibraryLayoutTest extends TestCase
{
	private const ROOT = __DIR__ . '/..';

	public function testVendoredFilesExist(): void
	{
		$required = [
			'system/autoload.inc.php',
			'system/bootstrap.inc.php',
			'system/app.conf.php',
			'libraries/EnchiladaMCP/McpServer.class.php',
			'libraries/EnchiladaMCP/McpTool.class.php',
			'libraries/EnchiladaMCP/ToolRegistry.class.php',
			'libraries/EnchiladaMCP/ToolResult.class.php',
			'libraries/EnchiladaMCP/Logger.class.php',
			'libraries/EnchiladaMCP/InstanceRegistry.class.php',
			'libraries/EnchiladaHTTP/EnchiladaHTTP.class.php',
			'libraries/EnchiladaMultiHTTP/EnchiladaMultiHTTP.class.php',
			'libraries/Enchilada/Dns/Resolver.class.php',
			'libraries/Enchilada/Dns/Exception.class.php',
			'libraries/Enchilada/Tortilla/HttpClient.php',
			'libraries/Enchilada/Tortilla/StdioTransport.php',
			'libraries/Enchilada/Tortilla/EventLoop.php',
			'libraries/Enchilada/Tortilla/ComalEventLoop.php',
			'libraries/Enchilada/Comal/ReactorFactory.php',
			'libraries/EnchiladaWebSocket/WebSocketClient.class.php',
		];
		foreach ($required as $path) {
			$this->assertFileExists(self::ROOT . '/' . $path, $path);
		}
	}

	/**
	 * Namespaced classes must sit at libraries/<Namespace>/<Class>.<suffix>;
	 * legacy global classes at eponymous libraries/<Class>/<Class>.class.php.
	 */
	public function testNamespacesMatchPaths(): void
	{
		$expect = [
			'libraries/EnchiladaMCP' => 'EnchiladaMCP',
			'libraries/Enchilada/Dns' => 'Enchilada\\Dns',
			'libraries/Enchilada/Tortilla' => 'Enchilada\\Tortilla',
			'libraries/Enchilada/Comal' => 'Enchilada\\Comal',
			'libraries/EnchiladaWebSocket' => 'EnchiladaWebSocket',
		];
		foreach ($expect as $dir => $namespace) {
			foreach (glob(self::ROOT . '/' . $dir . '/*.php') as $file) {
				$src = file_get_contents($file);
				$this->assertMatchesRegularExpression(
					'/^namespace ' . preg_quote($namespace, '/') . '(\\\\|;)/m',
					$src,
					"{$file} must declare namespace {$namespace}"
				);
			}
		}
		foreach (['libraries/EnchiladaHTTP', 'libraries/EnchiladaMultiHTTP'] as $dir) {
			foreach (glob(self::ROOT . '/' . $dir . '/*.php') as $file) {
				$this->assertStringNotContainsString('namespace ', file_get_contents($file),
					"{$file} is a legacy global-namespace class");
			}
		}
	}

	public function testByteIdenticalToCanonicalSources(): void
	{
		// Canonical checkouts live under $ENCHILADA_CODE_ROOT (default
		// ~/Documents/Code on the dev machine).
		$code = getenv('ENCHILADA_CODE_ROOT') ?: (getenv('HOME') . '/Documents/Code');
		$framework = $code . '/EnchiladaFramework';
		$extras = $code . '/EnchiladaExtras';
		$tortilla = $code . '/Tortilla';
		$comal = $code . '/Comal';

		if (!array_filter([$framework, $extras, $tortilla, $comal], 'is_dir')) {
			$this->markTestSkipped('canonical Enchilada checkouts not present on this host');
		}

		$pairs = [
			"$framework/system/autoload.inc.php" => 'system/autoload.inc.php',
			"$framework/system/bootstrap.inc.php" => 'system/bootstrap.inc.php',
		];
		foreach (glob("$extras/MCP/*.php") ?: [] as $src) {
			$pairs[$src] = 'libraries/EnchiladaMCP/' . basename($src);
		}
		foreach (glob("$extras/Dns/*.php") ?: [] as $src) {
			$pairs[$src] = 'libraries/Enchilada/Dns/' . basename($src);
		}
		foreach (glob("$extras/WebSocket/*.php") ?: [] as $src) {
			$pairs[$src] = 'libraries/EnchiladaWebSocket/' . basename($src);
		}
		$pairs["$extras/HTTP/EnchiladaHTTP.class.php"] = 'libraries/EnchiladaHTTP/EnchiladaHTTP.class.php';
		$pairs["$extras/HTTP/EnchiladaMultiHTTP.class.php"] = 'libraries/EnchiladaMultiHTTP/EnchiladaMultiHTTP.class.php';
		foreach (glob("$tortilla/src/*.php") ?: [] as $src) {
			// HttpClient is vendored from Tortilla branch
			// feat/http-response-headers (getLastResponseHeaders pending
			// upstream merge).
			if (basename($src) === 'HttpClient.php') {
				$out = [];
				@exec('git -C ' . escapeshellarg($tortilla) . ' show feat/http-response-headers:src/HttpClient.php', $out);
				if (!empty($out)) {
					$pairs["git:$tortilla#feat/http-response-headers:src/HttpClient.php"]
						= 'libraries/Enchilada/Tortilla/HttpClient.php';
					continue;
				}
			}
			$pairs[$src] = 'libraries/Enchilada/Tortilla/' . basename($src);
		}
		foreach (glob("$comal/src/*.php") ?: [] as $src) {
			$pairs[$src] = 'libraries/Enchilada/Comal/' . basename($src);
		}

		$checked = 0;
		foreach ($pairs as $src => $local) {
			if (str_starts_with($src, 'git:')) {
				[$repo, $ref] = explode('#', substr($src, 4), 2);
				// shell_exec keeps trailing whitespace; exec() strips it per line
				$canonical = shell_exec('git -C ' . escapeshellarg($repo) . ' show ' . escapeshellarg($ref));
			} elseif (is_file($src)) {
				$canonical = file_get_contents($src);
			} else {
				continue; // canonical checkout absent — layout checks still apply
			}
			$this->assertFileExists(self::ROOT . '/' . $local, $local);
			$this->assertSame(
				$canonical,
				file_get_contents(self::ROOT . '/' . $local),
				"{$local} differs from canonical {$src} — re-vendor, never patch"
			);
			$checked++;
		}
		$this->assertGreaterThan(0, $checked);
	}
}
