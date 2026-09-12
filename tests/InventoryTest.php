<?php

use PHPUnit\Framework\TestCase;
use VCenter\Instance;
use VCenter\VCenterException;

require_once __DIR__ . '/lib/FakeHttp.php';

class InventoryTest extends TestCase
{
	private function instance(callable $http): Instance
	{
		return new Instance('test', [
			'url' => 'https://vcenter.test',
			'username' => 'vcadmin',
			'password' => 'pw',
			'exclude_hosts' => ['thebe.example.com'],
		], $http);
	}

	private function fakeWith(FakeHttp $fake, array $lists): Instance
	{
		$fake->on('POST', '/api/session', fn() => ['code' => 200, 'body' => '"tok"']);
		foreach ($lists as $urlPart => $rows) {
			$fake->on('GET', $urlPart, is_callable($rows) ? $rows : fn() => ['code' => 200, 'body' => json_encode($rows)]);
		}
		return $this->instance($fake->callable());
	}

	public function testResolveByName(): void
	{
		$fake = new FakeHttp();
		$inst = $this->fakeWith($fake, [
			'/api/vcenter/datastore' => fn(array $c) => str_contains($c['url'], 'names=CDImages')
				? ['code' => 200, 'body' => '[{"datastore":"datastore-21","name":"CDImages","type":"NFS"}]']
				: ['code' => 200, 'body' => '[]'],
		]);

		$ds = $inst->inventory()->resolveDatastore('CDImages');
		$this->assertSame('datastore-21', $ds['datastore']);
	}

	public function testResolveByMoRefPassthrough(): void
	{
		$fake = new FakeHttp();
		$inst = $this->fakeWith($fake, []);
		$ds = $inst->inventory()->resolveDatastore('datastore-21');
		$this->assertSame('datastore-21', $ds['datastore']);
		// No datastore list call should have been made
		$this->assertCount(0, $fake->calls('GET', '/api/vcenter/datastore?'));
	}

	public function testAmbiguousNameIsError(): void
	{
		$fake = new FakeHttp();
		$inst = $this->fakeWith($fake, [
			'/api/vcenter/network' => [['network' => 'network-1', 'name' => 'Default'], ['network' => 'dvportgroup-5', 'name' => 'Default']],
		]);

		try {
			$inst->inventory()->resolveNetwork('Default');
			$this->fail('expected VCenterException');
		} catch (VCenterException $e) {
			$this->assertSame('Ambiguous', $e->getErrorType());
			$this->assertStringContainsString('network-1', $e->getMessage());
			$this->assertStringContainsString('dvportgroup-5', $e->getMessage());
		}
	}

	public function testUnknownNameIsError(): void
	{
		$fake = new FakeHttp();
		$inst = $this->fakeWith($fake, ['/api/vcenter/network' => []]);
		$this->expectException(VCenterException::class);
		$inst->inventory()->resolveNetwork('Nope');
	}

	public function testExcludedHostRejectedByName(): void
	{
		$fake = new FakeHttp();
		$inst = $this->fakeWith($fake, [
			'/api/vcenter/host' => fn(array $c) => str_contains($c['url'], 'names=thebe')
				? ['code' => 200, 'body' => '[{"host":"host-9","name":"thebe.example.com","connection_state":"CONNECTED","power_state":"POWERED_ON"}]']
				: ['code' => 200, 'body' => '[]'],
		]);

		try {
			$inst->inventory()->resolveHost('thebe.example.com');
			$this->fail('expected VCenterException');
		} catch (VCenterException $e) {
			$this->assertSame('HostExcluded', $e->getErrorType());
		}
	}

	public function testExcludedHostRejectedCaseInsensitiveByMoRef(): void
	{
		$fake = new FakeHttp();
		$inst = $this->fakeWith($fake, [
			'/api/vcenter/host' => [['host' => 'host-9', 'name' => 'TheBe.Example.COM']],
		]);

		$this->expectException(VCenterException::class);
		$inst->inventory()->resolveHost('host-9');
	}

	public function testPickHostSkipsExcludedAndDown(): void
	{
		$fake = new FakeHttp();
		$inst = $this->fakeWith($fake, [
			'/api/vcenter/host' => [
				['host' => 'host-9', 'name' => 'thebe.example.com', 'connection_state' => 'CONNECTED', 'power_state' => 'POWERED_ON'],
				['host' => 'host-8', 'name' => 'esx-down.example.com', 'connection_state' => 'DISCONNECTED', 'power_state' => 'POWERED_OFF'],
				['host' => 'host-7', 'name' => 'esx1.example.com', 'connection_state' => 'CONNECTED', 'power_state' => 'POWERED_ON'],
			],
		]);

		$pick = $inst->inventory()->pickHost();
		$this->assertSame('host-7', $pick['host']);
	}

	public function testPickHostNoneEligible(): void
	{
		$fake = new FakeHttp();
		$inst = $this->fakeWith($fake, [
			'/api/vcenter/host' => [
				['host' => 'host-9', 'name' => 'thebe.example.com', 'connection_state' => 'CONNECTED', 'power_state' => 'POWERED_ON'],
			],
		]);
		$this->expectException(VCenterException::class);
		$inst->inventory()->pickHost();
	}

	public function testPickDatastoreMostFree(): void
	{
		$fake = new FakeHttp();
		$fake->on('POST', '/api/session', fn() => ['code' => 200, 'body' => '"tok"']);
		// SOAP: handshake + HostSystem datastore property
		$fixture = fn(string $n) => file_get_contents(__DIR__ . '/fixtures/soap/' . $n);
		$fake->when(fn(array $c) => str_contains($c['body'] ?? '', 'RetrieveServiceContent'),
			fn() => ['code' => 200, 'body' => $fixture('service-content.xml')]);
		$fake->when(fn(array $c) => str_contains($c['body'] ?? '', '<Login '),
			fn() => ['code' => 200, 'body' => $fixture('login-response.xml'), 'headers' => FakeHttp::soapSessionHeaders()]);
		$fake->when(fn(array $c) => str_contains($c['body'] ?? '', 'RetrievePropertiesEx'),
			fn() => ['code' => 200, 'body' => $fixture('retrieve-properties-ex.xml')]);
		$fake->on('GET', '/api/vcenter/datastore/datastore-21',
			fn() => ['code' => 200, 'body' => '{"datastore":"datastore-21","name":"ds-small","free_space":100,"capacity":200}']);
		$fake->on('GET', '/api/vcenter/datastore/datastore-22',
			fn() => ['code' => 200, 'body' => '{"datastore":"datastore-22","name":"ds-big","free_space":900,"capacity":1000}']);

		$best = $this->instance($fake->callable())->inventory()->pickDatastore('host-12');
		$this->assertSame('datastore-22', $best['datastore']);
	}
}
