<?php

use PHPUnit\Framework\TestCase;
use VCenter\Instance;
use VCenter\SoapClient;
use VCenter\VCenterException;

require_once __DIR__ . '/lib/FakeHttp.php';

class SoapClientTest extends TestCase
{
	private function fixture(string $name): string
	{
		return file_get_contents(__DIR__ . '/fixtures/soap/' . $name);
	}

	private function instance(callable $http): Instance
	{
		return new Instance('test', [
			'url' => 'https://vcenter.test',
			'username' => 'vcadmin',
			'password' => 'pw',
		], $http);
	}

	/** Fake wired for the standard handshake: service content + login. */
	private function soapFake(FakeHttp $fake): void
	{
		$fake->when(
			fn(array $c) => str_contains($c['url'], '/sdk') && str_contains($c['body'] ?? '', 'RetrieveServiceContent'),
			fn() => ['code' => 200, 'body' => $this->fixture('service-content.xml')]
		);
		$fake->when(
			fn(array $c) => str_contains($c['url'], '/sdk') && str_contains($c['body'] ?? '', '<Login '),
			fn() => ['code' => 200, 'body' => $this->fixture('login-response.xml'), 'headers' => FakeHttp::soapSessionHeaders()]
		);
	}

	public function testEnvelopeShape(): void
	{
		$fake = new FakeHttp();
		$this->soapFake($fake);
		$fake->when(
			fn(array $c) => str_contains($c['body'] ?? '', 'RetrievePropertiesEx'),
			function (array $c) {
				$this->assertStringContainsString('soapenv:Envelope', $c['body']);
				$this->assertStringContainsString('urn:vim25', $c['body']);
				$this->assertContains('SOAPAction: "urn:vim25/8.0.0.0"', $c['headers']);
				$this->assertStringContainsString('<_this type="PropertyCollector">propertyCollector</_this>', $c['body']);
				return ['code' => 200, 'body' => $this->fixture('retrieve-properties-ex.xml')];
			}
		);

		$soap = $this->instance($fake->callable())->soap();
		$rows = $soap->properties([['type' => 'HostSystem', 'id' => 'host-12']], ['HostSystem' => ['name']]);
		$this->assertArrayHasKey('HostSystem:host-12', $rows);
	}

	public function testServiceContentParsing(): void
	{
		$fake = new FakeHttp();
		$this->soapFake($fake);
		$soap = $this->instance($fake->callable())->soap();
		$content = $soap->serviceContent();

		$this->assertSame('group-d1', $content['rootFolder']['id']);
		$this->assertSame('SessionManager', $content['sessionManager']['id']);
		$this->assertSame('8.0.3', $content['about']['version']);
		$this->assertSame('24345018', $content['about']['build']);
	}

	public function testLoginSetsSessionCookieOnSubsequentCalls(): void
	{
		$fake = new FakeHttp();
		$this->soapFake($fake);
		$fake->when(
			fn(array $c) => str_contains($c['body'] ?? '', 'RetrievePropertiesEx'),
			function (array $c) {
				$this->assertContains(
					'Cookie: vmware_soap_session="5271f3e6-9b3d-4d7a-a1c2-0123456789ab"',
					$c['headers']
				);
				return ['code' => 200, 'body' => $this->fixture('retrieve-properties-ex.xml')];
			}
		);

		$soap = $this->instance($fake->callable())->soap();
		$soap->properties([['type' => 'Task', 'id' => 'task-99']], ['Task' => ['info']]);
	}

	public function testFaultParsing(): void
	{
		$fake = new FakeHttp();
		$fake->when(
			fn(array $c) => str_contains($c['url'], '/sdk'),
			fn() => ['code' => 500, 'body' => $this->fixture('fault-not-authenticated.xml')]
		);

		try {
			$this->instance($fake->callable())->soap()->serviceContent();
			$this->fail('expected VCenterException');
		} catch (VCenterException $e) {
			$this->assertStringContainsString('not authenticated', $e->getMessage());
		}
	}

