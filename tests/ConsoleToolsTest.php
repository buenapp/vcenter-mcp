<?php

use PHPUnit\Framework\TestCase;
use VCenter\Instance;
use VCenter\InstanceManager;
use VCenter\Screenshot;
use VCenter\VCenterException;

require_once __DIR__ . '/lib/FakeHttp.php';
require_once __DIR__ . '/../tools/ConsoleTools.php';

class ConsoleToolsTest extends TestCase
{
	private function fixture(string $name): string
	{
		return file_get_contents(__DIR__ . '/fixtures/soap/' . $name);
	}

	private function manager(callable $http): InstanceManager
	{
		return new InstanceManager([
			'test' => ['url' => 'https://vcenter.test', 'username' => 'vcadmin', 'password' => 'pw'],
		], 'test', $http);
	}

	private function instance(callable $http): Instance
	{
		return new Instance('test', ['url' => 'https://vcenter.test', 'username' => 'vcadmin', 'password' => 'pw'], $http);
	}

	private function soapHandshake(FakeHttp $fake): void
	{
		$fake->when(fn(array $c) => str_contains($c['body'] ?? '', 'RetrieveServiceContent'),
			fn() => ['code' => 200, 'body' => $this->fixture('service-content.xml')]);
		$fake->when(fn(array $c) => str_contains($c['body'] ?? '', '<Login '),
			fn() => ['code' => 200, 'body' => $this->fixture('login-response.xml'), 'headers' => FakeHttp::soapSessionHeaders()]);
	}

	private function poweredOn(FakeHttp $fake): void
	{
		$fake->on('POST', '/api/session', fn() => ['code' => 200, 'body' => '"tok"']);
		$fake->on('GET', '/api/vcenter/vm/vm-42/power', fn() => ['code' => 200, 'body' => '{"state":"POWERED_ON"}']);
	}

	public function testPutUsbScanCodesXmlShape(): void
	{
		$fake = new FakeHttp();
		$this->poweredOn($fake);
		$this->soapHandshake($fake);
		$fake->when(
			fn(array $c) => str_contains($c['body'] ?? '', 'PutUsbScanCodes'),
			function (array $c) {
				$this->assertStringContainsString('<usbHidCode>262151</usbHidCode>', $c['body']); // 'a' = 0x04<<16|7
				$this->assertStringContainsString('<leftShift>true</leftShift>', $c['body']); // 'A'
				$this->assertStringContainsString('<rightGui>false</rightGui>', $c['body']);
				return ['code' => 200, 'body' => '<?xml version="1.0"?><soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Body><PutUsbScanCodesResponse xmlns="urn:vim25"><returnval>2</returnval></PutUsbScanCodesResponse></soapenv:Body></soapenv:Envelope>'];
			}
		);

		$tools = new ConsoleTools($this->manager($fake->callable()));
		$result = $tools->vm_send_keys('vm-42', text: 'Aa');
		$this->assertSame(2, $result['sent']);
		$this->assertSame(1, $result['chunks']);
	}

	public function testChunking70EventsMakes3Calls(): void
	{
		$fake = new FakeHttp();
		$this->poweredOn($fake);
		$this->soapHandshake($fake);
		$fake->when(
			fn(array $c) => str_contains($c['body'] ?? '', 'PutUsbScanCodes'),
			fn() => ['code' => 200, 'body' => '<?xml version="1.0"?><soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Body><PutUsbScanCodesResponse xmlns="urn:vim25"><returnval>32</returnval></PutUsbScanCodesResponse></soapenv:Body></soapenv:Envelope>']
		);

		$tools = new ConsoleTools($this->manager($fake->callable()));
		$result = $tools->vm_send_keys('vm-42', text: str_repeat('x', 70), delay_ms: 0);

		$calls = $fake->calls('POST', '/sdk');
		$putCalls = array_filter($calls, fn($c) => str_contains($c['body'], 'PutUsbScanCodes'));
		$this->assertCount(3, $putCalls);
		$this->assertSame(3, $result['chunks']);
		$this->assertSame(96, $result['sent']); // server reported 32 per call

		// each chunk carries <=32 events
		foreach ($putCalls as $c) {
			$this->assertLessThanOrEqual(32, substr_count($c['body'], '<keyEvents>'));
		}
	}

