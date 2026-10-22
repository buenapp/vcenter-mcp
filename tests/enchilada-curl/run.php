#!/usr/bin/env php
<?php
/**
 * curl behaviour suite: every executor must give the same outcome.
 *
 * Each case runs through every executor built on EnchiladaCurl:
 *
 *   multi+tick    EnchiladaMultiHTTP, queue() + tick()
 *   perform       EnchiladaCurl::perform() + decodeTransport()
 *   worker+wait   EnchiladaWorkerHTTP, queue() + wait()
 *   worker+hooks  EnchiladaWorkerHTTP driven by a Comal reactor through
 *                 setWatchHooks() / handleReadable()
 *
 * and the getResult() outcomes must be byte-identical. Two values are
 * normalized first because they differ between any two requests: the
 * Date response header, and elapsed-time numbers inside curl error
 * text. Streamed chunk boundaries are not compared (they depend on the
 * network); the chunks must reassemble the body exactly.
 *
 * The worker executors run where EnchiladaWorkerHTTP::supported() (POSIX);
 * on Windows the suite asserts that workers report unsupported. The
 * hooks executor needs Comal: from --comal=DIR, or Enchilada/Comal/
 * under --lib.
 *
 * Usage:
 *   php run.php --lib=path/to/libraries                  # a consumer's vendored copies
 *   php run.php --extras=path/to/Extras --comal=path/to/Comal/src   # an Extras checkout
 */

if (!defined('APPLICATION_DEBUG')) { define('APPLICATION_DEBUG', false); }
if (!defined('APPLICATION_USERAGENT')) { define('APPLICATION_USERAGENT', 'enchilada-curl-behaviour/1.0'); }

$opts = getopt('', ['lib:', 'extras:', 'comal:']);
$lib = $opts['lib'] ?? null;
$extras = $opts['extras'] ?? null;
if (($lib === null) === ($extras === null)) {
	fwrite(STDERR, "usage: php run.php --lib=<consumer libraries/> | --extras=<Extras checkout> [--comal=<Comal src/>]\n");
	exit(2);
}
$classFile = function (string $class) use ($lib, $extras): string {
	$file = $lib !== null
		? rtrim($lib, '/\\') . "/{$class}/{$class}.class.php"
		: rtrim($extras, '/\\') . "/HTTP/{$class}.class.php";
	if (!is_file($file)) {
		fwrite(STDERR, "missing {$file}\n");
		exit(2);
	}
	return $file;
};
foreach (['EnchiladaCurl', 'EnchiladaMultiHTTP', 'EnchiladaWorkerHTTP'] as $class) {
	require_once $classFile($class);
}

$comalDir = $opts['comal'] ?? ($lib !== null && is_dir("{$lib}/Enchilada/Comal") ? "{$lib}/Enchilada/Comal" : null);
if ($comalDir !== null) {
	spl_autoload_register(function (string $class) use ($comalDir): void {
		if (str_starts_with($class, 'Enchilada\\Comal\\')) {
			$file = rtrim($comalDir, '/\\') . '/' . substr($class, strlen('Enchilada\\Comal\\')) . '.php';
			if (is_file($file)) {
				require_once $file;
			}
		}
	});
}

$passed = 0;
$failed = 0;
function check(string $label, bool $cond, string $detail = ''): void
{
	global $passed, $failed;
	if ($cond) { $passed++; echo "  ok   {$label}\n"; }
	else { $failed++; echo "  FAIL {$label}" . ($detail !== '' ? " -- {$detail}" : '') . "\n"; }
}

$windows = PHP_OS_FAMILY === 'Windows';
$devNull = $windows ? 'NUL' : '/dev/null';

// ---------------------------------------------------------------------
// Fixture servers
// ---------------------------------------------------------------------
function freePort(): int
{
	$sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
	if ($sock === false) {
		fwrite(STDERR, "cannot reserve a port: {$errstr}\n");
		exit(2);
	}
	$name = stream_socket_get_name($sock, false);
	fclose($sock);
	return (int) substr($name, strrpos($name, ':') + 1);
}

function awaitConnect(string $address): bool
{
	for ($i = 0; $i < 100; $i++) {
		$c = @stream_socket_client($address, $errno, $errstr, 1);
		if ($c !== false) {
			fclose($c);
			return true;
		}
		usleep(50000);
	}
	return false;
}

