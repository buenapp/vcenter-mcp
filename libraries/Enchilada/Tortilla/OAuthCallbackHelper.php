<?php

namespace Enchilada\Tortilla;

/* Enchilada Framework 3.0
 * OAuth Callback Helper Process
 *
 * Serves one OAuth 3LO browser callback from a detached PHP process, for
 * hosts whose transport runs in blocking I/O mode (Windows stdio): there
 * the transport only services extra sockets between incoming messages,
 * so an in-process listener leaves the consent page waiting. The helper
 * owns the callback listener, exchanges the code and writes the token
 * file; the server process picks the tokens up through
 * \EnchiladaOAuth\Oauth3LO's token-file check.
 *
 * Parent side: start() launches the helper and returns once it has
 * bound a callback port; poll() reports completion without blocking;
 * stop() ends the helper. Child side: serve().
 *
 * Control channel: the helper connects back to a one-shot loopback
 * listener and proves itself with a random token taken from its
 * environment (process arguments are visible to other local users),
 * then receives the flow (client secret, PKCE verifier) over that
 * connection. Lines are JSON objects:
 *   helper -> parent  {"token": ...}
 *   parent -> helper  the flow
 *   helper -> parent  {"port": N} | {"error": ...}
 *   helper -> parent  {"status": "ok"|"error"|"timeout", "message"?: ...}
 * The helper exits when the connection closes (parent stopped it or
 * exited), after its TTL, or once the flow completes.
 *
 * Launch: detached so it never holds the host's protocol pipes. POSIX
 * runs it in a background subshell with /dev/null stdio; Windows uses
 * PowerShell Start-Process, because proc_open() and `start` both let the
 * child inherit the parent's stdout pipe there, which keeps the host
 * from seeing EOF until the helper exits.
 *
 * Software License Agreement (BSD License)
 *
 * Copyright (c) 2026, The Daniel Morante Company, Inc.
 * All rights reserved.
 */

final class OAuthCallbackHelper
{
	/** Seconds start() waits for the helper to connect and bind */
	public const READY_TIMEOUT = 20;

	/** Environment variable carrying the control token to the helper */
	private const TOKEN_ENV = 'ENCHILADA_OAUTH_HELPER_TOKEN';

	/** @var resource|null Control connection to the helper */
	private $control;

	private int $port;

	private string $buffer = '';

	/** @var array<string,mixed>|null Final status once known */
	private ?array $status = null;

	/**
	 * @param resource $control
	 */
	private function __construct($control, int $port)
	{
		$this->control = $control;
		$this->port = $port;
	}

	/**
	 * Launch a helper and wait until it has bound a callback port.
	 *
	 * @param array{path:string,state:string,ports:int[],ttl:int,code_verifier:string,
	 *              authorization_endpoint:string,token_endpoint:string,client_id:string,
	 *              client_secret:string,scope:?string,token_file:string,ca_cert?:?string} $flow
	 *        token_endpoint must be a full URL; the redirect_uri used for the
	 *        exchange is the helper's bound callback URL
	 *        (http://localhost:{port}{path}), as in the consent URL
	 * @throws \RuntimeException When the helper cannot be started or cannot bind
	 */
	public static function start(array $flow): self
	{
		$listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
		if ($listener === false) {
			throw new \RuntimeException("OAuth helper: control listener failed: [{$errno}] {$errstr}");
		}
		$name = stream_socket_get_name($listener, false);
		$controlPort = (int) substr($name, strrpos($name, ':') + 1);
		$token = bin2hex(random_bytes(16));

		self::spawn($controlPort, $token);

		$conn = stream_socket_accept($listener, self::READY_TIMEOUT);
		fclose($listener);
		if ($conn === false) {
			throw new \RuntimeException('OAuth helper did not start within ' . self::READY_TIMEOUT . 's');
		}
		stream_set_timeout($conn, self::READY_TIMEOUT);

		$hello = json_decode((string) fgets($conn), true);
		if (!is_array($hello) || !hash_equals($token, (string) ($hello['token'] ?? ''))) {
			fclose($conn);
			throw new \RuntimeException('OAuth helper: control connection failed authentication');
		}

		$flow['classes'] = self::classMap();
		$flow['comal_dir'] = class_exists(\Enchilada\Comal\ReactorFactory::class)
			? dirname((new \ReflectionClass(\Enchilada\Comal\ReactorFactory::class))->getFileName())
			: null;
		$flow['useragent'] = defined('APPLICATION_USERAGENT') ? APPLICATION_USERAGENT : 'Enchilada';
		fwrite($conn, json_encode($flow, JSON_UNESCAPED_SLASHES) . "\n");

		$reply = json_decode((string) fgets($conn), true);
		if (!is_array($reply) || !isset($reply['port'])) {
			fclose($conn);
			$why = is_array($reply) ? ($reply['error'] ?? 'unknown error') : 'no reply';
			throw new \RuntimeException("OAuth helper could not start the callback listener: {$why}");
		}

		stream_set_blocking($conn, false);
		return new self($conn, (int) $reply['port']);
	}

