<?php

use PHPUnit\Framework\TestCase;
use VCenter\InstanceManager;

require_once __DIR__ . '/lib/FakeHttp.php';
require_once __DIR__ . '/../tools/DeviceTools.php';

class DeviceToolsTest extends TestCase
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

	private function fake(): FakeHttp
	{
		$fake = new FakeHttp();
		$fake->on('POST', '/api/session', fn() => ['code' => 200, 'body' => '"tok"']);
		return $fake;
	}

	public function testAttachIsoPatchesExistingCdromAndConnectsWhenOn(): void
	{
		$fake = $this->fake();
		$fake->on('PATCH', '/api/vcenter/vm/vm-42/hardware/cdrom/16000', fn() => ['code' => 200, 'body' => '']);
		$fake->on('GET', '/api/vcenter/vm/vm-42/power', fn() => ['code' => 200, 'body' => '{"state":"POWERED_ON"}']);
		$fake->on('POST', '/api/vcenter/vm/vm-42/hardware/cdrom/16000', fn() => ['code' => 200, 'body' => '']);

		$tools = new DeviceTools($this->manager($fake->callable()));
		$result = $tools->attach_iso('vm-42', '[CDImages] x.iso', '16000');

		$this->assertSame('16000', $result['cdrom']);
		$this->assertTrue($result['connected']);

		$patch = $fake->calls('PATCH', '/hardware/cdrom/16000')[0];
		$body = json_decode($patch['body'], true);
		$this->assertSame('ISO_FILE', $body['backing']['type']);
		$this->assertSame('[CDImages] x.iso', $body['backing']['iso_file']);

		$connect = $fake->calls('POST', '/hardware/cdrom/16000')[0];
		$this->assertStringContainsString('action=connect', $connect['url']);
	}

	public function testAttachIsoCreatesSataCdromWhenNoneGiven(): void
	{
		$fake = $this->fake();
		$fake->on('GET', '/api/vcenter/vm/vm-42/hardware/cdrom', fn() => ['code' => 200, 'body' => '[]']);
		$fake->on('POST', '/api/vcenter/vm/vm-42/hardware/cdrom', fn() => ['code' => 200, 'body' => '"16000"']);
		$fake->on('GET', '/api/vcenter/vm/vm-42/power', fn() => ['code' => 200, 'body' => '{"state":"POWERED_OFF"}']);

		$tools = new DeviceTools($this->manager($fake->callable()));
		$result = $tools->attach_iso('vm-42', '[CDImages] x.iso');

		$create = $fake->calls('POST', '/api/vcenter/vm/vm-42/hardware/cdrom')[0];
		$body = json_decode($create['body'], true);
		$this->assertSame('SATA', $body['type']);
		$this->assertSame('ISO_FILE', $body['backing']['type']);
		$this->assertFalse($result['connected']);
	}

	public function testDetachIsoDisconnectsAndRebacks(): void
	{
		$fake = $this->fake();
		$fake->on('POST', '/api/vcenter/vm/vm-42/hardware/cdrom/16000', fn() => ['code' => 200, 'body' => '']);
		$fake->on('PATCH', '/api/vcenter/vm/vm-42/hardware/cdrom/16000', fn() => ['code' => 200, 'body' => '']);

		$tools = new DeviceTools($this->manager($fake->callable()));
		$result = $tools->detach_iso('vm-42', '16000');

		$this->assertTrue($result['detached']);
		$this->assertStringContainsString('action=disconnect', $fake->calls('POST', '/cdrom/16000')[0]['url']);
		$patch = json_decode($fake->calls('PATCH', '/cdrom/16000')[0]['body'], true);
		$this->assertSame('CLIENT_DEVICE', $patch['backing']['type']);
	}

	public function testAddDiskPostsNewVmdk(): void
	{
		$fake = $this->fake();
		$fake->on('POST', '/api/vcenter/vm/vm-42/hardware/disk', fn() => ['code' => 200, 'body' => '"2000"']);

		$tools = new DeviceTools($this->manager($fake->callable()));
		$tools->add_disk('vm-42', 30);

		$call = $fake->calls('POST', '/hardware/disk')[0];
		$body = json_decode($call['body'], true);
		$this->assertSame('SCSI', $body['type']);
		$this->assertSame(30 * 1073741824, $body['new_vmdk']['capacity']);
	}

	public function testAddNicResolvesDistributedPortgroup(): void
	{
		$fake = $this->fake();
		$fake->on('GET', '/api/vcenter/network', fn() => ['code' => 200, 'body' =>
			'[{"network":"dvportgroup-7","name":"DPort","type":"DISTRIBUTED_PORTGROUP"}]']);
		$fake->on('POST', '/api/vcenter/vm/vm-42/hardware/ethernet', fn() => ['code' => 200, 'body' => '"4000"']);

		$tools = new DeviceTools($this->manager($fake->callable()));
		$tools->add_nic('vm-42', 'DPort');

		$body = json_decode($fake->calls('POST', '/hardware/ethernet')[0]['body'], true);
		$this->assertSame('DISTRIBUTED_PORTGROUP', $body['backing']['type']);
		$this->assertSame('dvportgroup-7', $body['backing']['network']);
	}

	public function testSetBootPutsDeviceOrder(): void
	{
		$fake = $this->fake();
		$fake->on('PUT', '/api/vcenter/vm/vm-42/hardware/boot/device', fn() => ['code' => 200, 'body' => '']);
		$fake->on('GET', '/api/vcenter/vm/vm-42/hardware/boot', fn() => ['code' => 200, 'body' => '{"type":"EFI"}']);

		$tools = new DeviceTools($this->manager($fake->callable()));
		$tools->set_boot('vm-42', order: ['CDROM', 'DISK']);

		$put = $fake->calls('PUT', '/boot/device')[0];
		$body = json_decode($put['body'], true);
		$this->assertSame([['type' => 'CDROM'], ['type' => 'DISK']], $body['devices']);
	}

	public function testListNicsFetchesPerDeviceDetails(): void
	{
		$fake = $this->fake();
		// The collection endpoint returns only ids. Detail routes must
		// precede the collection route: FakeHttp matches on substring,
		// first match wins.
		$fake->on('GET', '/api/vcenter/vm/vm-42/hardware/ethernet/4000', fn() => ['code' => 200, 'body' =>
			'{"nic":"4000","label":"Network adapter 1","mac_address":"00:50:56:96:e9:7b","type":"VMXNET3","state":"CONNECTED","backing":{"type":"STANDARD_PORTGROUP","network":"network-9","network_name":"Default"}}']);
		$fake->on('GET', '/api/vcenter/vm/vm-42/hardware/ethernet/4001', fn() => ['code' => 200, 'body' =>
			'{"nic":"4001","label":"Network adapter 2","mac_address":"00:50:56:96:00:01","type":"E1000","state":"NOT_CONNECTED","backing":{"type":"STANDARD_PORTGROUP","network":"network-9","network_name":"Default"}}']);
		$fake->on('GET', '/api/vcenter/vm/vm-42/hardware/ethernet',
			fn() => ['code' => 200, 'body' => '[{"nic":"4000"},{"nic":"4001"}]']);

		$tools = new DeviceTools($this->manager($fake->callable()));
		$nics = $tools->list_nics('vm-42');

		$this->assertCount(2, $nics);
		$this->assertSame('00:50:56:96:e9:7b', $nics[0]['mac_address']);
		$this->assertSame('Default', $nics[0]['backing']['network_name']);
		$this->assertSame('4001', $nics[1]['nic']);
	}

	public function testListCdromsFetchesPerDeviceDetails(): void
	{
		$fake = $this->fake();
		$fake->on('GET', '/api/vcenter/vm/vm-42/hardware/cdrom/16000', fn() => ['code' => 200, 'body' =>
			'{"cdrom":"16000","label":"CD/DVD drive 1","type":"SATA","state":"CONNECTED","backing":{"type":"ISO_FILE","iso_file":"[CDImages] x.iso"}}']);
		$fake->on('GET', '/api/vcenter/vm/vm-42/hardware/cdrom',
			fn() => ['code' => 200, 'body' => '[{"cdrom":"16000"}]']);

		$tools = new DeviceTools($this->manager($fake->callable()));
		$cdroms = $tools->list_cdroms('vm-42');

		$this->assertSame('[CDImages] x.iso', $cdroms[0]['backing']['iso_file']);
	}

	// ── Storage (vim25) ──────────────────────────────────────────────

	private function fixture(string $name): string
	{
		return file_get_contents(__DIR__ . '/fixtures/soap/' . $name);
	}

	/** SOAP handshake + config.hardware.device from the storage fixture. */
	private function storageFake(string $deviceFixture = 'config-hardware-storage.xml'): FakeHttp
	{
		$fake = $this->fake();
		$fake->when(fn(array $c) => str_contains($c['body'] ?? '', 'RetrieveServiceContent'),
			fn() => ['code' => 200, 'body' => $this->fixture('service-content.xml')]);
		$fake->when(fn(array $c) => str_contains($c['body'] ?? '', '<Login '),
			fn() => ['code' => 200, 'body' => $this->fixture('login-response.xml'),
				'headers' => FakeHttp::soapSessionHeaders()]);
		$fake->when(fn(array $c) => str_contains($c['body'] ?? '', 'config.hardware.device'),
			fn() => ['code' => 200, 'body' => $this->fixture($deviceFixture)]);
		return $fake;
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

	public function testListDisksReportsControllerModeAndProvisioning(): void
	{
		$tools = new DeviceTools($this->manager($this->storageFake()->callable()));
		$disks = $tools->list_disks('vm-42');

		$this->assertCount(2, $disks);
		$this->assertSame('2000', $disks[0]['disk']);
		$this->assertSame('Hard disk 1', $disks[0]['label']);
		$this->assertSame(20971520 * 1024, $disks[0]['capacity']);
		$this->assertSame(20.0, $disks[0]['capacity_gib']);
		$this->assertSame(1000, $disks[0]['controller_key']);
		$this->assertSame('lsilogic-sas', $disks[0]['controller_type']);
		$this->assertSame(0, $disks[0]['unit_number']);
		$this->assertSame('persistent', $disks[0]['disk_mode']);
		$this->assertTrue($disks[0]['thin_provisioned']);
		$this->assertSame('[ds2] t1/t1.vmdk', $disks[0]['file']);

		$this->assertSame(1001, $disks[1]['controller_key']);
		$this->assertSame('paravirtual', $disks[1]['controller_type']);
		$this->assertSame('independent_persistent', $disks[1]['disk_mode']);
		$this->assertFalse($disks[1]['thin_provisioned']);
	}

	public function testListControllersShapesScsiControllers(): void
	{
		$tools = new DeviceTools($this->manager($this->storageFake()->callable()));
		$controllers = $tools->list_controllers('vm-42');

		$this->assertCount(2, $controllers);
		$this->assertSame(1000, $controllers[0]['key']);
		$this->assertSame('lsilogic-sas', $controllers[0]['controller_type']);
		$this->assertSame(0, $controllers[0]['bus_number']);
		$this->assertSame('noSharing', $controllers[0]['shared_bus']);
		$this->assertSame(1001, $controllers[1]['key']);
		$this->assertSame('paravirtual', $controllers[1]['controller_type']);
		$this->assertSame(1, $controllers[1]['bus_number']);
	}

	public function testAddDiskAttachesToExistingController(): void
	{
		$fake = $this->storageFake();
		$this->reconfigRoutes($fake);

		$tools = new DeviceTools($this->manager($fake->callable()));
		$result = $tools->add_disk('vm-42', 30, controller_type: 'paravirtual');

		$this->assertFalse($result['controller_created']);
		$this->assertSame(1001, $result['controller_key']);
		$this->assertSame(1, $result['unit_number']);

		$sdk = $fake->calls('POST', '/sdk');
		$reconfig = array_values(array_filter($sdk,
			fn($c) => str_contains($c['body'] ?? '', 'ReconfigVM_Task')))[0]['body'];
		$this->assertSame(1, substr_count($reconfig, '<deviceChange>'));
		$this->assertStringContainsString('<device xsi:type="VirtualDisk">', $reconfig);
		// fileOperation=create is required — without it vCenter treats
		// the add as attaching an existing file
		$this->assertStringContainsString('<fileOperation>create</fileOperation>', $reconfig);
		$this->assertStringContainsString('<controllerKey>1001</controllerKey>', $reconfig);
		$this->assertStringContainsString('<unitNumber>1</unitNumber>', $reconfig);
		$this->assertStringContainsString('<capacityInKB>' . (30 * 1048576) . '</capacityInKB>', $reconfig);
		$this->assertStringContainsString('<diskMode>persistent</diskMode>', $reconfig);
		$this->assertStringNotContainsString('ParaVirtualSCSIController', $reconfig);
		$this->assertStringNotContainsString('thinProvisioned', $reconfig);
		// No power pre-check when the controller already exists
		$this->assertSame([], $fake->calls('GET', '/api/vcenter/vm/vm-42/power'));
	}

	public function testAddDiskCreatesControllerWhenTypeAbsent(): void
	{
		$fake = $this->storageFake('config-hardware-device.xml');
		$fake->on('GET', '/api/vcenter/vm/vm-42/power', fn() => ['code' => 200, 'body' => '{"state":"POWERED_OFF"}']);
		$this->reconfigRoutes($fake);

		$tools = new DeviceTools($this->manager($fake->callable()));
		$result = $tools->add_disk('vm-42', 30,
			controller_type: 'paravirtual', disk_mode: 'independent_persistent', thin: true);

		$this->assertTrue($result['controller_created']);
		$this->assertSame(0, $result['unit_number']);

		$sdk = $fake->calls('POST', '/sdk');
		$reconfig = array_values(array_filter($sdk,
			fn($c) => str_contains($c['body'] ?? '', 'ReconfigVM_Task')))[0]['body'];
		$this->assertSame(2, substr_count($reconfig, '<deviceChange>'));
		$this->assertStringContainsString('<device xsi:type="ParaVirtualSCSIController">', $reconfig);
		$this->assertStringContainsString('<key>-101</key>', $reconfig);
		$this->assertStringContainsString('<busNumber>1</busNumber>', $reconfig);
		$this->assertStringContainsString('<controllerKey>-101</controllerKey>', $reconfig);
		$this->assertStringContainsString('<unitNumber>0</unitNumber>', $reconfig);
		$this->assertStringContainsString('<diskMode>independent_persistent</diskMode>', $reconfig);
		$this->assertStringContainsString('<thinProvisioned>true</thinProvisioned>', $reconfig);
		$this->assertSame(1, substr_count($reconfig, '<fileOperation>create</fileOperation>'));
	}

	public function testAddDiskControllerCreationRequiresPowerOff(): void
	{
		$fake = $this->storageFake('config-hardware-device.xml');
		$fake->on('GET', '/api/vcenter/vm/vm-42/power', fn() => ['code' => 200, 'body' => '{"state":"POWERED_ON"}']);

		$tools = new DeviceTools($this->manager($fake->callable()));
		try {
			$tools->add_disk('vm-42', 30, controller_type: 'paravirtual');
			$this->fail('expected VCenterException');
		} catch (\VCenter\VCenterException $e) {
			$this->assertSame('PowerStateError', $e->getErrorType());
		}
		$this->assertSame([], array_filter($fake->calls('POST', '/sdk'),
			fn($c) => str_contains($c['body'] ?? '', 'ReconfigVM_Task')));
	}

	public function testAddDiskRejectsBadDiskMode(): void
	{
		$tools = new DeviceTools($this->manager($this->storageFake()->callable()));
		try {
			$tools->add_disk('vm-42', 30, disk_mode: 'ephemeral');
			$this->fail('expected VCenterException');
		} catch (\VCenter\VCenterException $e) {
			$this->assertSame('InvalidArgument', $e->getErrorType());
		}
	}

	public function testSetDiskEditsBacking(): void
	{
		$fake = $this->storageFake();
		$fake->on('GET', '/api/vcenter/vm/vm-42/power', fn() => ['code' => 200, 'body' => '{"state":"POWERED_OFF"}']);
		$this->reconfigRoutes($fake);

		$tools = new DeviceTools($this->manager($fake->callable()));
		$result = $tools->set_disk('vm-42', '2001', disk_mode: 'persistent');

		$this->assertTrue($result['updated']);
		$this->assertSame('2001', $result['disk']);

		$sdk = $fake->calls('POST', '/sdk');
		$reconfig = array_values(array_filter($sdk,
			fn($c) => str_contains($c['body'] ?? '', 'ReconfigVM_Task')))[0]['body'];
		$this->assertStringContainsString('<operation>edit</operation>', $reconfig);
		$this->assertStringContainsString('<key>2001</key>', $reconfig);
		$this->assertStringContainsString('<fileName>[ds2] t1/t1_1.vmdk</fileName>', $reconfig);
		$this->assertStringContainsString('<diskMode>persistent</diskMode>', $reconfig);
		// vCenter silently ignores thinProvisioned on backing edits —
		// it must not be sent.
		$this->assertStringNotContainsString('thinProvisioned', $reconfig);
		$this->assertStringContainsString('<controllerKey>1001</controllerKey>', $reconfig);
		$this->assertStringContainsString('<unitNumber>0</unitNumber>', $reconfig);
	}

	public function testSetDiskRequiresPowerOff(): void
	{
		$fake = $this->storageFake();
		$fake->on('GET', '/api/vcenter/vm/vm-42/power', fn() => ['code' => 200, 'body' => '{"state":"POWERED_ON"}']);

		$tools = new DeviceTools($this->manager($fake->callable()));
		try {
			$tools->set_disk('vm-42', '2001', disk_mode: 'persistent');
			$this->fail('expected VCenterException');
		} catch (\VCenter\VCenterException $e) {
			$this->assertSame('PowerStateError', $e->getErrorType());
		}
	}

	public function testSetDiskRejectsUnknownDisk(): void
	{
		$tools = new DeviceTools($this->manager($this->storageFake()->callable()));
		try {
			$tools->set_disk('vm-42', '2999', disk_mode: 'persistent');
			$this->fail('expected VCenterException');
		} catch (\VCenter\VCenterException $e) {
			$this->assertSame('NotFound', $e->getErrorType());
		}
	}

	public function testDetachIsoAnswersLockedDoorQuestion(): void
	{
		$fake = $this->fake();
		// Disconnect: transport-hang once (pending question), succeed after
		$disconnects = 0;
		$fake->on('POST', '/api/vcenter/vm/vm-42/hardware/cdrom/16000',
			function () use (&$disconnects) {
				$disconnects++;
				return $disconnects === 1 ? ['code' => 0, 'body' => ''] : ['code' => 200, 'body' => ''];
			});
		$fake->on('PATCH', '/api/vcenter/vm/vm-42/hardware/cdrom/16000', fn() => ['code' => 200, 'body' => '']);
		$fake->when(fn(array $c) => str_contains($c['body'] ?? '', 'RetrieveServiceContent'),
			fn() => ['code' => 200, 'body' => $this->fixture('service-content.xml')]);
		$fake->when(fn(array $c) => str_contains($c['body'] ?? '', '<Login '),
			fn() => ['code' => 200, 'body' => $this->fixture('login-response.xml'),
				'headers' => FakeHttp::soapSessionHeaders()]);
		$fake->when(fn(array $c) => str_contains($c['body'] ?? '', 'runtime.question'),
			fn() => ['code' => 200, 'body' => $this->fixture('question-cdrom-locked.xml')]);
		$fake->when(fn(array $c) => str_contains($c['body'] ?? '', 'AnswerVM'),
			fn() => ['code' => 200, 'body' => '<?xml version="1.0"?>'
				. '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Body>'
				. '<AnswerVMResponse xmlns="urn:vim25"/></soapenv:Body></soapenv:Envelope>']);

		$tools = new DeviceTools($this->manager($fake->callable()));
		$result = $tools->detach_iso('vm-42', '16000');

		$this->assertTrue($result['detached']);
		$this->assertTrue($result['question_answered']);
		$this->assertSame(2, $disconnects);
		$answer = array_values(array_filter($fake->calls('POST', '/sdk'),
			fn($c) => str_contains($c['body'] ?? '', 'AnswerVM')))[0]['body'];
		$this->assertStringContainsString('<questionId>question-9</questionId>', $answer);
		$this->assertStringContainsString('<answerChoice>button.yes</answerChoice>', $answer);
	}

	public function testReconfigureTimeoutSurfacesPendingQuestion(): void
	{
		$fake = $this->fake();
		// The PATCH hangs: transport-level failure
		$fake->on('PATCH', '/api/vcenter/vm/vm-42/hardware/cdrom/16000',
			fn() => ['code' => 0, 'body' => '']);
		// runtime.question check goes through SOAP
		$fake->on('POST', '/sdk', function (array $c) {
			if (str_contains($c['body'], 'RetrieveServiceContent')) {
				return ['code' => 200, 'body' => file_get_contents(__DIR__ . '/fixtures/soap/service-content.xml')];
			}
			if (str_contains($c['body'], '<Login ')) {
				return ['code' => 200, 'body' => file_get_contents(__DIR__ . '/fixtures/soap/login-response.xml'),
					'headers' => FakeHttp::soapSessionHeaders()];
			}
			return ['code' => 200, 'body' => file_get_contents(__DIR__ . '/fixtures/soap/question.xml')];
		});

		$tools = new DeviceTools($this->manager($fake->callable()));
		try {
			$tools->attach_iso('vm-42', '[CDImages] x.iso', '16000');
			$this->fail('expected VCenterException');
		} catch (\VCenter\VCenterException $e) {
			$this->assertSame('QuestionPending', $e->getErrorType());
			$this->assertStringContainsString('get_vm_question', $e->getMessage());
			$this->assertStringContainsString('msg.uuid.altered', $e->getMessage());
		}
	}

	public function testReconfigureTimeoutWithoutQuestionRethrows(): void
	{
		$fake = $this->fake();
		$fake->on('PATCH', '/api/vcenter/vm/vm-42/hardware/cdrom/16000',
			fn() => ['code' => 0, 'body' => '']);
		$fake->on('POST', '/sdk', function (array $c) {
			if (str_contains($c['body'], 'RetrieveServiceContent')) {
				return ['code' => 200, 'body' => file_get_contents(__DIR__ . '/fixtures/soap/service-content.xml')];
			}
			if (str_contains($c['body'], '<Login ')) {
				return ['code' => 200, 'body' => file_get_contents(__DIR__ . '/fixtures/soap/login-response.xml'),
					'headers' => FakeHttp::soapSessionHeaders()];
			}
			// no runtime.question propSet
			return ['code' => 200, 'body' => file_get_contents(__DIR__ . '/fixtures/soap/missing-set-benign.xml')];
		});

		$tools = new DeviceTools($this->manager($fake->callable()));
		try {
			$tools->attach_iso('vm-42', '[CDImages] x.iso', '16000');
			$this->fail('expected VCenterException');
		} catch (\VCenter\VCenterException $e) {
			$this->assertSame('Transport', $e->getErrorType());
		}
	}
}