$port = freePort();
$env = getenv();
if (!$windows) {
	$env['PHP_CLI_SERVER_WORKERS'] = '8';
}
$procs = [];
$procs[] = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . '/router.php'],
	[0 => ['file', $devNull, 'r'], 1 => ['file', $devNull, 'a'], 2 => ['file', $devNull, 'a']], $pipes, null, $env);
if (!awaitConnect("tcp://127.0.0.1:{$port}")) {
	fwrite(STDERR, "fixture server did not start on port {$port}\n");
	exit(2);
}
$base = "http://127.0.0.1:{$port}";

$unixSocket = null;
if (!$windows) {
	$unixSocket = sys_get_temp_dir() . '/enchilada-curl-' . getmypid() . '.sock';
	$procs[] = proc_open([PHP_BINARY, __DIR__ . '/unix-server.php', $unixSocket],
		[0 => ['file', $devNull, 'r'], 1 => ['file', $devNull, 'a'], 2 => ['file', $devNull, 'a']], $pipes);
	if (!awaitConnect("unix://{$unixSocket}")) {
		fwrite(STDERR, "unix fixture did not start on {$unixSocket}\n");
		exit(2);
	}
}

register_shutdown_function(function () use (&$procs, $unixSocket) {
	foreach ($procs as $proc) {
		if (is_resource($proc)) {
			proc_terminate($proc);
			proc_close($proc);
		}
	}
	$procs = [];
	if ($unixSocket !== null && file_exists($unixSocket)) {
		unlink($unixSocket);
	}
});

// ---------------------------------------------------------------------
// Executors: each takes a configured client factory and a request, and
// returns [outcome, chunks, seconds]
// ---------------------------------------------------------------------
$workers = EnchiladaWorkerHTTP::supported();
$reactor = null;
if ($workers && $comalDir !== null && class_exists('Enchilada\\Comal\\ReactorFactory')) {
	$reactor = \Enchilada\Comal\ReactorFactory::create();
}

/** @var array<string,EnchiladaWorkerHTTP> One worker client per executor and setup, reused across cases */
$workerClients = [];

$executors = [];
$executors['multi+tick'] = function (callable $setup, array $req) use ($base): array {
	$client = $setup(new EnchiladaMultiHTTP($req['endpoint'] ?? $base));
	$chunks = [];
	$t = microtime(true);
	$id = $client->queue(...requestArgs($req, $chunks));
	while (($outcome = $client->getResult($id)) === null) {
		$client->tick();
	}
	return [$outcome, $chunks, microtime(true) - $t, $client, $id ?? null];
};
$executors['perform'] = function (callable $setup, array $req) use ($base): array {
	$client = $setup(new EnchiladaMultiHTTP($req['endpoint'] ?? $base));
	$chunks = [];
	$t = microtime(true);
	$args = requestArgs($req, $chunks);
	$writeCallback = array_pop($args);
	$spec = $client->buildSpec(...$args);
	$outcome = EnchiladaCurl::decodeTransport($spec['format'], $client->perform($spec, $writeCallback));
	return [$outcome, $chunks, microtime(true) - $t, $client, $id ?? null];
};
if ($workers) {
	$executors['worker+wait'] = function (callable $setup, array $req) use ($base): array {
		$client = $setup(new EnchiladaWorkerHTTP($req['endpoint'] ?? $base));
		$chunks = [];
		$t = microtime(true);
		$id = $client->queue(...requestArgs($req, $chunks));
		$client->wait($id);
		$outcome = $client->getResult($id);
		$client->shutdown();
		return [$outcome, $chunks, microtime(true) - $t, $client, $id ?? null];
	};
	if ($reactor !== null) {
		$executors['worker+hooks'] = function (callable $setup, array $req) use ($base, $reactor): array {
			$client = $setup(new EnchiladaWorkerHTTP($req['endpoint'] ?? $base));
			$watchers = [];
			$client->setWatchHooks(
				function ($stream) use ($reactor, $client, &$watchers, &$id) {
					$watchers[get_resource_id($stream)] = $reactor->onReadable($stream, function ($s) use ($reactor, $client, &$id) {
						if (in_array($id, $client->handleReadable($s), true)) {
							$reactor->stop();
						}
					});
				},
				function ($stream) use ($reactor, &$watchers) {
					$rid = get_resource_id($stream);
					if (isset($watchers[$rid])) {
						$reactor->cancel($watchers[$rid]);
						unset($watchers[$rid]);
					}
				}
			);
			$chunks = [];
			$id = null;
			$t = microtime(true);
			$id = $client->queue(...requestArgs($req, $chunks));
			$reactor->run();
			$outcome = $client->getResult($id);
			$elapsed = microtime(true) - $t;
			if ($watchers !== []) {
				$outcome['__still_watched'] = count($watchers);
			}
			$client->shutdown();
			return [$outcome, $chunks, $elapsed, $client, $id];
		};
	}
}