	/**
	 * The callback port the helper bound.
	 */
	public function port(): int
	{
		return $this->port;
	}

	/**
	 * The helper's final status, or null while the flow is still open.
	 * Never blocks.
	 *
	 * @return array{status:string,message?:string}|null status is 'ok',
	 *         'error', 'timeout', 'stopped', or 'exited' (gone without a
	 *         status)
	 */
	public function poll(): ?array
	{
		if ($this->status !== null || $this->control === null) {
			return $this->status;
		}
		while (($data = fread($this->control, 8192)) !== false && $data !== '') {
			$this->buffer .= $data;
		}
		while (($eol = strpos($this->buffer, "\n")) !== false) {
			$message = json_decode(substr($this->buffer, 0, $eol), true);
			$this->buffer = substr($this->buffer, $eol + 1);
			if (is_array($message) && isset($message['status'])) {
				$this->status = $message;
			}
		}
		if ($this->status === null && feof($this->control)) {
			$this->status = ['status' => 'exited'];
		}
		if ($this->status !== null) {
			fclose($this->control);
			$this->control = null;
		}
		return $this->status;
	}

	/**
	 * End the helper (it exits when the control connection closes),
	 * freeing its callback port.
	 */
	public function stop(): void
	{
		if ($this->control !== null) {
			fclose($this->control);
			$this->control = null;
		}
		$this->status ??= ['status' => 'stopped'];
	}

	/**
	 * Helper process entry point.
	 */
	public static function serve(int $controlPort): void
	{
		$control = stream_socket_client("tcp://127.0.0.1:{$controlPort}", $errno, $errstr, self::READY_TIMEOUT);
		if ($control === false) {
			exit(1);
		}
		$send = function (array $message) use ($control): void {
			fwrite($control, json_encode($message, JSON_UNESCAPED_SLASHES) . "\n");
		};
		$send(['token' => (string) getenv(self::TOKEN_ENV)]);

		$flow = json_decode((string) fgets($control), true);
		if (!is_array($flow)) {
			exit(1);
		}
		self::registerAutoload($flow['classes'], $flow['comal_dir']);
		defined('APPLICATION_DEBUG') || define('APPLICATION_DEBUG', false);
		defined('APPLICATION_USERAGENT') || define('APPLICATION_USERAGENT', $flow['useragent']);

		try {
			$server = new \EnchiladaOAuth\OAuthCallbackServer($flow['path'], $flow['state'], $flow['ports']);
		} catch (\RuntimeException $e) {
			$send(['error' => $e->getMessage()]);
			exit(1);
		}
		$send(['port' => $server->getPort()]);

		$finish = function (string $code) use ($flow, $server): array {
			try {
				self::exchange($flow, $server->getCallbackUrl(), $code);
				return ['status' => 'ok'];
			} catch (\Throwable $e) {
				return ['status' => 'error', 'message' => $e->getMessage()];
			}
		};

		$loop = ComalEventLoop::create();
		if ($loop === null) {
			// No loop vendored: wait for the callback alone (parent exit is
			// not noticed until the TTL)
			$code = $server->waitForCallback((int) $flow['ttl']);
			$status = $code !== null ? $finish($code)
				: ($server->getError() !== null ? ['status' => 'error', 'message' => $server->getError()] : ['status' => 'timeout']);
			$send($status);
			$server->close();
			exit(0);
		}

		$status = null;
		$watchers = [];
		$server->setWatchHooks(
			function ($stream) use (&$watchers, $loop, $server, $finish, &$status) {
				$watchers[(int) $stream] = $loop->onReadable($stream, function () use ($loop, $server, $finish, &$status) {
					$code = $server->handleConnection();
					if ($code !== null) {
						$status = $finish($code);
					} elseif ($server->getError() !== null) {
						$status = ['status' => 'error', 'message' => $server->getError()];
					}
					if ($status !== null) {
						$loop->stop();
					}
				});
			},
			function ($stream) use (&$watchers, $loop) {
				$loop->cancel($watchers[(int) $stream] ?? '');
				unset($watchers[(int) $stream]);
			}
		);
		stream_set_blocking($control, false);
		$loop->onReadable($control, function ($stream) use ($loop) {
			while (($data = fread($stream, 8192)) !== false && $data !== '') {
				// nothing is expected from the parent after the flow
			}
			if (feof($stream)) {
				$loop->stop();   // parent stopped the flow or exited
			}
		});
		$loop->delay((float) $flow['ttl'], function () use ($loop, &$status) {
			$status = ['status' => 'timeout'];
			$loop->stop();
		});
		$loop->run();

		// Report before closing: on Windows PHP's socket close waits up to
		// 500 ms for a listener to become writable, which it never does
		if ($status !== null) {
			stream_set_blocking($control, true);
			$send($status);
		}
		$server->close();
		exit(0);
	}

