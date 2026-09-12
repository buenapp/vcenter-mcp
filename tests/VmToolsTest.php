<?php

use PHPUnit\Framework\TestCase;
use VCenter\InstanceManager;
use VCenter\VCenterException;

require_once __DIR__ . '/lib/FakeHttp.php';
require_once __DIR__ . '/../tools/VmTools.php';

class VmToolsTest extends TestCase
{
	private function manager(callable $http): InstanceManager
	{
		return new InstanceManager([
			'test' => [
				'url' => 'https://vcenter.test',
				'username' => 'vcadmin',
				'password' => 'pw',
				'exclude_hosts' => ['thebe.example.com'],
				'defaults' => ['network' => 'Default'],
			],
		], 'test', $http);
	}

	/** Full placement fake: host list, SOAP datastore prop, datastore GETs, folder, pool, network. */
	private function createFake(): FakeHttp
	{
		$fake = new FakeHttp();
		$fake->on('POST', '/api/session', fn() => ['code' => 200, 'body' => '"tok"']);
		$fixture = fn(string $n) => file_get_contents(__DIR__ . '/fixtures/soap/' . $n);
		$fake->when(fn(array $c) => str_contains($c['body'] ?? '', 'RetrieveServiceContent'),
			fn() => ['code' => 200, 'body' => $fixture('service-content.xml')]);
		$fake->when(fn(array $c) => str_contains($c['body'] ?? '', '<Login '),
			fn() => ['code' => 200, 'body' => $fixture('login-response.xml'), 'headers' => FakeHttp::soapSessionHeaders()]);
		$fake->when(fn(array $c) => str_contains($c['body'] ?? '', 'RetrievePropertiesEx'),
			fn() => ['code' => 200, 'body' => $fixture('retrieve-properties-ex.xml')]);

		$fake->on('GET', '/api/vcenter/host', function (array $c) {
			$hosts = [
				['host' => 'host-9', 'name' => 'thebe.example.com', 'connection_state' => 'CONNECTED', 'power_state' => 'POWERED_ON'],
				['host' => 'host-12', 'name' => 'esx1.example.com', 'connection_state' => 'CONNECTED', 'power_state' => 'POWERED_ON'],
			];
			if (preg_match('/hosts=([a-z0-9-]+)/', $c['url'], $m)) {
				$hosts = array_values(array_filter($hosts, fn($h) => $h['host'] === $m[1]));
			}
			return ['code' => 200, 'body' => json_encode($hosts)];
		});
		$fake->on('GET', '/api/vcenter/datastore/datastore-21',
			fn() => ['code' => 200, 'body' => '{"datastore":"datastore-21","name":"ds1","free_space":100,"capacity":200}']);
		$fake->on('GET', '/api/vcenter/datastore/datastore-22',
			fn() => ['code' => 200, 'body' => '{"datastore":"datastore-22","name":"ds2","free_space":900,"capacity":1000}']);
		$fake->on('GET', '/api/vcenter/network', fn() => ['code' => 200, 'body' =>
			'[{"network":"network-1","name":"Default","type":"STANDARD_PORTGROUP"}]']);
		$fake->on('GET', '/api/vcenter/folder', fn() => ['code' => 200, 'body' =>
			'[{"folder":"group-v1","name":"vm","type":"VIRTUAL_MACHINE"}]']);
		$fake->on('GET', '/api/vcenter/resource-pool', fn() => ['code' => 200, 'body' =>
			'[{"resource_pool":"resgroup-1","name":"Resources"}]']);
		$fake->on('POST', '/api/vcenter/vm', fn() => ['code' => 200, 'body' => '"vm-42"']);
		$fake->on('GET', '/api/vcenter/vm/vm-42', fn() => ['code' => 200, 'body' =>
			'{"name":"t1","power_state":"POWERED_OFF","cpu":{"count":2}}']);

		return $fake;
	}