/**
 * queue() arguments for a request; the write callback (last) collects
 * chunks when the request streams.
 */
function requestArgs(array $req, array &$chunks): array
{
	$writeCallback = !empty($req['stream'])
		? function (string $chunk) use (&$chunks) { $chunks[] = $chunk; }
		: null;
	return [$req['path'], $req['data'] ?? null, $req['verb'] ?? 'GET', $req['headers'] ?? [],
		$req['timeout'] ?? null, $req['format'] ?? 'json', $writeCallback];
}

function normalize(array $outcome): array
{
	unset($outcome['headers']['date']);
	foreach (['curl_error', 'error'] as $key) {
		if (is_string($outcome[$key] ?? null)) {
			$outcome[$key] = preg_replace('/\d+/', 'N', $outcome[$key]);
		}
	}
	$outcome['raw'] = base64_encode((string) $outcome['raw']);
	if (is_string($outcome['result'])) {
		$outcome['result'] = base64_encode($outcome['result']);
	}
	return $outcome;
}

// ---------------------------------------------------------------------
// Cases
// ---------------------------------------------------------------------
$binary = random_bytes(150000);
$none = fn($c) => $c;

$cases = [
	'JSON body' => [
		'req' => ['path' => 'json'],
		'expect' => fn($o) => $o['result'] === ['ok' => true, 'n' => 1] && $o['http_code'] === 200 && $o['error'] === null,
	],
	'invalid JSON' => [
		'req' => ['path' => 'invalid-json'],
		'expect' => fn($o) => $o['result'] === false && str_starts_with((string) $o['error'], 'Invalid JSON in response body') && $o['http_code'] === 200,
	],
	'empty body' => [
		'req' => ['path' => 'empty'],
		'expect' => fn($o) => $o['result'] === false && $o['error'] === null && $o['raw'] === '' && $o['http_code'] === 200,
	],
	'204 No Content' => [
		'req' => ['path' => 'no-content'],
		'expect' => fn($o) => $o['result'] === false && $o['error'] === null && $o['http_code'] === 204,
	],
	'404' => [
		'req' => ['path' => 'missing'],
		'expect' => fn($o) => $o['result'] === ['error' => 'not here'] && $o['http_code'] === 404 && $o['curl_errno'] === 0,
	],
	'redirect chain keeps the last hop\'s headers only' => [
		'req' => ['path' => 'redirect/3'],
		'expect' => fn($o) => $o['http_code'] === 200 && ($o['result']['path'] ?? '') === '/redirect/0'
			&& ($o['headers']['x-final'] ?? null) === ['yes'] && !isset($o['headers']['x-hop']) && !isset($o['headers']['set-cookie']),
	],
	'binary POST' => [
		'req' => ['path' => 'echo', 'data' => $binary, 'verb' => 'POST', 'headers' => ['Content-Type: application/octet-stream']],
		'expect' => fn($o) => ($o['result']['sha256'] ?? '') === hash('sha256', $binary) && ($o['result']['len'] ?? 0) === strlen($binary)
			&& ($o['result']['content_type'] ?? '') === 'application/octet-stream',
	],
	'form POST' => [
		'req' => ['path' => 'echo', 'data' => ['a' => '1', 'b' => 'x y&z'], 'verb' => 'POST', 'format' => 'form'],
		'expect' => fn($o) => is_string($o['result']) && (json_decode($o['result'], true)['post'] ?? null) === ['a' => '1', 'b' => 'x y&z']
			&& str_starts_with((string) (json_decode($o['result'], true)['content_type'] ?? ''), 'application/x-www-form-urlencoded'),
	],
	'GET with query' => [
		'req' => ['path' => 'echo', 'data' => ['q' => 'a b', 'n' => '2']],
		'expect' => fn($o) => ($o['result']['query'] ?? null) === ['q' => 'a b', 'n' => '2'] && ($o['result']['method'] ?? '') === 'GET'
			&& ($o['result']['len'] ?? -1) === 0 && ($o['result']['user_agent'] ?? '') === APPLICATION_USERAGENT,
	],
	'SSE completes on [DONE]' => [
		'req' => ['path' => 'sse-done', 'format' => 'json,sse'],
		'expect' => fn($o) => $o['curl_errno'] === 0 && $o['error'] === null && $o['result'] === "data: {\"n\":1}\n\ndata: [DONE]\n\n"
			&& $o['http_code'] === 200,
		'maxSeconds' => 1.0,
	],
	'SSE body ends with the [DONE] event' => [
		'req' => ['path' => 'sse-done-trailing', 'format' => 'json,sse'],
		'expect' => fn($o) => $o['curl_errno'] === 0 && $o['error'] === null && $o['result'] === "data: {\"n\":1}\n\ndata: [DONE]\n\n"
			&& $o['raw'] === $o['result'],
	],
	'stream-keepalive' => [
		'req' => ['path' => 'stream', 'format' => 'raw,stream-keepalive', 'stream' => true],
		'expect' => fn($o) => $o['result'] === "line-1\nline-2\nline-3\nline-4\nline-5\n" && $o['curl_errno'] === 0,
	],
	'streamed writeCallback chunks' => [
		'req' => ['path' => 'stream', 'format' => 'raw', 'stream' => true],
		'expect' => fn($o) => $o['result'] === "line-1\nline-2\nline-3\nline-4\nline-5\n",
	],
	'multipart upload (CURLFile, in-process executors only)' => [
		'req' => ['path' => 'echo', 'data' => ['note' => 'hi', 'f' => new CURLFile(__FILE__, 'text/plain', 'run.php')], 'verb' => 'POST', 'format' => 'multipart'],
		'executors' => ['multi+tick', 'perform'],
		// curl picks a random boundary per request: mask what depends on it
		'project' => function (array $o): array {
			$echo = json_decode((string) $o['result'], true);
			$echo['content_type'] = preg_replace('/boundary=.*/', 'boundary=B', (string) ($echo['content_type'] ?? ''));
			unset($echo['sha256']);
			$o['result'] = $o['raw'] = json_encode($echo);
			return $o;
		},
		'expect' => fn($o) => is_string($o['result'])
			&& (json_decode($o['result'], true)['files']['f'] ?? null) === ['name' => 'run.php', 'size' => filesize(__FILE__)]
			&& (json_decode($o['result'], true)['post'] ?? null) === ['note' => 'hi']
			&& str_starts_with((string) (json_decode($o['result'], true)['content_type'] ?? ''), 'multipart/form-data; boundary='),
	],
	'basic auth' => [
		'setup' => function ($c) { $c->setPlaintextAuth('alice', 's3cret'); return $c; },
		'req' => ['path' => 'auth'],
		'expect' => fn($o) => $o['result'] === ['user' => 'alice'] && $o['http_code'] === 200,
	],
	'timeout' => [
		'req' => ['path' => 'slow', 'timeout' => 1],
		'expect' => fn($o) => $o['curl_errno'] === CURLE_OPERATION_TIMEDOUT && $o['http_code'] === 0 && $o['error'] !== null && $o['result'] === null,
		'maxSeconds' => 1.8,
	],
	'connection refused' => [
		// Windows retries a refused connect for about 2 s before reporting it
		'req' => ['path' => 'nope', 'endpoint' => 'http://127.0.0.1:1', 'timeout' => 6],
		'expect' => fn($o) => $o['curl_errno'] === CURLE_COULDNT_CONNECT && $o['http_code'] === 0 && $o['error'] !== null,
	],
];
if ($unixSocket !== null) {
	$cases['Unix socket'] = [
		'setup' => function ($c) use ($unixSocket) { $c->setUnixSocket($unixSocket); return $c; },
		'req' => ['path' => 'via-socket', 'endpoint' => 'http://localhost'],
		'expect' => fn($o) => $o['result'] === ['via' => 'unix', 'request' => 'GET /via-socket HTTP/1.1'] && $o['http_code'] === 200,
	];
}

