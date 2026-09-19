<?php

use PHPUnit\Framework\TestCase;
use VCenter\GuestOperations;
use VCenter\InstanceManager;
use VCenter\VCenterException;

require_once __DIR__ . '/lib/FakeHttp.php';
require_once __DIR__ . '/../tools/GuestTools.php';

class GuestOpsToolsTest extends TestCase
{
	private function fixture(string $name): string
	{
		return file_get_contents(__DIR__ . '/fixtures/soap/' . $name);
	}

	private function manager(callable $http): InstanceManager
	{
		return new InstanceManager([
			'test' => [
				'url' => 'https://vcenter.test',
				'username' => 'vcadmin',
				'password' => 'pw',
			],
		], 'test', $http);
	}

	/** SOAP handshake routes (RetrieveServiceContent + Login). */
	private function soapHandshake(FakeHttp $fake): void
	{
		$fake->when(
			fn(array $c) => str_contains($c['body'] ?? '', 'RetrieveServiceContent'),
			fn() => ['code' => 200, 'body' => $this->fixture('service-content.xml')]
		);
		$fake->when(
			fn(array $c) => str_contains($c['body'] ?? '', '<Login '),
			fn() => ['code' => 200, 'body' => $this->fixture('login-response.xml'),
				'headers' => FakeHttp::soapSessionHeaders()]
		);
		$fake->when(
			fn(array $c) => str_contains($c['body'] ?? '', '<RetrievePropertiesEx '),
			fn() => ['code' => 200, 'body' => $this->fixture('guest-managers.xml')]
		);
	}

	/** Default guest-ops routes: start, exited process, transfer info, GET body. */
	private function happyGuestRoutes(FakeHttp $fake, string $downloadBody = "hello\n"): void
	{
		$fake->when(
			fn(array $c) => str_contains($c['body'] ?? '', '<StartProgramInGuest '),
			fn() => ['code' => 200, 'body' => $this->fixture('guest-start-program.xml')]
		);
		$fake->when(
			fn(array $c) => str_contains($c['body'] ?? '', '<ListProcessesInGuest '),
			fn() => ['code' => 200, 'body' => $this->fixture('guest-list-processes-exited.xml')]
		);
		$fake->when(
			fn(array $c) => str_contains($c['body'] ?? '', '<InitiateFileTransferFromGuest '),
			fn() => ['code' => 200, 'body' => $this->fixture('guest-transfer-from-info.xml')]
		);
		$fake->when(
			fn(array $c) => str_contains($c['body'] ?? '', '<InitiateFileTransferToGuest '),
			fn() => ['code' => 200, 'body' => $this->fixture('guest-transfer-to-url.xml')]
		);
		$fake->on('GET', 'guestFile', fn() => ['code' => 200, 'body' => $downloadBody]);
		$fake->on('PUT', 'guestFile', fn() => ['code' => 200, 'body' => '']);
	}

	public function testGuestRunCapturesStdoutAndExitCode(): void
	{
		$fake = new FakeHttp();
		$this->soapHandshake($fake);
		$this->happyGuestRoutes($fake);

		$tools = new GuestTools($this->manager($fake->callable()));
		$result = $tools->guest_run('vm-42', 'root', 'secret', command: 'echo hello');

		$this->assertSame('vm-42', $result['vm']);
		$this->assertSame(4242, $result['pid']);
		$this->assertTrue($result['completed']);
		$this->assertSame(0, $result['exit_code']);
		$this->assertSame("hello\n", $result['stdout']);
		$this->assertFalse($result['stdout_truncated']);
		$this->assertSame('2026-09-19T12:00:00Z', $result['start_time']);
		$this->assertSame('2026-09-19T12:00:01Z', $result['end_time']);

		// The guest saw a /bin/sh wrapper with auth and a capture redirect.
		$starts = $fake->calls('POST', 'sdk');
		$start = array_values(array_filter($starts,
			fn($c) => str_contains($c['body'] ?? '', '<StartProgramInGuest ')))[0];
		$this->assertStringContainsString('NamePasswordAuthentication', $start['body']);
		$this->assertStringContainsString('<username>root</username>', $start['body']);
		$this->assertStringContainsString('<password>secret</password>', $start['body']);
		$this->assertStringContainsString('<programPath>/bin/sh</programPath>', $start['body']);
		$this->assertStringContainsString('vcenter-mcp-', $start['body']);
		$this->assertStringContainsString('2&gt;&amp;1', $start['body']);
		// Brace group keeps the redirect off the last &&/|| operand only.
		$this->assertStringContainsString('; } &gt;', $start['body']);

		// Cleanup rm is a second StartProgramInGuest.
		$this->assertCount(2, array_filter($starts,
			fn($c) => str_contains($c['body'] ?? '', '<StartProgramInGuest ')));
	}