	public function testCreateVmBodyAssembly(): void
	{
		$fake = $this->createFake();
		$tools = new VmTools($this->manager($fake->callable()));

		$result = $tools->create_vm('t1', iso: '[CDImages] FreeBSD OS/boot.iso');

		$this->assertSame('vm-42', $result['vm']);
		$this->assertSame('t1', $result['summary']['name']);

		$create = $fake->calls('POST', '/api/vcenter/vm')[0];
		$body = json_decode($create['body'], true);

		$this->assertSame('t1', $body['name']);
		$this->assertSame('FREEBSD_64', $body['guest_OS']);
		// Placement: excluded host skipped, most-free datastore picked
		$this->assertSame('host-12', $body['placement']['host']);
		$this->assertSame('datastore-22', $body['placement']['datastore']);
		$this->assertSame('group-v1', $body['placement']['folder']);
		$this->assertSame('resgroup-1', $body['placement']['resource_pool']);
		// Hardware
		$this->assertSame(['count' => 2, 'cores_per_socket' => 1], $body['cpu']);
		$this->assertSame(['size_MiB' => 2048], $body['memory']);
		$this->assertSame([['type' => 'PVSCSI', 'bus' => 0]], $body['scsi_adapters']);
		$this->assertSame(20 * 1073741824, $body['disks'][0]['new_vmdk']['capacity']);
		$this->assertSame('VMXNET3', $body['nics'][0]['type']);
		$this->assertSame('STANDARD_PORTGROUP', $body['nics'][0]['backing']['type']);
		$this->assertSame('network-1', $body['nics'][0]['backing']['network']);
		$this->assertSame('EFI', $body['boot']['type']);
		// ISO: SATA adapter + cdrom + boot order
		$this->assertSame([['type' => 'AHCI', 'bus' => 0]], $body['sata_adapters']);
		$this->assertSame('SATA', $body['cdroms'][0]['type']);
		$this->assertSame('ISO_FILE', $body['cdroms'][0]['backing']['type']);
		$this->assertSame('[CDImages] FreeBSD OS/boot.iso', $body['cdroms'][0]['backing']['iso_file']);
		$this->assertSame([['type' => 'CDROM'], ['type' => 'DISK']], $body['boot_devices']);
	}

	public function testCreateVmExplicitExcludedHostRejected(): void
	{
		$fake = new FakeHttp();
		$fake->on('POST', '/api/session', fn() => ['code' => 200, 'body' => '"tok"']);
		$fake->on('GET', '/api/vcenter/host', fn() => ['code' => 200, 'body' =>
			'[{"host":"host-9","name":"thebe.example.com"}]']);

		$tools = new VmTools($this->manager($fake->callable()));
		$this->expectException(VCenterException::class);
		$this->expectExceptionMessageMatches('/excluded/');
		$tools->create_vm('t1', host: 'thebe.example.com');
	}

	public function testCreateVmWithoutIsoOmitsCdromAndBootDevices(): void
	{
		$fake = $this->createFake();
		$tools = new VmTools($this->manager($fake->callable()));
		$tools->create_vm('t2', disk_gib: 40);

		$body = json_decode($fake->calls('POST', '/api/vcenter/vm')[0]['body'], true);
		$this->assertArrayNotHasKey('cdroms', $body);
		$this->assertArrayNotHasKey('sata_adapters', $body);
		$this->assertArrayNotHasKey('boot_devices', $body);
		$this->assertSame(40 * 1073741824, $body['disks'][0]['new_vmdk']['capacity']);
	}