echo 'Library: ' . (new ReflectionClass('EnchiladaCurl'))->getFileName() . "\n";
echo 'Executors: ' . implode(', ', array_keys($executors))
	. ($reactor !== null ? ' (reactor: ' . get_class($reactor) . ')' : '') . "\n";
if ($unixSocket === null) {
	echo "  (Unix socket case not run on Windows)\n";
}
if ($workers && $reactor === null) {
	check('Comal available for the worker+hooks executor (--comal=DIR)', false);
}

foreach ($cases as $name => $case) {
	echo "\n{$name}\n";
	$reference = null;
	$referenceName = null;
	foreach ($executors as $executorName => $run) {
		if (isset($case['executors']) && !in_array($executorName, $case['executors'], true)) {
			continue;
		}
		[$outcome, $chunks, $seconds, $client, $id] = $run($case['setup'] ?? $none, $case['req']);
		$label = str_pad($executorName, 13);
		check("{$label} expected outcome", is_array($outcome) && !isset($outcome['__still_watched']) && ($case['expect'])($outcome),
			json_encode(is_array($outcome) ? normalize($outcome) : $outcome, JSON_UNESCAPED_SLASHES));
		if (isset($case['maxSeconds'])) {
			check(sprintf('%s finished in %.2f s (limit %.1f s)', $label, $seconds, $case['maxSeconds']), $seconds < $case['maxSeconds']);
		}
		if (!empty($case['req']['stream'])) {
			check("{$label} chunks reassemble the body", $chunks !== [] && implode('', $chunks) === ($outcome['raw'] ?? null), count($chunks) . ' chunks');
		}
		check("{$label} outcome handed over once", ($id === null || $client->getResult($id) === null) && !$client->hasPendingRequests());
		$normalized = json_encode(normalize(isset($case['project']) ? $case['project']((array) $outcome) : (array) $outcome), JSON_UNESCAPED_SLASHES);
		if ($reference === null) {
			$reference = $normalized;
			$referenceName = $executorName;
		} else {
			check("{$label} identical to {$referenceName}", $normalized === $reference, "\n      {$referenceName}: {$reference}\n      {$executorName}: {$normalized}");
		}
	}
}