	public function testGuestRunWithoutCaptureStartsProgramDirectly(): void
	{
		$fake = new FakeHttp();
		$this->soapHandshake($fake);
		$this->happyGuestRoutes($fake);

		$tools = new GuestTools($this->manager($fake->callable()));
		$result = $tools->guest_run('vm-42', 'root', 'secret',
			program: '/bin/touch', arguments: '/tmp/marker', capture_output: false);

		$this->assertTrue($result['completed']);
		$this->assertSame(0, $result['exit_code']);
		$this->assertArrayNotHasKey('stdout', $result);

		$start = array_values(array_filter($fake->calls('POST', 'sdk'),
			fn($c) => str_contains($c['body'] ?? '', '<StartProgramInGuest ')))[0];
		$this->assertStringContainsString('<programPath>/bin/touch</programPath>', $start['body']);
		$this->assertStringNotContainsString('/bin/sh', $start['body']);
		// No capture file was ever transferred.
		$this->assertCount(0, array_filter($fake->calls('POST', 'sdk'),
			fn($c) => str_contains($c['body'] ?? '', 'InitiateFileTransfer')));
	}

	public function testGuestRunTimeoutReportsIncomplete(): void
	{
		$fake = new FakeHttp();
		$this->soapHandshake($fake);
		$fake->when(
			fn(array $c) => str_contains($c['body'] ?? '', '<StartProgramInGuest '),
			fn() => ['code' => 200, 'body' => $this->fixture('guest-start-program.xml')]
		);
		$fake->when(
			fn(array $c) => str_contains($c['body'] ?? '', '<ListProcessesInGuest '),
			fn() => ['code' => 200, 'body' => $this->fixture('guest-list-processes-running.xml')]
		);

		$tools = new GuestTools($this->manager($fake->callable()));
		$result = $tools->guest_run('vm-42', 'root', 'secret',
			command: 'sleep 60', capture_output: false, timeout: 1);

		$this->assertFalse($result['completed']);
		$this->assertNull($result['exit_code']);
		$this->assertStringContainsString('still running', $result['note']);
		$this->assertStringContainsString('4242', $result['note']);
	}

	public function testGuestRunRequiresExactlyOneOfCommandOrProgram(): void
	{
		$tools = new GuestTools($this->manager((new FakeHttp())->callable()));

		$this->expectException(VCenterException::class);
		$tools->guest_run('vm-42', 'root', 'secret');
	}

	public function testGuestUploadPutsContentThroughVCenter(): void
	{
		$fake = new FakeHttp();
		$this->soapHandshake($fake);
		$this->happyGuestRoutes($fake);

		$tools = new GuestTools($this->manager($fake->callable()));
		$result = $tools->guest_upload('vm-42', 'root', 'secret', '/root/id.pub', content: 'ssh-ed25519 AAAA test');

		$this->assertSame(strlen('ssh-ed25519 AAAA test'), $result['bytes_written']);
		$this->assertSame('/root/id.pub', $result['guest_path']);

		$puts = $fake->calls('PUT', 'guestFile');
		$this->assertCount(1, $puts);
		$this->assertSame('https://vcenter.test:443/guestFile?id=9284&token=ca525e80-fake-token', $puts[0]['url']);
		$this->assertSame('ssh-ed25519 AAAA test', $puts[0]['body']);
		$this->assertContains('Content-Type: application/octet-stream', $puts[0]['headers']);
	}