	public function testCreateVmPoolFallsBackToHostComputeResource(): void
	{
		$fake = new FakeHttp();
		$fake->on('POST', '/api/session', fn() => ['code' => 200, 'body' => '"tok"']);
		$fixture = fn(string $n) => file_get_contents(__DIR__ . '/fixtures/soap/' . $n);
		$fake->when(fn(array $c) => str_contains($c['body'] ?? '', 'RetrieveServiceContent'),
			fn() => ['code' => 200, 'body' => $fixture('service-content.xml')]);
		$fake->when(fn(array $c) => str_contains($c['body'] ?? '', '<Login '),
			fn() => ['code' => 200, 'body' => $fixture('login-response.xml'), 'headers' => FakeHttp::soapSessionHeaders()]);
		// HostSystem.datastore (pickDatastore), then parent + resourcePool (hostResourcePool)
		$fake->when(fn(array $c) => str_contains($c['body'] ?? '', 'RetrievePropertiesEx'),
			fn() => ['code' => 200, 'body' => $fixture('host-resource-pool.xml')]);

		$fake->on('GET', '/api/vcenter/host', fn() => ['code' => 200, 'body' =>
			'[{"host":"host-12","name":"esx1.example.com","connection_state":"CONNECTED","power_state":"POWERED_ON"}]']);
		$fake->on('GET', '/api/vcenter/datastore/datastore-22',
			fn() => ['code' => 200, 'body' => '{"datastore":"datastore-22","name":"ds2","free_space":900,"capacity":1000}']);
		$fake->on('GET', '/api/vcenter/network', fn() => ['code' => 200, 'body' =>
			'[{"network":"network-1","name":"Default","type":"STANDARD_PORTGROUP"}]']);
		$fake->on('GET', '/api/vcenter/folder', fn() => ['code' => 200, 'body' =>
			'[{"folder":"group-v1","name":"vm","type":"VIRTUAL_MACHINE"}]']);
		// REST pool filter returns nothing -> SOAP fallback
		$fake->on('GET', '/api/vcenter/resource-pool', fn() => ['code' => 200, 'body' => '[]']);
		$fake->on('POST', '/api/vcenter/vm', fn() => ['code' => 200, 'body' => '"vm-42"']);
		$fake->on('GET', '/api/vcenter/vm/vm-42', fn() => ['code' => 200, 'body' => '{"name":"t3"}']);

		$tools = new VmTools($this->manager($fake->callable()));
		$result = $tools->create_vm('t3');

		$this->assertSame('resgroup-77', $result['placement']['resource_pool']);
		// the REST filter was scoped to the placed host
		$this->assertStringContainsString('hosts=host-12', $fake->calls('GET', '/api/vcenter/resource-pool')[0]['url']);
	}

	public function testDeleteVmRefusesPoweredOnWithoutForce(): void
	{
		$fake = new FakeHttp();
		$fake->on('POST', '/api/session', fn() => ['code' => 200, 'body' => '"tok"']);
		$fake->on('GET', '/api/vcenter/vm/vm-42/power', fn() => ['code' => 200, 'body' => '{"state":"POWERED_ON"}']);

		$tools = new VmTools($this->manager($fake->callable()));
		$this->expectException(VCenterException::class);
		$tools->delete_vm('vm-42');
	}

	public function testDeleteVmForcePowersOffFirst(): void
	{
		$fake = new FakeHttp();
		$fake->on('POST', '/api/session', fn() => ['code' => 200, 'body' => '"tok"']);
		$fake->on('GET', '/api/vcenter/vm/vm-42/power', fn() => ['code' => 200, 'body' => '{"state":"POWERED_ON"}']);
		$fake->on('POST', '/api/vcenter/vm/vm-42/power', fn() => ['code' => 200, 'body' => '']);
		$fake->on('DELETE', '/api/vcenter/vm/vm-42', fn() => ['code' => 200, 'body' => '']);

		$tools = new VmTools($this->manager($fake->callable()));
		$result = $tools->delete_vm('vm-42', force: true);
		$this->assertTrue($result['deleted']);

		$stop = $fake->calls('POST', '/api/vcenter/vm/vm-42/power')[0];
		$this->assertStringContainsString('action=stop', $stop['url']);
	}
}