	public function testReLoginOnNotAuthenticated(): void
	{
		$fake = new FakeHttp();
		$logins = 0;
		$fake->when(
			fn(array $c) => str_contains($c['url'], '/sdk') && str_contains($c['body'] ?? '', 'RetrieveServiceContent'),
			fn() => ['code' => 200, 'body' => $this->fixture('service-content.xml')]
		);
		$fake->when(
			fn(array $c) => str_contains($c['body'] ?? '', '<Login '),
			function () use (&$logins) {
				$logins++;
				return ['code' => 200, 'body' => $this->fixture('login-response.xml'), 'headers' => FakeHttp::soapSessionHeaders()];
			}
		);
		$calls = 0;
		$fake->when(
			fn(array $c) => str_contains($c['body'] ?? '', 'RetrievePropertiesEx'),
			function () use (&$calls) {
				$calls++;
				if ($calls === 1) {
					return ['code' => 500, 'body' => $this->fixture('fault-not-authenticated.xml')];
				}
				return ['code' => 200, 'body' => $this->fixture('retrieve-properties-ex.xml')];
			}
		);

		$soap = $this->instance($fake->callable())->soap();
		$rows = $soap->properties([['type' => 'Task', 'id' => 'task-99']], ['Task' => ['info']]);
		$this->assertSame(2, $logins);
		$this->assertSame(2, $calls);
		$this->assertNotEmpty($rows);
	}

	public function testSearchDatastoreBuildsSpecAndReturnsTask(): void
	{
		$fake = new FakeHttp();
		$this->soapFake($fake);
		$fake->when(
			fn(array $c) => str_contains($c['body'] ?? '', 'SearchDatastore_Task'),
			function (array $c) {
				$this->assertStringContainsString('<datastorePath>[CDImages] FreeBSD OS</datastorePath>', $c['body']);
				$this->assertStringContainsString('<matchPattern>*.iso</matchPattern>', $c['body']);
				return ['code' => 200, 'body' => '<?xml version="1.0"?><soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Body><SearchDatastore_TaskResponse xmlns="urn:vim25"><returnval type="Task">task-99</returnval></SearchDatastore_TaskResponse></soapenv:Body></soapenv:Envelope>'];
			}
		);

		$task = $this->instance($fake->callable())->soap()
			->searchDatastore(['type' => 'HostDatastoreBrowser', 'id' => 'hostdatastorebrowser-1'], '[CDImages] FreeBSD OS', '*.iso');
		$this->assertSame('task-99', $task['id']);
		$this->assertSame('Task', $task['type']);
	}

	public function testLoginWithoutSessionCookieFails(): void
	{
		$fake = new FakeHttp();
		$fake->when(
			fn(array $c) => str_contains($c['body'] ?? '', 'RetrieveServiceContent'),
			fn() => ['code' => 200, 'body' => $this->fixture('service-content.xml')]
		);
		// Login body is fine but carries no Set-Cookie — the session key
		// must come from the cookie, never from UserSession.key.
		$fake->when(
			fn(array $c) => str_contains($c['body'] ?? '', '<Login '),
			fn() => ['code' => 200, 'body' => $this->fixture('login-response.xml')]
		);

		$this->expectException(VCenterException::class);
		$this->expectExceptionMessageMatches('/no vmware_soap_session cookie/');
		$this->instance($fake->callable())->soap()->login();
	}

	public function testMissingSetFaultSurfaces(): void
	{
		$fake = new FakeHttp();
		$this->soapFake($fake);
		$fake->when(
			fn(array $c) => str_contains($c['body'] ?? '', 'RetrievePropertiesEx'),
			fn() => ['code' => 200, 'body' => $this->fixture('missing-set-fault.xml')]
		);

		try {
			$this->instance($fake->callable())->properties()
				->get('Datastore', 'datastore-1166', ['browser']);
			$this->fail('expected VCenterException');
		} catch (VCenterException $e) {
			$this->assertStringContainsString("'browser'", $e->getMessage());
			$this->assertStringContainsString('Datastore:datastore-1166', $e->getMessage());
			$this->assertStringContainsString('NotAuthenticated', $e->getMessage());
			$this->assertStringContainsString('System.Read', $e->getMessage());
		}
	}

	public function testMissingSetBenignDoesNotThrow(): void
	{
		$fake = new FakeHttp();
		$this->soapFake($fake);
		$fake->when(
			fn(array $c) => str_contains($c['body'] ?? '', 'RetrievePropertiesEx'),
			fn() => ['code' => 200, 'body' => $this->fixture('missing-set-benign.xml')]
		);

		$rows = $this->instance($fake->callable())->properties()
			->get('VirtualMachine', 'vm-42', ['name', 'runtime.question', 'runtime.offlineReason']);
		$this->assertSame('testvm', $rows['name']);
		$this->assertArrayNotHasKey('runtime.question', $rows);
	}
}