	public function testGuestUploadFromLocalPath(): void
	{
		$fake = new FakeHttp();
		$this->soapHandshake($fake);
		$this->happyGuestRoutes($fake);

		$tmp = tempnam(sys_get_temp_dir(), 'guest-upload-');
		file_put_contents($tmp, "line1\nline2\n");
		try {
			$tools = new GuestTools($this->manager($fake->callable()));
			$result = $tools->guest_upload('vm-42', 'root', 'secret', '/tmp/from-local.txt', local_path: $tmp);
			$this->assertSame(strlen("line1\nline2\n"), $result['bytes_written']);
			$this->assertSame("line1\nline2\n", $fake->calls('PUT', 'guestFile')[0]['body']);
		} finally {
			unlink($tmp);
		}
	}

	public function testGuestUploadRejectsMultipleSources(): void
	{
		$tools = new GuestTools($this->manager((new FakeHttp())->callable()));

		$this->expectException(VCenterException::class);
		$tools->guest_upload('vm-42', 'root', 'secret', '/tmp/x', content: 'a', content_base64: 'YQ==');
	}

	public function testGuestDownloadReturnsTextContent(): void
	{
		$fake = new FakeHttp();
		$this->soapHandshake($fake);
		$this->happyGuestRoutes($fake, "hello\n");

		$tools = new GuestTools($this->manager($fake->callable()));
		$result = $tools->guest_download('vm-42', 'root', 'secret', '/etc/motd');

		$this->assertSame('text', $result['encoding']);
		$this->assertSame("hello\n", $result['content']);
		$this->assertSame(6, $result['size']);
		$this->assertFalse($result['truncated']);
	}

	public function testGuestDownloadBinaryReturnsBase64(): void
	{
		$fake = new FakeHttp();
		$this->soapHandshake($fake);
		$this->happyGuestRoutes($fake, "\x00\x01\x02\xFF");

		$tools = new GuestTools($this->manager($fake->callable()));
		$result = $tools->guest_download('vm-42', 'root', 'secret', '/bin/ls');

		$this->assertSame('base64', $result['encoding']);
		$this->assertSame(base64_encode("\x00\x01\x02\xFF"), $result['content']);
	}

	public function testGuestDownloadToLocalPath(): void
	{
		$fake = new FakeHttp();
		$this->soapHandshake($fake);
		$this->happyGuestRoutes($fake, "payload");

		$tmp = tempnam(sys_get_temp_dir(), 'guest-download-');
		try {
			$tools = new GuestTools($this->manager($fake->callable()));
			$result = $tools->guest_download('vm-42', 'root', 'secret', '/tmp/src', local_path: $tmp);
			$this->assertSame($tmp, $result['local_path']);
			$this->assertSame('payload', file_get_contents($tmp));
			$this->assertSame(strlen('payload'), $result['bytes_written']);
		} finally {
			unlink($tmp);
		}
	}

	public function testGuestListFilesShapesEntries(): void
	{
		$fake = new FakeHttp();
		$this->soapHandshake($fake);
		$fake->when(
			fn(array $c) => str_contains($c['body'] ?? '', '<ListFilesInGuest '),
			fn() => ['code' => 200, 'body' => $this->fixture('guest-list-files.xml')]
		);

		$tools = new GuestTools($this->manager($fake->callable()));
		$result = $tools->guest_list_files('vm-42', 'root', 'secret', '/tmp');

		$this->assertTrue($result['end_of_stream']);
		$this->assertSame(2, $result['new_index']);
		$this->assertCount(2, $result['files']);
		$this->assertSame('/tmp/notes.txt', $result['files'][0]['path']);
		$this->assertSame(42, $result['files'][0]['size']);
		$this->assertSame('file', $result['files'][0]['type']);
		$this->assertSame('directory', $result['files'][1]['type']);

		$list = array_values(array_filter($fake->calls('POST', 'sdk'),
			fn($c) => str_contains($c['body'] ?? '', '<ListFilesInGuest ')))[0];
		$this->assertStringContainsString('<filePath>/tmp</filePath>', $list['body']);
	}

