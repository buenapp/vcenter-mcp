<?php

namespace EnchiladaMCP;

/* Enchilada Framework 3.0
 * MCP File Logger
 *
 * Minimal, failure-proof logger for MCP servers. Stdio transports own
 * stdout (protocol channel) and stderr is often captured and discarded
 * by MCP hosts, so durable diagnostics require an opt-in log file.
 *
 * The logger never throws: any write failure is silently ignored so a
 * logging problem can never break the JSON-RPC protocol stream. Nor can
 * it stall it — see mirrorToStderr(), which refuses to fill a stderr
 * pipe that the host may never drain.
 *
 * Instances are callable (via __invoke) so a Logger can be passed
 * directly to StdioTransport::setLogger(), McpServer::setLogger(), etc.
 *
 * Usage:
 *   $logger = Logger::fromEnv('FORGEJO_MCP'); // FORGEJO_MCP_LOG, FORGEJO_MCP_LOG_LEVEL, FORGEJO_MCP_LOG_STDERR
 *   $logger->info('Server started');
 *   $transport->setLogger($logger);
 *
 * Software License Agreement (BSD License)
 *
 * Copyright (c) 2026, The Daniel Morante Company, Inc.
 * All rights reserved.
 */

class Logger
{
	public const LEVEL_DEBUG = 0;
	public const LEVEL_INFO  = 1;
	public const LEVEL_ERROR = 2;

	private const LEVEL_NAMES = [
		self::LEVEL_DEBUG => 'DEBUG',
		self::LEVEL_INFO  => 'INFO',
		self::LEVEL_ERROR => 'ERROR',
	];

	/**
	 * Bytes we are willing to write to a stderr *pipe* that cannot be put
	 * into non-blocking mode (Windows). Deliberately smaller than the
	 * smallest plausible pipe buffer (4 KiB) so a host that never reads
	 * stderr can still not stall us mid-handshake, while leaving room for
	 * the startup banner and a fatal configuration error.
	 */
	private const STDERR_PIPE_BUDGET = 2048;

	/** @var string|null Log file path (null = file logging disabled) */
	private ?string $path;

	/** @var int Minimum level written to the file */
	private int $level;

	/** @var bool Mirror log lines to STDERR */
	private bool $mirrorStderr;

	/** @var string Tag prepended to each line (e.g. "forgejo-mcp") */
	private string $tag;

	/**
	 * Create a new logger.
	 *
	 * @param string|null $path         Log file path, or null to disable file output
	 * @param int         $level        Minimum level (LEVEL_DEBUG, LEVEL_INFO, LEVEL_ERROR)
	 * @param bool        $mirrorStderr Also write every emitted line to STDERR
	 * @param string      $tag          Short identifier included in each line
	 */
	public function __construct(?string $path = null, int $level = self::LEVEL_INFO, bool $mirrorStderr = false, string $tag = 'mcp')
	{
		$this->path = ($path !== null && $path !== '') ? $path : null;
		$this->level = $level;
		$this->mirrorStderr = $mirrorStderr;
		$this->tag = $tag;
	}

	/**
	 * Build a logger from environment variables.
	 *
	 *   {PREFIX}_LOG         Log file path (enables file logging)
	 *   {PREFIX}_LOG_LEVEL   debug|info|error (default: debug when a log file is set)
	 *   {PREFIX}_LOG_STDERR  truthy value mirrors output to STDERR
	 *
	 * @param  string $prefix Environment variable prefix (e.g. "FORGEJO_MCP")
	 * @param  string $tag    Tag for log lines
	 * @return self
	 */
	public static function fromEnv(string $prefix, string $tag = 'mcp'): self
	{
		$path = getenv("{$prefix}_LOG") ?: null;

		$level = self::LEVEL_DEBUG;
		switch (strtolower((string)(getenv("{$prefix}_LOG_LEVEL") ?: ''))) {
			case 'info':  $level = self::LEVEL_INFO;  break;
			case 'error': $level = self::LEVEL_ERROR; break;
		}

		$stderr = (bool)getenv("{$prefix}_LOG_STDERR");

		return new self($path, $level, $stderr, $tag);
	}

	/**
	 * Parse a level name into a level constant.
	 *
	 * @param  string $name Level name (debug, info, error)
	 * @return int|null     Level constant, or null if unrecognized
	 */
	public static function levelFromString(string $name): ?int
	{
		return match (strtolower($name)) {
			'debug' => self::LEVEL_DEBUG,
			'info'  => self::LEVEL_INFO,
			'error' => self::LEVEL_ERROR,
			default => null,
		};
	}

	/**
	 * Whether any output sink is active.
	 *
	 * @return bool
	 */
	public function enabled(): bool
	{
		return $this->path !== null || $this->mirrorStderr;
	}

	/**
	 * Whether debug-level messages are emitted.
	 *
	 * @return bool
	 */
	public function isDebug(): bool
	{
		return $this->enabled() && $this->level <= self::LEVEL_DEBUG;
	}

	/**
	 * Log a debug message (verbose protocol/transport detail).
	 *
	 * @param string $message
	 */
	public function debug(string $message): void
	{
		$this->log(self::LEVEL_DEBUG, $message);
	}

