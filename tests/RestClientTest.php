<?php

use PHPUnit\Framework\TestCase;
use VCenter\Instance;
use VCenter\VCenterException;

require_once __DIR__ . '/lib/FakeHttp.php';

class RestClientTest extends TestCase
{
	private function config(callable $http): array
	{
		return ['http' => $http];
	}

	private function instance(callable $http): Instance
	{
		return new Instance('test', [
			'url' => 'https://vcenter.test',
			'username' => 'vcadmin',
			'password' => 'pw',
		], $http);
	}

	private function fakeSession(FakeHttp $fake): void
	{
		$fake->on('POST', '/api/session', function (array $call) {
			return ['code' => 200, 'body' => '"token-abc"'];
		});
	}

	public function testSessionLoginAndTokenHeader(): void
	{
		$fake = new FakeHttp();
		$this->fakeSession($fake);
		$fake->on('GET', '/api/vcenter/datacenter', fn() => ['code' => 200, 'body' => '[{"datacenter":"datacenter-1","name":"DC1"}]']);

		$rest = $this->instance($fake->callable())->rest();
		$result = $rest->get('vcenter/datacenter');

		$this->assertSame('DC1', $result[0]['name']);
		// Session login sent Basic auth
		$login = $fake->calls('POST', '/api/session')[0];
		$this->assertContains('Authorization: Basic ' . base64_encode('vcadmin:pw'), $login['headers']);
		// API call carried the session token
		$apiCall = $fake->calls('GET', '/api/vcenter/datacenter')[0];
		$this->assertContains('vmware-api-session-id: token-abc', $apiCall['headers']);
	}

	public function testReLoginOn401ThenRetry(): void
	{
		$logins = 0;
		$calls = 0;
		$fake = new FakeHttp();
		$fake->on('POST', '/api/session', function () use (&$logins) {
			$logins++;
			return ['code' => 200, 'body' => '"token-' . $logins . '"'];
		});
		$fake->on('GET', '/api/vcenter/vm', function (array $call) use (&$calls) {
			$calls++;
			if (in_array('vmware-api-session-id: token-1', $call['headers'], true)) {
				return ['code' => 401, 'body' => '{"type":"com.vmware.vapi.std.errors.unauthenticated","value":{"messages":[{"default_message":"expired"}]}}'];
			}
			return ['code' => 200, 'body' => '[{"vm":"vm-42","name":"test"}]'];
		});

		$rest = $this->instance($fake->callable())->rest();
		$result = $rest->get('vcenter/vm');

		$this->assertSame('vm-42', $result[0]['vm']);
		$this->assertSame(2, $logins);
		$this->assertSame(2, $calls);
	}

	public function testErrorMappingVapiTypeAndMessage(): void
	{
		$fake = new FakeHttp();
		$this->fakeSession($fake);
		$fake->on('GET', '/api/vcenter/vm', fn() => ['code' => 404, 'body' =>
			'{"type":"com.vmware.vapi.std.errors.not_found","value":{"messages":[{"default_message":"VM not found","id":"msg"}]}}']);

		try {
			$this->instance($fake->callable())->rest()->get('vcenter/vm');
			$this->fail('expected VCenterException');
		} catch (VCenterException $e) {
			$this->assertSame(404, $e->getCode());
			$this->assertSame('com.vmware.vapi.std.errors.not_found', $e->getErrorType());
			$this->assertStringContainsString('VM not found', $e->getMessage());
		}
	}

	public function testLoginFailure(): void
	{
		$fake = new FakeHttp();
		$fake->on('POST', '/api/session', fn() => ['code' => 401, 'body' => '{"type":"com.vmware.vapi.std.errors.unauthenticated","value":{"messages":[{"default_message":"bad credentials"}]}}']);

		$this->expectException(VCenterException::class);
		$this->instance($fake->callable())->rest()->get('vcenter/vm');
	}

	public function testPostSendsJsonBody(): void
	{
		$fake = new FakeHttp();
		$this->fakeSession($fake);
		$fake->on('POST', '/api/vcenter/vm', fn() => ['code' => 200, 'body' => '"vm-42"']);

		$this->instance($fake->callable())->rest()->post('vcenter/vm', ['name' => 'x']);
		$call = $fake->calls('POST', '/api/vcenter/vm')[0];
		$this->assertContains('Content-Type: application/json', $call['headers']);
		$this->assertSame(['name' => 'x'], json_decode($call['body'], true));
	}
}