// ---------------------------------------------------------------------
// Executor-specific guarantees
// ---------------------------------------------------------------------
echo "\nLong-lived clients do not retain completed responses\n";
foreach (['multi+tick', 'perform', 'worker+wait'] as $executorName) {
	if (!isset($executors[$executorName])) {
		continue;
	}
	$client = $executorName === 'worker+wait' ? new EnchiladaWorkerHTTP($base) : new EnchiladaMultiHTTP($base);
	$runOne = match ($executorName) {
		'multi+tick' => function () use ($client) { $id = $client->queue('json'); while (($o = $client->getResult($id)) === null) { $client->tick(); } return $o; },
		'perform' => function () use ($client) { $s = $client->buildSpec('json'); return EnchiladaCurl::decodeTransport($s['format'], $client->perform($s)); },
		'worker+wait' => function () use ($client) { $id = $client->queue('json'); $client->wait($id); return $client->getResult($id); },
	};
	for ($i = 0; $i < 20; $i++) { $runOne(); }
	$before = memory_get_usage();
	for ($i = 0; $i < 300; $i++) { $last = $runOne(); }
	$grownKb = (int) ((memory_get_usage() - $before) / 1024);
	check(sprintf('%-13s 300 calls on one client: memory grew %d KB', $executorName, $grownKb), $grownKb < 256 && ($last['result']['ok'] ?? false) === true);
	if ($client instanceof EnchiladaWorkerHTTP) {
		$client->shutdown();
	}
}

