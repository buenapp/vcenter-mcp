<?php

use PHPUnit\Framework\TestCase;
use VCenter\InstanceManager;
use VCenter\VCenterException;

require_once __DIR__ . '/lib/FakeHttp.php';
require_once __DIR__ . '/../tools/PassthroughTools.php';

class PassthroughToolsTest extends TestCase
{
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

	private function fixture(string $name): string
	{
		return file_get_contents(__DIR__ . '/fixtures/soap/' . $name);
	}

	/** REST session + SOAP handshake routes. */
	private function fake(): FakeHttp
	{
		$fake = new FakeHttp();
		$fake->on('POST', '/api/session', fn() => ['code' => 200, 'body' => '"tok"']);
		$fake->when(fn(array $c) => str_contains($c['body'] ?? '', 'RetrieveServiceContent'),
			fn() => ['code' => 200, 'body' => $this->fixture('service-content.xml')]);
		$fake->when(fn(array $c) => str_contains($c['body'] ?? '', '<Login '),
			fn() => ['code' => 200, 'body' => $this->fixture('login-response.xml'),
				'headers' => FakeHttp::soapSessionHeaders()]);
		$fake->on('GET', '/api/vcenter/host',
			fn() => ['code' => 200, 'body' => '[{"host":"host-12","name":"esx-gpu1.example.com"}]']);
		return $fake;
	}

	private function hostPciRoute(FakeHttp $fake): void
	{
		$fake->when(fn(array $c) => str_contains($c['body'] ?? '', 'config.pciPassthruInfo'),
			fn() => ['code' => 200, 'body' => $this->fixture('host-pci.xml')]);
	}

	/** ReconfigVM_Task + successful task info routes. */
	private function reconfigRoutes(FakeHttp $fake): void
	{
		$fake->when(fn(array $c) => str_contains($c['body'] ?? '', 'ReconfigVM_Task'),
			fn() => ['code' => 200, 'body' => '<?xml version="1.0"?>'
				. '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Body>'
				. '<ReconfigVM_TaskResponse xmlns="urn:vim25"><returnval type="Task">task-7</returnval></ReconfigVM_TaskResponse>'
				. '</soapenv:Body></soapenv:Envelope>']);
		$fake->when(fn(array $c) => str_contains($c['body'] ?? '', '<pathSet>info</pathSet>'),
			fn() => ['code' => 200, 'body' => '<?xml version="1.0"?>'
				. '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Body>'
				. '<RetrievePropertiesExResponse xmlns="urn:vim25"><returnval><objects>'
				. '<obj type="Task">task-7</obj>'
				. '<propSet><name>info</name><val><state>success</state></val></propSet>'
				. '</objects></returnval></RetrievePropertiesExResponse></soapenv:Body></soapenv:Envelope>']);
	}

	private function powerOffRoute(FakeHttp $fake): void
	{
		$fake->on('GET', '/api/vcenter/vm/vm-42/power',
			fn() => ['code' => 200, 'body' => '{"state":"POWERED_OFF"}']);
	}

	private function reconfigCalls(FakeHttp $fake): array
	{
		return array_values(array_filter($fake->log,
			fn($c) => str_contains($c['body'] ?? '', 'ReconfigVM_Task')));
	}

	public function testListHostPciDefaultsToPassthroughEnabled(): void
	{
		$fake = $this->fake();
		$this->hostPciRoute($fake);

		$tools = new PassthroughTools($this->manager($fake->callable()));
		$result = $tools->list_host_pci('esx-gpu1.example.com');

		$this->assertSame('host-12', $result['host']);
		$this->assertCount(2, $result['devices']);
		$this->assertSame('0000:63:00.0', $result['devices'][0]['id']);
		$this->assertSame('GP104GL [Tesla P4]', $result['devices'][0]['name']);
		$this->assertSame('10de', $result['devices'][0]['vendor_id']);
		$this->assertSame('1bb3', $result['devices'][0]['device_id']);
		$this->assertSame('0302', $result['devices'][0]['class']);
		$this->assertTrue($result['devices'][0]['passthru_enabled']);
	}

