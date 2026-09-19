<?php
/**
 * vCenter MCP Server — Guest Operations facade
 *
 * Run programs and move files inside a guest through VMware Tools
 * (GuestOperationsManager over vim25 SOAP). StartProgramInGuest does no
 * shell interpretation and reports no output, so the capture pattern is
 * the guest's own: /bin/sh -c 'cmd > tmpfile 2>&1', poll
 * ListProcessesInGuest for the exit, then InitiateFileTransferFromGuest
 * to fetch the output.
 *
 * @package    VCenterMCP\VCenter
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

namespace VCenter;

class GuestOperations
{
	/** Output cap for guest_run's captured stdout (bytes). */
	public const STDOUT_LIMIT = 262144;

	private Instance $instance;

	public function __construct(Instance $instance)
	{
		$this->instance = $instance;
	}

	/**
	 * NamePasswordAuthentication element (pre-escaped) for guest-ops calls.
	 */
	public static function authXml(string $username, string $password, bool $interactiveSession = false): string
	{
		// vim25 serializes base-type properties first: interactiveSession
		// (GuestAuthentication) precedes username/password.
		return '<auth xsi:type="NamePasswordAuthentication" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
			. '<interactiveSession>' . ($interactiveSession ? 'true' : 'false') . '</interactiveSession>'
			. '<username>' . SoapClient::esc($username) . '</username>'
			. '<password>' . SoapClient::esc($password) . '</password>'
			. '</auth>';
	}

	/**
	 * Wrap a command line for /bin/sh -c with POSIX single-quote escaping
	 * (' -> '\''), including the surrounding quotes.
	 */
	public static function shQuote(string $command): string
	{
		return "'" . str_replace("'", "'\\''", $command) . "'";
	}

	/** Unique capture-file path inside a POSIX guest. */
	public static function capturePath(): string
	{
		return '/tmp/vcenter-mcp-' . bin2hex(random_bytes(6)) . '.out';
	}

	/**
	 * StartProgramInGuest with a GuestProgramSpec; returns the guest PID.
	 *
	 * @param array{type:string,id:string} $vm    VirtualMachine MoRef
	 * @param array<string,string>         $env   Extra environment (KEY => value)
	 */
	public function startProgram(array $vm, string $authXml, string $programPath, string $arguments = '', ?string $workingDirectory = null, array $env = []): int
	{
		$spec = '<programPath>' . SoapClient::esc($programPath) . '</programPath>'
			. '<arguments>' . SoapClient::esc($arguments) . '</arguments>';
		if ($workingDirectory !== null && $workingDirectory !== '') {
			$spec .= '<workingDirectory>' . SoapClient::esc($workingDirectory) . '</workingDirectory>';
		}
		foreach ($env as $key => $value) {
			$spec .= '<envVariables>' . SoapClient::esc($key . '=' . $value) . '</envVariables>';
		}
		return $this->instance->soap()->startProgramInGuest($vm, $authXml, $spec);
	}

	/**
	 * ListProcessesInGuest, shaped for tool output. A process reports
	 * exit_code only once it has exited (endTime set).
	 */
	public function listProcesses(array $vm, string $authXml, array $pids = []): array
	{
		$out = [];
		foreach ($this->instance->soap()->listProcessesInGuest($vm, $authXml, $pids) as $proc) {
			$out[] = self::shapeProcess($proc);
		}
		return $out;
	}

	/**
	 * Poll ListProcessesInGuest until $pid exits or the budget expires.
	 * A pid that vanishes from the listing without an endTime is reported
	 * as completed with exit_code null (Tools reaped it before we looked).
	 *
	 * @return array{completed:bool,pid:int,exit_code:?int,...}
	 */
	public function waitForExit(array $vm, string $authXml, int $pid, int $timeoutSec, ?callable $sleep = null): array
	{
		$sleep = $sleep ?? fn(int $us) => usleep($us);
		$deadline = microtime(true) + max(0, $timeoutSec);
		$seen = false;

		while (true) {
			$procs = $this->listProcesses($vm, $authXml, [$pid]);
			$proc = $procs[0] ?? null;
			if ($proc !== null) {
				$seen = true;
				if ($proc['end_time'] !== null) {
					return ['completed' => true] + $proc;
				}
			} elseif ($seen) {
				return ['completed' => true, 'pid' => $pid, 'name' => null, 'cmd_line' => null,
					'owner' => null, 'start_time' => null, 'end_time' => null, 'exit_code' => null,
					'note' => 'process left the guest listing without a recorded exit code'];
			}
			if (microtime(true) >= $deadline) {
				return ['completed' => false] + ($proc ?? ['pid' => $pid, 'name' => null, 'cmd_line' => null,
					'owner' => null, 'start_time' => null, 'end_time' => null, 'exit_code' => null]);
			}
			$sleep(500000);
		}
	}

	/**
	 * Run a program to completion: start, wait for exit, and when a
	 * capture path is given fetch the redirected output and remove the
	 * capture file (best effort).
	 *
	 * @return array{pid:int,completed:bool,exit_code:?int,start_time:?string,end_time:?string,stdout?:string,stdout_truncated?:bool}
	 */
	public function run(array $vm, string $authXml, string $programPath, string $arguments, ?string $capturePath, int $timeoutSec, ?string $workingDirectory = null, array $env = [], ?callable $sleep = null): array
	{
		$pid = $this->startProgram($vm, $authXml, $programPath, $arguments, $workingDirectory, $env);
		$result = $this->waitForExit($vm, $authXml, $pid, $timeoutSec, $sleep);

		$out = [
			'pid' => $pid,
			'completed' => $result['completed'],
			'exit_code' => $result['exit_code'],
			'start_time' => $result['start_time'],
			'end_time' => $result['end_time'],
		];
		if (isset($result['note'])) {
			$out['note'] = $result['note'];
		}

		if ($capturePath !== null && $result['completed']) {
			try {
				$file = $this->downloadFile($vm, $authXml, $capturePath);
				$out['stdout'] = strlen($file['content']) > self::STDOUT_LIMIT
					? substr($file['content'], 0, self::STDOUT_LIMIT) : $file['content'];
				$out['stdout_truncated'] = strlen($file['content']) > self::STDOUT_LIMIT;
			} catch (VCenterException $e) {
				$out['note'] = 'output capture failed: ' . $e->getMessage();
			}
			try {
				$this->startProgram($vm, $authXml, '/bin/rm', '-f ' . self::shQuote($capturePath));
			} catch (\Throwable $e) {
				// capture file left behind in /tmp — harmless
			}
		}
		return $out;
	}

	/**
	 * Upload $content to a guest path (InitiateFileTransferToGuest + PUT).
	 *
	 * @param int|null $permissions POSIX mode bits as an integer (e.g. 420 for 0644)
	 * @return int Bytes written
	 */
	public function uploadFile(array $vm, string $authXml, string $guestPath, string $content, bool $overwrite = true, ?int $permissions = null): int
	{
		$attributes = $permissions !== null
			? '<fileAttributes xsi:type="GuestPosixFileAttributes" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><permissions>' . $permissions . '</permissions></fileAttributes>'
			: '<fileAttributes xsi:type="GuestFileAttributes" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"/>';
		$url = $this->instance->soap()->initiateFileTransferToGuest(
			$vm, $authXml, $guestPath, $attributes, strlen($content), $overwrite);
		$this->instance->soap()->putGuestFile($url, $content);
		return strlen($content);
	}

	/**
	 * Download a guest path (InitiateFileTransferFromGuest + GET).
	 *
	 * @return array{size:int,content:string}
	 */
	public function downloadFile(array $vm, string $authXml, string $guestPath): array
	{
		$info = $this->instance->soap()->initiateFileTransferFromGuest($vm, $authXml, $guestPath);
		return ['size' => $info['size'], 'content' => $this->instance->soap()->getGuestFile($info['url'])];
	}

	/**
	 * ListFilesInGuest, shaped for tool output.
	 *
	 * @return array{files:array,new_index:?int,end_of_stream:bool}
	 */
	public function listFiles(array $vm, string $authXml, string $path, ?string $matchPattern = null, ?int $index = null, ?int $maxResults = null): array
	{
		$result = $this->instance->soap()->listFilesInGuest($vm, $authXml, $path, $matchPattern, $index, $maxResults);
		$files = [];
		foreach ($result['files'] as $file) {
			$attributes = is_array($file['attributes'] ?? null) ? $file['attributes'] : [];
			// vCenter returns bare basenames when a matchPattern narrows
			// the listing; re-anchor them on the listed directory.
			$filePath = (string) ($file['path'] ?? '');
			if ($filePath !== '' && $filePath[0] !== '/' && !preg_match('#^[A-Za-z]:[\\\\/]#', $filePath)) {
				$filePath = rtrim($path, '/') . '/' . $filePath;
			}
			$files[] = [
				'path' => $filePath,
				'size' => isset($file['size']) ? (int) $file['size'] : null,
				'type' => (string) ($attributes['type'] ?? ''),
				'modification_time' => (string) ($file['modificationTime'] ?? ''),
			];
		}
		return ['files' => $files, 'new_index' => $result['newIndex'], 'end_of_stream' => $result['endOfStream']];
	}

	/** Shape a raw GuestProcessInfo entry for tool output. */
	private static function shapeProcess(array $proc): array
	{
		$endTime = isset($proc['endTime']) && $proc['endTime'] !== '' ? (string) $proc['endTime'] : null;
		return [
			'pid' => (int) ($proc['pid'] ?? 0),
			'name' => (string) ($proc['name'] ?? ''),
			'cmd_line' => (string) ($proc['cmdLine'] ?? ''),
			'owner' => (string) ($proc['owner'] ?? ''),
			'start_time' => isset($proc['startTime']) && $proc['startTime'] !== '' ? (string) $proc['startTime'] : null,
			'end_time' => $endTime,
			'exit_code' => $endTime !== null && isset($proc['exitCode']) ? (int) $proc['exitCode'] : null,
		];
	}
}