	public function testGuestProcessStatusShapesExitedAndRunning(): void
	{
		$fake = new FakeHttp();
		$this->soapHandshake($fake);
		$fake->when(
			fn(array $c) => str_contains($c['body'] ?? '', '<ListProcessesInGuest '),
			fn() => ['code' => 200, 'body' => $this->fixture('guest-list-processes-multi.xml')]
		);

		$tools = new GuestTools($this->manager($fake->callable()));
		$result = $tools->guest_process_status('vm-42', 'root', 'secret', pids: [1, 4242]);

		$this->assertCount(2, $result['processes']);
		$init = $result['processes'][0];
		$this->assertSame(1, $init['pid']);
		$this->assertNull($init['end_time']);
		$this->assertNull($init['exit_code']);
		$sh = $result['processes'][1];
		$this->assertSame(0, $sh['exit_code']);
		$this->assertSame('2026-09-19T12:00:01Z', $sh['end_time']);

		$list = array_values(array_filter($fake->calls('POST', 'sdk'),
			fn($c) => str_contains($c['body'] ?? '', '<ListProcessesInGuest ')))[0];
		$this->assertStringContainsString('<pids>1</pids><pids>4242</pids>', $list['body']);
	}

	public function testInvalidGuestLoginIsRephrased(): void
	{
		$fake = new FakeHttp();
		$this->soapHandshake($fake);
		$fake->when(
			fn(array $c) => str_contains($c['body'] ?? '', '<StartProgramInGuest '),
			fn() => ['code' => 500, 'body' => $this->fixture('fault-invalid-guest-login.xml')]
		);

		$tools = new GuestTools($this->manager($fake->callable()));
		try {
			$tools->guest_run('vm-42', 'root', 'wrong', command: 'id');
			$this->fail('expected VCenterException');
		} catch (VCenterException $e) {
			$this->assertStringContainsString("guest authentication failed for 'root'", $e->getMessage());
			$this->assertSame('InvalidGuestLogin', $e->getErrorType());
		}
	}

	public function testGuestOperationsUnavailableIsRephrased(): void
	{
		$fake = new FakeHttp();
		$this->soapHandshake($fake);
		$fake->when(
			fn(array $c) => str_contains($c['body'] ?? '', '<StartProgramInGuest '),
			fn() => ['code' => 500, 'body' => $this->fixture('fault-guest-ops-unavailable.xml')]
		);

		$tools = new GuestTools($this->manager($fake->callable()));
		try {
			$tools->guest_run('vm-42', 'root', 'secret', command: 'id');
			$this->fail('expected VCenterException');
		} catch (VCenterException $e) {
			$this->assertStringContainsString('VMware Tools is not running', $e->getMessage());
			$this->assertSame('GuestOperationsUnavailable', $e->getErrorType());
		}
	}

	public function testShQuoteEscapesSingleQuotes(): void
	{
		$this->assertSame("'echo hello'", GuestOperations::shQuote('echo hello'));
		$this->assertSame("'don'\\''t'", GuestOperations::shQuote("don't"));
	}

	public function testAuthXmlEscapesCredentials(): void
	{
		$auth = GuestOperations::authXml('ro<ot', 'p&ss', true);
		$this->assertStringContainsString('NamePasswordAuthentication', $auth);
		$this->assertStringContainsString('<username>ro&lt;ot</username>', $auth);
		$this->assertStringContainsString('<password>p&amp;ss</password>', $auth);
		$this->assertStringContainsString('<interactiveSession>true</interactiveSession>', $auth);
	}
}