	public function testSendKeysRefusesPoweredOff(): void
	{
		$fake = new FakeHttp();
		$fake->on('POST', '/api/session', fn() => ['code' => 200, 'body' => '"tok"']);
		$fake->on('GET', '/api/vcenter/vm/vm-42/power', fn() => ['code' => 200, 'body' => '{"state":"POWERED_OFF"}']);

		$tools = new ConsoleTools($this->manager($fake->callable()));
		$this->expectException(VCenterException::class);
		$this->expectExceptionMessageMatches('/POWERED_ON/');
		$tools->vm_send_keys('vm-42', text: 'x');
	}

	public function testSendKeysRequiresSomething(): void
	{
		$tools = new ConsoleTools($this->manager(fn() => ['code' => 200, 'body' => '']));
		$this->expectException(VCenterException::class);
		$tools->vm_send_keys('vm-42');
	}

	public function testScreenshotEndToEnd(): void
	{
		$fake = new FakeHttp();
		$this->soapHandshake($fake);
		$png = file_get_contents(__DIR__ . '/fixtures/screenshot.png');

		$fake->when(fn(array $c) => str_contains($c['body'] ?? '', 'CreateScreenshot_Task'),
			fn() => ['code' => 200, 'body' => $this->fixture('task-ref.xml')]);
		// task info poll + parent walk both come back as RetrievePropertiesEx;
		// the task poll asks for Task:task-500, the walk for VirtualMachine/Folder/Datacenter
		$fake->when(
			fn(array $c) => str_contains($c['body'] ?? '', 'RetrievePropertiesEx') && str_contains($c['body'] ?? '', 'task-500'),
			fn() => ['code' => 200, 'body' => $this->fixture('task-screenshot-result.xml')]
		);
		$fake->when(
			fn(array $c) => str_contains($c['body'] ?? '', 'RetrievePropertiesEx') && str_contains($c['body'] ?? '', 'task-501'),
			fn() => ['code' => 200, 'body' => $this->fixture('task-delete-result.xml')]
		);
		$fake->when(
			fn(array $c) => str_contains($c['body'] ?? '', 'RetrievePropertiesEx'),
			fn() => ['code' => 200, 'body' => $this->fixture('parent-walk.xml')]
		);
		$fake->when(
			fn(array $c) => str_contains($c['body'] ?? '', 'DeleteDatastoreFile_Task'),
			fn() => ['code' => 200, 'body' => $this->fixture('delete-task-ref.xml')]
		);
		$downloadChecked = false;
		$fake->when(
			fn(array $c) => $c['method'] === 'GET' && str_contains($c['url'], '/folder/'),
			function (array $c) use ($png, &$downloadChecked) {
				$this->assertStringContainsString('dcPath=DC1', $c['url']);
				$this->assertStringContainsString('dsName=ds2', $c['url']);
				$this->assertStringContainsString('t1/t1-42.png', $c['url']);
				$this->assertContains('Cookie: vmware_soap_session="5271f3e6-9b3d-4d7a-a1c2-0123456789ab"', $c['headers']);
				$downloadChecked = true;
				return ['code' => 200, 'body' => $png];
			}
		);

		$shot = (new Screenshot($this->instance($fake->callable())))->capture('vm-42');

		$this->assertTrue($downloadChecked);
		$this->assertSame($png, $shot['png']);
		$this->assertSame(2, $shot['width']);
		$this->assertSame(2, $shot['height']);

		// delete task issued
		$this->assertNotEmpty(array_filter($fake->calls('POST', '/sdk'),
			fn($c) => str_contains($c['body'], 'DeleteDatastoreFile_Task')));
	}