	/**
	 * Log an informational message (lifecycle, request summaries).
	 *
	 * @param string $message
	 */
	public function info(string $message): void
	{
		$this->log(self::LEVEL_INFO, $message);
	}

	/**
	 * Log an error message.
	 *
	 * @param string $message
	 */
	public function error(string $message): void
	{
		$this->log(self::LEVEL_ERROR, $message);
	}

	/**
	 * Callable interface — messages are logged at debug level.
	 *
	 * Allows passing the Logger directly where a callable is expected
	 * (e.g. StdioTransport::setLogger($logger)).
	 *
	 * @param string $message
	 */
	public function __invoke(string $message): void
	{
		$this->debug($message);
	}

	/**
	 * Summarize a payload for safe logging: byte length and SHA-256 digest.
	 *
	 * The digest allows byte-exactness verification (e.g. large base64
	 * secrets) without writing secret material to the log.
	 *
	 * @param  string $data Payload
	 * @return string       e.g. "len=10308 sha256=9f2c..."
	 */
	public static function digest(string $data): string
	{
		return 'len=' . strlen($data) . ' sha256=' . hash('sha256', $data);
	}

	/**
	 * Truncate a string for single-line log output.
	 *
	 * @param  string $text      Text to truncate
	 * @param  int    $maxLength Maximum characters kept (default 200)
	 * @return string
	 */
	public static function truncate(string $text, int $maxLength = 200): string
	{
		if (strlen($text) <= $maxLength) {
			return $text;
		}
		return substr($text, 0, $maxLength) . '...';
	}

	/**
	 * Emit a log line to all active sinks.
	 *
	 * @param int    $level   Level constant
	 * @param string $message Message text (newlines are flattened)
	 */
	private function log(int $level, string $message): void
	{
		if ($level < $this->level) {
			return;
		}

		$line = date('Y-m-d\TH:i:sP')
			. ' [' . $this->tag . ']'
			. ' [' . self::LEVEL_NAMES[$level] . '] '
			. str_replace(["\r", "\n"], ['\r', '\n'], $message)
			. "\n";

		if ($this->path !== null) {
			@file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX);
		}

		if ($this->mirrorStderr) {
			$this->mirrorToStderr($line);
		}
	}

	/**
	 * Write a line to STDERR without ever risking a blocked write.
	 *
	 * MCP hosts routinely create a stderr pipe and then read it lazily or
	 * not at all. Once the OS pipe buffer fills, a blocking
	 * fwrite(STDERR) never returns and the whole server wedges — the
	 * host sees a live process that has stopped answering, i.e. a hang.
	 *
	 * Three cases, established once per process:
	 *
	 *   nonblocking  stream_set_blocking(false) took effect (POSIX). A
	 *                full pipe drops bytes instead of blocking, so the
	 *                stream is safe to use without limit.
	 *
	 *   safe-sink    stderr is a regular file or a character device
	 *                (console, /dev/null). There is no peer that has to
	 *                drain it, so writes cannot stall.
	 *
	 *   bounded      stderr is a pipe that could not be made
	 *                non-blocking. This is Windows: stream_set_blocking()
	 *                is a no-op for pipes there. Spend a small byte
	 *                budget so genuine startup errors still surface, then
	 *                stop before the buffer can fill.
	 */
	private function mirrorToStderr(string $line): void
	{
		static $mode = null;
		static $budget = self::STDERR_PIPE_BUDGET;

		if ($mode === null) {
			$mode = self::classifyStderr();
		}

		if ($mode !== 'bounded') {
			@fwrite(STDERR, $line);
			return;
		}

		if ($budget <= 0) {
			return;
		}

		$budget -= strlen($line);
		@fwrite(STDERR, $line);

		if ($budget <= 0) {
			// Say so on both channels: the file log is where the rest of
			// the diagnostics went, and the reader of stderr needs to
			// know its stream is deliberately incomplete.
			$notice = date('Y-m-d\TH:i:sP') . ' [' . $this->tag . '] [INFO] '
				. 'stderr mirroring stopped after ' . self::STDERR_PIPE_BUDGET . ' bytes: '
				. 'this platform cannot write to a stderr pipe without risking a stall '
				. 'if the host does not drain it'
				. ($this->path !== null ? '. Full log: ' . $this->path : '. Set a log file to keep full diagnostics')
				. "\n";
			@fwrite(STDERR, $notice);
			if ($this->path !== null) {
				@file_put_contents($this->path, $notice, FILE_APPEND | LOCK_EX);
			}
		}
	}

	/**
	 * Decide how STDERR may be used: 'nonblocking', 'safe-sink' or
	 * 'bounded'. See mirrorToStderr() for what each implies.
	 */
	private static function classifyStderr(): string
	{
		if (@stream_set_blocking(STDERR, false)) {
			return 'nonblocking';
		}

		// Not a pipe? Then nothing can fail to drain it.
		$stat = @fstat(STDERR);
		if (is_array($stat) && isset($stat['mode'])) {
			$type = $stat['mode'] & 0170000;   // S_IFMT
			if ($type === 0100000 || $type === 0020000) {   // S_IFREG | S_IFCHR
				return 'safe-sink';
			}
		}

		return 'bounded';
	}
}