if (!$workers) {
	echo "\nWorkers unsupported on this platform\n";
	check('EnchiladaWorkerHTTP::supported() is false on Windows', $windows);
	$client = new EnchiladaWorkerHTTP($base);
	try {
		$client->queue('json');
		check('queue() refuses when no worker can start', false);
	} catch (RuntimeException $e) {
		check('queue() refuses when no worker can start (request not taken)', !$client->hasPendingRequests(), $e->getMessage());
	}
	check('unavailable() explains why', is_string($client->unavailable()));
} else {
	echo "\nEnchiladaWorkerHTTP\n";

	$client = new EnchiladaWorkerHTTP($base);
	try {
		$client->queueSpec($client->buildSpec('echo', ['f' => new CURLFile(__FILE__)], 'POST', [], null, 'multipart'));
		check('a CURLFile payload is rejected', false);
	} catch (InvalidArgumentException $e) {
		check('a CURLFile payload is rejected, so the caller can run it in place', !$client->hasPendingRequests());
	}

	$client->setMaxWorkers(2);
	$ids = [];
	for ($i = 0; $i < 6; $i++) {
		$ids[] = $client->queue('json');
	}
	$workersProp = new ReflectionProperty($client, 'workers');
	check('workers start on demand up to setMaxWorkers()', count($workersProp->getValue($client)) === 2);
	$ok = 0;
	foreach (array_reverse($ids) as $id) {
		$client->wait($id);
		$ok += ($client->getResult($id)['result']['ok'] ?? false) === true ? 1 : 0;
	}
	check('queued requests wait for a free worker and all complete', $ok === 6, "{$ok}/6");

	$id = $client->queue('slow', null, 'GET', [], 10);
	foreach ($workersProp->getValue($client) as $w) {
		if ($w['job'] !== null) {
			proc_terminate($w['proc'], 9);
		}
	}
	$client->wait($id);
	$o = $client->getResult($id);
	check('a dead worker fails its request with CURLE_RECV_ERROR', ($o['curl_errno'] ?? 0) === CURLE_RECV_ERROR, json_encode($o));
	$id = $client->queue('json');
	$client->wait($id);
	check('the next request gets a fresh worker', ($client->getResult($id)['result']['ok'] ?? false) === true);

	$id = $client->queue('slow', null, 'GET', [], 10);
	$t = microtime(true);
	$client->shutdown();
	$o = $client->getResult($id);
	check(sprintf('shutdown terminates a busy worker at once (%.0f ms)', (microtime(true) - $t) * 1000),
		microtime(true) - $t < 0.5 && ($o['curl_errno'] ?? 0) === CURLE_RECV_ERROR);
	check('no workers left after shutdown', $workersProp->getValue($client) === []);

	if ($reactor !== null) {
		$client = new EnchiladaWorkerHTTP($base);
		$watched = 0;
		$unwatched = 0;
		$watchers = [];
		$client->setWatchHooks(
			function ($s) use (&$watched, &$watchers, $reactor, $client) {
				$watched++;
				$watchers[get_resource_id($s)] = $reactor->onReadable($s, fn($st) => $client->handleReadable($st));
			},
			function ($s) use (&$unwatched, &$watchers, $reactor) {
				$unwatched++;
				$reactor->cancel($watchers[get_resource_id($s)]);
				unset($watchers[get_resource_id($s)]);
			}
		);
		$a = $client->queue('json');
		$b = $client->queue('stream', null, 'GET', [], null, 'raw');
		$timerAt = null;
		$t = microtime(true);
		$reactor->delay(0.05, function () use (&$timerAt, $t) { $timerAt = (microtime(true) - $t) * 1000; });
		$c = $client->queue('slow', null, 'GET', [], 10);
		$reactor->run();
		$elapsed = microtime(true) - $t;
		check('the loop returns on its own once every request is done (idle workers are not watched)',
			$watched === 3 && $unwatched === 3 && $watchers === [], "watched={$watched} unwatched={$unwatched}");
		check(sprintf('a 50 ms loop timer fires on time while a 2 s request runs (%.0f ms)', $timerAt ?? -1), $timerAt !== null && $timerAt < 150);
		check('all three outcomes delivered', ($client->getResult($a)['result']['ok'] ?? false) === true
			&& str_starts_with((string) ($client->getResult($b)['result'] ?? ''), 'line-1')
			&& ($client->getResult($c)['result']['slow'] ?? false) === true, sprintf('%.2f s', $elapsed));
		$client->shutdown();
	}
}

echo "\n" . str_repeat('-', 40) . "\n";
echo "Results: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