	public function testScreenshotViaToolReturnsImageResult(): void
	{
		$fake = new FakeHttp();
		$this->poweredOn($fake);
		$this->soapHandshake($fake);
		$png = file_get_contents(__DIR__ . '/fixtures/screenshot.png');
		$fake->when(fn(array $c) => str_contains($c['body'] ?? '', 'CreateScreenshot_Task'),
			fn() => ['code' => 200, 'body' => $this->fixture('task-ref.xml')]);
		$fake->when(fn(array $c) => str_contains($c['body'] ?? '', 'RetrievePropertiesEx') && str_contains($c['body'] ?? '', 'task-500'),
			fn() => ['code' => 200, 'body' => $this->fixture('task-screenshot-result.xml')]);
		$fake->when(fn(array $c) => str_contains($c['body'] ?? '', 'RetrievePropertiesEx') && str_contains($c['body'] ?? '', 'task-501'),
			fn() => ['code' => 200, 'body' => $this->fixture('task-delete-result.xml')]);
		$fake->when(fn(array $c) => str_contains($c['body'] ?? '', 'RetrievePropertiesEx'),
			fn() => ['code' => 200, 'body' => $this->fixture('parent-walk.xml')]);
		$fake->when(fn(array $c) => str_contains($c['body'] ?? '', 'DeleteDatastoreFile_Task'),
			fn() => ['code' => 200, 'body' => $this->fixture('delete-task-ref.xml')]);
		$fake->when(fn(array $c) => $c['method'] === 'GET' && str_contains($c['url'], '/folder/'),
			fn() => ['code' => 200, 'body' => $png]);

		$tools = new ConsoleTools($this->manager($fake->callable()));
		$result = $tools->vm_screenshot('vm-42');

		$this->assertInstanceOf(\EnchiladaMCP\ToolResult::class, $result);
	}

	public function testQuestionParseAndAnswer(): void
	{
		$fake = new FakeHttp();
		$this->soapHandshake($fake);
		$fake->when(
			fn(array $c) => str_contains($c['body'] ?? '', 'RetrievePropertiesEx'),
			fn() => ['code' => 200, 'body' => $this->fixture('question.xml')]
		);
		$fake->when(
			fn(array $c) => str_contains($c['body'] ?? '', 'AnswerVM'),
			function (array $c) {
				$this->assertStringContainsString('<questionId>question-1</questionId>', $c['body']);
				$this->assertStringContainsString('<answerChoice>1</answerChoice>', $c['body']);
				return ['code' => 200, 'body' => '<?xml version="1.0"?><soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Body><AnswerVMResponse xmlns="urn:vim25"/></soapenv:Body></soapenv:Envelope>'];
			}
		);

		$tools = new ConsoleTools($this->manager($fake->callable()));
		$q = $tools->get_vm_question('vm-42');
		$this->assertSame('question-1', $q['question']['id']);
		$this->assertSame('button.uuid.copiedTheVM', $q['question']['choices'][1]['label']);

		// answer by label, case-insensitive
		$r = $tools->answer_vm_question('vm-42', 'BUTTON.UUID.COPIEDTHEVM');
		$this->assertSame('1', $r['answered']);
	}

	public function testAnswerVmQuestionInvalidChoice(): void
	{
		$fake = new FakeHttp();
		$this->soapHandshake($fake);
		$fake->when(fn(array $c) => str_contains($c['body'] ?? '', 'RetrievePropertiesEx'),
			fn() => ['code' => 200, 'body' => $this->fixture('question.xml')]);

		$tools = new ConsoleTools($this->manager($fake->callable()));
		$this->expectException(VCenterException::class);
		$this->expectExceptionMessageMatches('/Invalid choice/');
		$tools->answer_vm_question('vm-42', 'bogus');
	}

	public function testAcquireTicketParse(): void
	{
		$fake = new FakeHttp();
		$this->soapHandshake($fake);
		$fake->when(
			fn(array $c) => str_contains($c['body'] ?? '', 'AcquireTicket'),
			fn() => ['code' => 200, 'body' => $this->fixture('acquire-ticket.xml')]
		);

		$ticket = $this->instance($fake->callable())->soap()
			->acquireTicket(['type' => 'VirtualMachine', 'id' => 'vm-42'], 'webmks');
		$this->assertSame('esx1.example.com', $ticket['host']);
		$this->assertSame(443, $ticket['port']);
		$this->assertSame('5255f2d7-d0f4-4e8d-9b3a-ticketabc', $ticket['ticket']);
		$this->assertStringStartsWith('AA:BB', $ticket['sslThumbprint']);
	}