	public function testListHostPciFullTable(): void
	{
		$fake = $this->fake();
		$this->hostPciRoute($fake);

		$tools = new PassthroughTools($this->manager($fake->callable()));
		$result = $tools->list_host_pci('host-12', false);

		$this->assertCount(3, $result['devices']);
		$nic = $result['devices'][2];
		$this->assertSame('ntg3', $nic['driver']);
		$this->assertFalse($nic['passthru_capable']);
		$this->assertFalse($nic['passthru_enabled']);
	}

	public function testListVmPassthroughDevicesShapesBacking(): void
	{
		$fake = $this->fake();
		$fake->when(fn(array $c) => str_contains($c['body'] ?? '', 'config.hardware.device'),
			fn() => ['code' => 200, 'body' => $this->fixture('config-hardware-passthrough.xml')]);

		$tools = new PassthroughTools($this->manager($fake->callable()));
		$result = $tools->list_passthrough_devices('vm-42');

		$this->assertSame('vm-42', $result['vm']);
		$this->assertCount(1, $result['devices']);
		$this->assertSame('13000', $result['devices'][0]['key']);
		$this->assertSame('0000:63:00.0', $result['devices'][0]['pci_id']);
		$this->assertSame('1bb3', $result['devices'][0]['device_id']);
		$this->assertSame('Tesla P4 on esx-gpu1', $result['devices'][0]['custom_label']);
	}

	public function testAttachPciBuildsBackingOnVmRuntimeHost(): void
	{
		$fake = $this->fake();
		$this->powerOffRoute($fake);
		$this->hostPciRoute($fake);
		$this->reconfigRoutes($fake);
		$fake->when(fn(array $c) => str_contains($c['body'] ?? '', 'runtime.host'),
			fn() => ['code' => 200, 'body' => $this->fixture('vm-runtime-host.xml')]);
		$fake->when(fn(array $c) => $c['method'] === 'GET' && str_contains($c['url'], '/api/vcenter/vm') && str_contains($c['url'], 'hosts='),
			fn() => ['code' => 200, 'body' => '[{"vm":"vm-42","name":"gpu-vm"}]']);
		$fake->when(fn(array $c) => str_contains($c['body'] ?? '', 'config.hardware.device'),
			fn() => ['code' => 200, 'body' => $this->fixture('config-hardware-passthrough.xml')]);

		$tools = new PassthroughTools($this->manager($fake->callable()));
		$result = $tools->attach_pci('vm-42', '0000:63:00.0');

		$this->assertTrue($result['attached']);
		$this->assertSame('host-12', $result['host']);
		$this->assertSame('13000', $result['key']);

		$reconfig = $this->reconfigCalls($fake)[0];
		$this->assertStringContainsString('VirtualPCIPassthroughDynamicBackingInfo', $reconfig['body']);
		$this->assertStringContainsString('<id>0000:63:00.0</id>', $reconfig['body']);
		$this->assertStringContainsString('<deviceId>7091</deviceId>', $reconfig['body']);
		$this->assertStringContainsString('<memoryReservationLockedToMax>true</memoryReservationLockedToMax>', $reconfig['body']);
	}

	public function testAttachPciRefusesPoweredOnVm(): void
	{
		$fake = $this->fake();
		$fake->on('GET', '/api/vcenter/vm/vm-42/power',
			fn() => ['code' => 200, 'body' => '{"state":"POWERED_ON"}']);

		$tools = new PassthroughTools($this->manager($fake->callable()));
		try {
			$tools->attach_pci('vm-42', '0000:63:00.0');
			$this->fail('expected VCenterException');
		} catch (VCenterException $e) {
			$this->assertSame('PowerStateError', $e->getErrorType());
		}
		$this->assertSame([], $this->reconfigCalls($fake));
	}

