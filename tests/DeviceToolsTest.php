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

	public function testListCdromsAndDisksFetchPerDeviceDetails(): void
	{
		$fake = $this->fake();
		$fake->on('GET', '/api/vcenter/vm/vm-42/hardware/cdrom/16000', fn() => ['code' => 200, 'body' =>
			'{"cdrom":"16000","label":"CD/DVD drive 1","type":"SATA","state":"CONNECTED","backing":{"type":"ISO_FILE","iso_file":"[CDImages] x.iso"}}']);
		$fake->on('GET', '/api/vcenter/vm/vm-42/hardware/cdrom',
			fn() => ['code' => 200, 'body' => '[{"cdrom":"16000"}]']);
		$fake->on('GET', '/api/vcenter/vm/vm-42/hardware/disk/2000', fn() => ['code' => 200, 'body' =>
			'{"disk":"2000","label":"Hard disk 1","capacity":34359738368,"backing":{"type":"VMDK_FILE","vmdk_file":"[ds] vm/vm.vmdk"}}']);
		$fake->on('GET', '/api/vcenter/vm/vm-42/hardware/disk',
			fn() => ['code' => 200, 'body' => '[{"disk":"2000"}]']);

		$tools = new DeviceTools($this->manager($fake->callable()));
		$cdroms = $tools->list_cdroms('vm-42');
		$disks = $tools->list_disks('vm-42');

		$this->assertSame('[CDImages] x.iso', $cdroms[0]['backing']['iso_file']);
		$this->assertSame(34359738368, $disks[0]['capacity']);
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