	public function testInvokeAuthedRetriesNonPropertiesCall(): void
	{
		$fake = new FakeHttp();
		$logins = 0;
		$fake->when(fn(array $c) => str_contains($c['body'] ?? '', 'RetrieveServiceContent'),
			fn() => ['code' => 200, 'body' => $this->fixture('service-content.xml')]);
		$fake->when(fn(array $c) => str_contains($c['body'] ?? '', '<Login '),
			function () use (&$logins) {
				$logins++;
				return ['code' => 200, 'body' => $this->fixture('login-response.xml'), 'headers' => FakeHttp::soapSessionHeaders()];
			});
		$tries = 0;
		$fake->when(
			fn(array $c) => str_contains($c['body'] ?? '', 'AcquireTicket'),
			function () use (&$tries) {
				$tries++;
				if ($tries === 1) {
					return ['code' => 500, 'body' => $this->fixture('fault-not-authenticated.xml')];
				}
				return ['code' => 200, 'body' => $this->fixture('acquire-ticket.xml')];
			}
		);

		$ticket = $this->instance($fake->callable())->soap()
			->acquireTicket(['type' => 'VirtualMachine', 'id' => 'vm-42'], 'webmks');
		$this->assertSame(2, $logins);
		$this->assertSame(2, $tries);
		$this->assertSame('esx1.example.com', $ticket['host']);
	}

	// ── Phase 3: WebMKS method plumbing ────────────────────────────

	private function soapScreenshot(FakeHttp $fake): void
	{
		$png = file_get_contents(__DIR__ . '/fixtures/screenshot.png');
		$fake->when(fn(array $c) => str_contains($c['body'] ?? '', 'CreateScreenshot_Task'),
			fn() => ['code' => 200, 'body' => $this->fixture('task-ref.xml')]);
		$fake->when(fn(array $c) => str_contains($c['body'] ?? '', 'RetrievePropertiesEx') && str_contains($c['body'] ?? '', 'task-500'),
			fn() => ['code' => 200, 'body' => $this->fixture('task-screenshot-result.xml')]);
		$fake->when(fn(array $c) => str_contains($c['body'] ?? '', 'RetrievePropertiesEx') && str_contains($c['body'] ?? '', 'task-501'),
			fn() => ['code' => 200, 'body' => $this->fixture('task-delete-result.xml')]);
		$fake->when(fn(array $c) => str_contains($c['body'] ?? '', 'RetrievePropertiesEx'),
			fn() => ['code' => 200, 'body' => $this->fixture('parent-walk.xml')]);
		$fake->when(fn(array $c) => str_contains($c['body'] ?? '', 'DeleteDatastoreFile_Task'),
			fn() => ['code' => 200, 'body' => $this->fixture('delete-task-ref.xml')]);
		$fake->when(fn(array $c) => $c['method'] === 'GET' && str_contains($c['url'], '/folder/'),
			fn() => ['code' => 200, 'body' => $png]);
	}

	public function testAutoFallsBackToSoapWhenWebMksFails(): void
	{
		$fake = new FakeHttp();
		$this->soapHandshake($fake);
		$this->soapScreenshot($fake);

		$manager = $this->manager($fake->callable());
		// Factory seam: a WebMKS client whose open() always fails
		$manager->setWebMksFactory(fn($inst, $vmId) => new class($inst, $vmId) extends \VCenter\Console\WebMksClient {
			public function open(float $timeout = 10.0): void
			{
				throw new VCenterException('ws refused', 0, 'WebMks');
			}
		});

		$tools = new ConsoleTools($manager);
		$result = $tools->vm_screenshot('vm-42', 'auto');

		$texts = array_map(fn($b) => $b['text'] ?? '', $result->getContent());
		$this->assertContains('2x2 via soap', $texts);
		$this->assertNotEmpty(array_filter($texts, fn($t) => str_contains($t, 'webmks unavailable (ws refused)')));
	}

	public function testWebmksMethodFailsWithoutFallback(): void
	{
		$fake = new FakeHttp();
		$manager = $this->manager($fake->callable());
		$manager->setWebMksFactory(fn($inst, $vmId) => new class($inst, $vmId) extends \VCenter\Console\WebMksClient {
			public function open(float $timeout = 10.0): void
			{
				throw new VCenterException('ws refused', 0, 'WebMks');
			}
		});

		$tools = new ConsoleTools($manager);
		$this->expectException(VCenterException::class);
		$this->expectExceptionMessageMatches('/ws refused/');
		$tools->vm_screenshot('vm-42', 'webmks');
	}
}
