<?php

use PHPUnit\Framework\TestCase;
use VCenter\InstanceManager;
use VCenter\VCenterException;

require_once __DIR__ . '/lib/FakeHttp.php';
require_once __DIR__ . '/../tools/VideoTools.php';

class VideoToolsTest extends TestCase
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
	}

	public function testGetVideoParsesVideoCard(): void
	{
		$fake = new FakeHttp();
		$this->soapHandshake($fake);
		$fake->when(
			fn(array $c) => str_contains($c['body'] ?? '', 'config.hardware.device'),
			fn() => ['code' => 200, 'body' => $this->fixture('config-hardware-device.xml')]
		);

		$tools = new VideoTools($this->manager($fake->callable()));
		$video = $tools->get_video('vm-42');

		$this->assertSame('vm-42', $video['vm']);
		$this->assertSame(400, $video['key']);
		$this->assertSame('Video card', $video['label']);
		$this->assertFalse($video['use_auto_detect']);
		$this->assertSame(8192, $video['video_ram_size_kb']);
		$this->assertSame(1, $video['num_displays']);
		$this->assertFalse($video['enable_3d_support']);
		$this->assertSame(262144, $video['graphics_memory_size_kb']);
	}

	public function testSetVideoSendsDeviceEditAndRereads(): void
	{
		$fake = new FakeHttp();
		$fake->on('POST', '/api/session', fn() => ['code' => 200, 'body' => '"tok"']);
		$fake->on('GET', '/api/vcenter/vm/vm-42/power', fn() => ['code' => 200, 'body' => '{"state":"POWERED_OFF"}']);
		$this->soapHandshake($fake);

		// First device read returns the base card; the post-reconfigure
		// re-read reflects the applied settings.
		$reads = 0;
		$fake->when(
			fn(array $c) => str_contains($c['body'] ?? '', 'config.hardware.device'),
			function () use (&$reads) {
				$reads++;
				$body = $this->fixture('config-hardware-device.xml');
				if ($reads > 1) {
					$body = str_replace(
						['<useAutoDetect>false</useAutoDetect>', '<videoRamSizeInKB>8192</videoRamSizeInKB>'],
						['<useAutoDetect>true</useAutoDetect>', ''],
						$body
					);
				}
				return ['code' => 200, 'body' => $body];
			}
		);
		$fake->when(
			fn(array $c) => str_contains($c['body'] ?? '', 'ReconfigVM_Task'),
			fn() => ['code' => 200, 'body' => '<?xml version="1.0"?>'
				. '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Body>'
				. '<ReconfigVM_TaskResponse xmlns="urn:vim25"><returnval type="Task">task-7</returnval></ReconfigVM_TaskResponse>'
				. '</soapenv:Body></soapenv:Envelope>']
		);
		$fake->when(
			fn(array $c) => str_contains($c['body'] ?? '', '<pathSet>info</pathSet>'),
			fn() => ['code' => 200, 'body' => '<?xml version="1.0"?>'
				. '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Body>'
				. '<RetrievePropertiesExResponse xmlns="urn:vim25"><returnval><objects>'
				. '<obj type="Task">task-7</obj>'
				. '<propSet><name>info</name><val><state>success</state></val></propSet>'
				. '</objects></returnval></RetrievePropertiesExResponse></soapenv:Body></soapenv:Envelope>']
		);

		$tools = new VideoTools($this->manager($fake->callable()));
		$result = $tools->set_video('vm-42', use_auto_detect: true);

		$this->assertTrue($result['updated']);
		$this->assertTrue($result['use_auto_detect']);
		$this->assertNull($result['video_ram_size_kb']);

		// POST /sdk call order: service content, login, device read, reconfig
		$reconfig = $fake->calls('POST', '/sdk')[3]['body'];
		$this->assertStringContainsString('ReconfigVM_Task', $reconfig);
		$this->assertStringContainsString('<operation>edit</operation>', $reconfig);
		$this->assertStringContainsString('<device xsi:type="VirtualMachineVideoCard">', $reconfig);
		$this->assertStringContainsString('<key>400</key>', $reconfig);
		$this->assertStringContainsString('<useAutoDetect>true</useAutoDetect>', $reconfig);
		$this->assertStringNotContainsString('videoRamSizeInKB', $reconfig);
	}

	public function testSetVideoSendsRamFieldsInSchemaOrder(): void
	{
		$fake = new FakeHttp();
		$fake->on('POST', '/api/session', fn() => ['code' => 200, 'body' => '"tok"']);
		$fake->on('GET', '/api/vcenter/vm/vm-42/power', fn() => ['code' => 200, 'body' => '{"state":"POWERED_OFF"}']);
		$this->soapHandshake($fake);
		$fake->when(
			fn(array $c) => str_contains($c['body'] ?? '', 'config.hardware.device'),
			fn() => ['code' => 200, 'body' => $this->fixture('config-hardware-device.xml')]
		);
		$fake->when(
			fn(array $c) => str_contains($c['body'] ?? '', 'ReconfigVM_Task'),
			fn() => ['code' => 200, 'body' => '<?xml version="1.0"?>'
				. '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Body>'
				. '<ReconfigVM_TaskResponse xmlns="urn:vim25"><returnval type="Task">task-7</returnval></ReconfigVM_TaskResponse>'
				. '</soapenv:Body></soapenv:Envelope>']
		);
		$fake->when(
			fn(array $c) => str_contains($c['body'] ?? '', '<pathSet>info</pathSet>'),
			fn() => ['code' => 200, 'body' => '<?xml version="1.0"?>'
				. '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Body>'
				. '<RetrievePropertiesExResponse xmlns="urn:vim25"><returnval><objects>'
				. '<obj type="Task">task-7</obj>'
				. '<propSet><name>info</name><val><state>success</state></val></propSet>'
				. '</objects></returnval></RetrievePropertiesExResponse></soapenv:Body></soapenv:Envelope>']
		);

		$tools = new VideoTools($this->manager($fake->callable()));
		$tools->set_video('vm-42', video_ram_size_kb: 16384, num_displays: 2, enable_3d_support: true);

		// POST /sdk call order: service content, login, device read, reconfig
		$reconfig = $fake->calls('POST', '/sdk')[3]['body'];
		$this->assertStringContainsString('<videoRamSizeInKB>16384</videoRamSizeInKB>', $reconfig);
		$this->assertStringContainsString('<numDisplays>2</numDisplays>', $reconfig);
		$this->assertStringContainsString('<enable3DSupport>true</enable3DSupport>', $reconfig);
		// vim25 sequence order inside VirtualMachineVideoCard
		$vram = strpos($reconfig, '<videoRamSizeInKB>');
		$displays = strpos($reconfig, '<numDisplays>');
		$threed = strpos($reconfig, '<enable3DSupport>');
		$this->assertTrue($vram < $displays && $displays < $threed);
	}

	public function testSetVideoRejectsAutoDetectWithExplicitSizing(): void
	{
		$fake = new FakeHttp();
		$tools = new VideoTools($this->manager($fake->callable()));
		try {
			$tools->set_video('vm-42', use_auto_detect: true, video_ram_size_kb: 8192);
			$this->fail('expected VCenterException');
		} catch (VCenterException $e) {
			$this->assertSame('InvalidArgument', $e->getErrorType());
			$this->assertStringContainsString('cannot be combined', $e->getMessage());
		}
		// Nothing touched vCenter
		$this->assertSame([], $fake->calls());
	}

	public function testSetVideoWithoutChangesErrors(): void
	{
		$fake = new FakeHttp();
		$tools = new VideoTools($this->manager($fake->callable()));
		try {
			$tools->set_video('vm-42');
			$this->fail('expected VCenterException');
		} catch (VCenterException $e) {
			$this->assertSame('InvalidArgument', $e->getErrorType());
		}
	}

	public function testSetVideoRequiresPoweredOff(): void
	{
		$fake = new FakeHttp();
		$fake->on('POST', '/api/session', fn() => ['code' => 200, 'body' => '"tok"']);
		$fake->on('GET', '/api/vcenter/vm/vm-42/power', fn() => ['code' => 200, 'body' => '{"state":"POWERED_ON"}']);

		$tools = new VideoTools($this->manager($fake->callable()));
		try {
			$tools->set_video('vm-42', use_auto_detect: true);
			$this->fail('expected VCenterException');
		} catch (VCenterException $e) {
			$this->assertSame('PowerStateError', $e->getErrorType());
			$this->assertStringContainsString('POWERED_ON', $e->getMessage());
		}
		$this->assertSame([], $fake->calls('POST', '/sdk'));
	}

	public function testSetVideoMapsInvalidPowerStateFault(): void
	{
		$fake = new FakeHttp();
		$fake->on('POST', '/api/session', fn() => ['code' => 200, 'body' => '"tok"']);
		$fake->on('GET', '/api/vcenter/vm/vm-42/power', fn() => ['code' => 200, 'body' => '{"state":"POWERED_OFF"}']);
		$this->soapHandshake($fake);
		$fake->when(
			fn(array $c) => str_contains($c['body'] ?? '', 'config.hardware.device'),
			fn() => ['code' => 200, 'body' => $this->fixture('config-hardware-device.xml')]
		);
		// Race with a host-side power action: SOAP rejects the reconfigure
		// even though the REST pre-check passed.
		$fake->when(
			fn(array $c) => str_contains($c['body'] ?? '', 'ReconfigVM_Task'),
			fn() => ['code' => 500, 'body' => '<?xml version="1.0"?>'
				. '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><soapenv:Body>'
				. '<soapenv:Fault>'
				. '<faultcode>ServerFaultCode</faultcode>'
				. '<faultstring>The virtual machine is powered on</faultstring>'
				. '<detail><InvalidPowerStateFault xmlns="urn:vim25" xsi:type="InvalidPowerState"/></detail>'
				. '</soapenv:Fault>'
				. '</soapenv:Body></soapenv:Envelope>']
		);

		$tools = new VideoTools($this->manager($fake->callable()));
		try {
			$tools->set_video('vm-42', enable_3d_support: true);
			$this->fail('expected VCenterException');
		} catch (VCenterException $e) {
			$this->assertSame('PowerStateError', $e->getErrorType());
		}
	}

	public function testGetVideoErrorsWhenNoVideoCard(): void
	{
		$fake = new FakeHttp();
		$this->soapHandshake($fake);
		// Card stripped: only the SCSI controller remains
		$body = preg_replace(
			'#<VirtualDevice xsi:type="VirtualMachineVideoCard">.*?</VirtualDevice>#s',
			'',
			$this->fixture('config-hardware-device.xml')
		);
		$fake->when(
			fn(array $c) => str_contains($c['body'] ?? '', 'config.hardware.device'),
			fn() => ['code' => 200, 'body' => $body]
		);

		$tools = new VideoTools($this->manager($fake->callable()));
		try {
			$tools->get_video('vm-42');
			$this->fail('expected VCenterException');
		} catch (VCenterException $e) {
			$this->assertSame('NotFound', $e->getErrorType());
		}
	}
}