	public function testAttachPciRefusesDeviceAlreadyAttachedElsewhere(): void
	{
		$fake = $this->fake();
		$this->powerOffRoute($fake);
		$this->hostPciRoute($fake);
		$this->reconfigRoutes($fake);
		$fake->when(fn(array $c) => str_contains($c['body'] ?? '', 'runtime.host'),
			fn() => ['code' => 200, 'body' => $this->fixture('vm-runtime-host.xml')]);
		$fake->when(fn(array $c) => $c['method'] === 'GET' && str_contains($c['url'], '/api/vcenter/vm') && str_contains($c['url'], 'hosts='),
			fn() => ['code' => 200, 'body' => '[{"vm":"vm-42"},{"vm":"vm-43","name":"old-gpu-vm"}]']);
		$fake->when(fn(array $c) => str_contains($c['body'] ?? '', 'config.hardware.device')
				&& str_contains($c['body'] ?? '', '>vm-43<'),
			fn() => ['code' => 200, 'body' => $this->fixture('config-hardware-passthrough.xml')]);

		$tools = new PassthroughTools($this->manager($fake->callable()));
		try {
			$tools->attach_pci('vm-42', '0000:63:00.0');
			$this->fail('expected VCenterException');
		} catch (VCenterException $e) {
			$this->assertSame('DeviceInUse', $e->getErrorType());
			$this->assertStringContainsString('old-gpu-vm', $e->getMessage());
		}
		$this->assertSame([], $this->reconfigCalls($fake));
	}

	public function testAttachPciAmbiguousDeviceName(): void
	{
		$fake = $this->fake();
		$this->powerOffRoute($fake);
		$this->hostPciRoute($fake);
		$fake->when(fn(array $c) => str_contains($c['body'] ?? '', 'runtime.host'),
			fn() => ['code' => 200, 'body' => $this->fixture('vm-runtime-host.xml')]);

		$tools = new PassthroughTools($this->manager($fake->callable()));
		try {
			$tools->attach_pci('vm-42', 'Tesla');
			$this->fail('expected VCenterException');
		} catch (VCenterException $e) {
			$this->assertSame('Ambiguous', $e->getErrorType());
		}
		$this->assertSame([], $this->reconfigCalls($fake));
	}

	public function testAttachPciRejectsNonCapableDevice(): void
	{
		$fake = $this->fake();
		$this->powerOffRoute($fake);
		$this->hostPciRoute($fake);
		$fake->when(fn(array $c) => str_contains($c['body'] ?? '', 'runtime.host'),
			fn() => ['code' => 200, 'body' => $this->fixture('vm-runtime-host.xml')]);

		$tools = new PassthroughTools($this->manager($fake->callable()));
		try {
			$tools->attach_pci('vm-42', '0000:00:1f.6');
			$this->fail('expected VCenterException');
		} catch (VCenterException $e) {
			$this->assertSame('Unsupported', $e->getErrorType());
			$this->assertStringContainsString('not passthrough capable', $e->getMessage());
		}
		$this->assertSame([], $this->reconfigCalls($fake));
	}

	public function testDetachPciRemovesByPciId(): void
	{
		$fake = $this->fake();
		$this->powerOffRoute($fake);
		$this->reconfigRoutes($fake);
		$deviceReads = 0;
		$fake->when(fn(array $c) => str_contains($c['body'] ?? '', 'config.hardware.device'),
			function () use (&$deviceReads) {
				$deviceReads++;
				// After the detach the device is gone from the listing
				return ['code' => 200, 'body' => $this->fixture(
					$deviceReads === 1 ? 'config-hardware-passthrough.xml' : 'config-hardware-device.xml')];
			});

		$tools = new PassthroughTools($this->manager($fake->callable()));
		$result = $tools->detach_pci('vm-42', '0000:63:00.0');

		$this->assertTrue($result['detached']);
		$this->assertSame('13000', $result['key']);

		$reconfig = $this->reconfigCalls($fake)[0];
		$this->assertStringContainsString('<operation>remove</operation>', $reconfig['body']);
		$this->assertStringContainsString('<key>13000</key>', $reconfig['body']);
	}

	public function testDetachPciRefusesPoweredOnVm(): void
	{
		$fake = $this->fake();
		$fake->on('GET', '/api/vcenter/vm/vm-42/power',
			fn() => ['code' => 200, 'body' => '{"state":"SUSPENDED"}']);

		$tools = new PassthroughTools($this->manager($fake->callable()));
		try {
			$tools->detach_pci('vm-42', '0000:63:00.0');
			$this->fail('expected VCenterException');
		} catch (VCenterException $e) {
			$this->assertSame('PowerStateError', $e->getErrorType());
			$this->assertStringContainsString('SUSPENDED', $e->getMessage());
		}
		$this->assertSame([], $this->reconfigCalls($fake));
	}
}