	/**
	 * Exchange the authorization code and persist the tokens.
	 *
	 * @param array<string,mixed> $flow
	 * @throws \Exception When the exchange fails
	 */
	private static function exchange(array $flow, string $redirectUri, string $code): void
	{
		$url = parse_url($flow['token_endpoint']);
		$origin = $url['scheme'] . '://' . $url['host'] . (isset($url['port']) ? ':' . $url['port'] : '');
		$multi = new \EnchiladaMultiHTTP($origin);
		if (!empty($flow['ca_cert'])) {
			$multi->setCaCert($flow['ca_cert']);
		}
		$client = new Oauth3LOClient(new HttpClient($multi), $flow['authorization_endpoint'], $flow['token_endpoint'],
			$flow['client_id'], $flow['client_secret'], $redirectUri, $flow['scope'], $flow['token_file']);
		$client->exchangeCode($code, $flow['code_verifier']);
	}

	/**
	 * Files of every class the helper needs, as loaded in this process.
	 *
	 * @return array<string,string>
	 */
	private static function classMap(): array
	{
		$map = [];
		foreach ([\EnchiladaCurl::class, \EnchiladaMultiHTTP::class, \EnchiladaOAuth\OAuthCallbackServer::class, \EnchiladaOAuth\Oauth3LO::class,
			EventLoop::class, ComalEventLoop::class, HttpClient::class, Oauth3LOClient::class] as $class) {
			$map[$class] = (new \ReflectionClass($class))->getFileName();
		}
		return $map;
	}

	/**
	 * Class loading in the helper: the parent's class map, plus the Comal
	 * directory for whichever reactor backend the helper's factory picks.
	 *
	 * @param array<string,string> $classes
	 */
	private static function registerAutoload(array $classes, ?string $comalDir): void
	{
		spl_autoload_register(function (string $class) use ($classes, $comalDir): void {
			if (isset($classes[$class])) {
				require_once $classes[$class];
			} elseif ($comalDir !== null && str_starts_with($class, 'Enchilada\\Comal\\')) {
				$file = $comalDir . '/' . substr($class, strlen('Enchilada\\Comal\\')) . '.php';
				if (is_file($file)) {
					require_once $file;
				}
			}
		});
	}

	/**
	 * Launch the helper detached from this process's stdio.
	 *
	 * @throws \RuntimeException When no launcher is available
	 */
	private static function spawn(int $controlPort, string $token): void
	{
		if (!function_exists('exec') || PHP_BINARY === '') {
			throw new \RuntimeException('OAuth helper needs CLI PHP with exec()');
		}
		$code = 'require ' . var_export(__FILE__, true) . ';'
			. '\\Enchilada\\Tortilla\\OAuthCallbackHelper::serve(' . $controlPort . ');';

		putenv(self::TOKEN_ENV . '=' . $token);
		try {
			if (\PHP_OS_FAMILY === 'Windows') {
				// One argument without spaces survives Start-Process's
				// unquoted -ArgumentList joining
				$arg = "eval(base64_decode('" . base64_encode($code) . "'));";
				$ps = "\$ProgressPreference = 'SilentlyContinue'; "
					. "Start-Process -WindowStyle Hidden -FilePath '" . str_replace("'", "''", PHP_BINARY)
					. "' -ArgumentList '-r','" . str_replace("'", "''", $arg) . "'";
				$encoded = base64_encode(self::utf16le($ps));
				// stderr would otherwise reach the host's pipe
				exec('powershell -NoProfile -NonInteractive -EncodedCommand ' . $encoded . ' <NUL 2>NUL', $output, $ret);
			} else {
				// Background subshell: /dev/null stdio, descriptors 3-9
				// closed, reparented when the shell exits
				exec('(exec 3>&- 4>&- 5>&- 6>&- 7>&- 8>&- 9>&-; exec ' . escapeshellarg(PHP_BINARY) . ' -r '
					. escapeshellarg($code) . ') </dev/null >/dev/null 2>&1 &', $output, $ret);
			}
		} finally {
			putenv(self::TOKEN_ENV);
		}
		if ($ret !== 0) {
			throw new \RuntimeException("OAuth helper launch failed (exit {$ret})");
		}
	}

	/**
	 * UTF-8 to UTF-16LE (PowerShell -EncodedCommand), with whichever
	 * converter this PHP build has.
	 */
	private static function utf16le(string $text): string
	{
		if (function_exists('mb_convert_encoding')) {
			return mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
		}
		if (function_exists('iconv')) {
			return iconv('UTF-8', 'UTF-16LE', $text);
		}
		if (preg_match('/[^\x00-\x7F]/', $text)) {
			throw new \RuntimeException('OAuth helper launch needs mbstring or iconv for a non-ASCII PHP path');
		}
		return implode("\0", str_split($text)) . "\0";
	}
}
