<?php

use PHPUnit\Framework\TestCase;
use VCenter\Instance;
use VCenter\VCenterException;

require_once __DIR__ . '/lib/FakeHttp.php';

class PropertyCollectorTaskTest extends TestCase
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

	private function soapHandshake(FakeHttp $fake): void
	{
		$fake->when(
			fn(array $c) => str_contains($c['body'] ?? '', 'RetrieveServiceContent'),
			fn() => ['code' => 200, 'body' => $this->fixture('service-content.xml')]
		);
		$fake->when(
			fn(array $c) => str_contains($c['body'] ?? '', '<Login '),
			fn() => ['code' => 200, 'body' => $this->fixture('login-response.xml'), 'headers' => FakeHttp::soapSessionHeaders()]
		);
	}

	public function testParsesRetrievePropertiesEx(): void
	{
		$fake = new FakeHttp();
		$this->soapHandshake($fake);
		$fake->when(
			fn(array $c) => str_contains($c['body'] ?? '', 'RetrievePropertiesEx'),
			fn() => ['code' => 200, 'body' => $this->fixture('retrieve-properties-ex.xml')]
		);

		$inst = $this->instance($fake->callable());
		$rows = $inst->soap()->properties(
			[['type' => 'HostSystem', 'id' => 'host-12'], ['type' => 'Task', 'id' => 'task-99']],
			['HostSystem' => ['name', 'datastore'], 'Task' => ['info']]
		);

		$this->assertSame('esx1.example.com', $rows['HostSystem:host-12']['name']);
		$datastores = $rows['HostSystem:host-12']['datastore'];
		$this->assertSame('datastore-21', $datastores[0]['id']);
		$this->assertSame('datastore-22', $datastores[1]['id']);
		$this->assertSame('success', $rows['Task:task-99']['info']['state']);
	}

	public function testTaskWaitSuccess(): void
	{
		$fake = new FakeHttp();
		$this->soapHandshake($fake);
		$fake->when(
			fn(array $c) => str_contains($c['body'] ?? '', 'RetrievePropertiesEx'),
			fn() => ['code' => 200, 'body' => $this->fixture('retrieve-properties-ex.xml')]
		);

		$inst = $this->instance($fake->callable());
		$result = $inst->tasks()->wait(['type' => 'Task', 'id' => 'task-99'], 5, fn() => null);

		$this->assertSame('success', $result['state']);
		$this->assertSame('FreeBSD-15.1-RELEASE-amd64-bootonly.iso', $result['result']['file']['path']);
	}

	public function testTaskWaitError(): void
	{
		$fake = new FakeHttp();
		$this->soapHandshake($fake);
		$body = '<?xml version="1.0"?><soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Body>'
			. '<RetrievePropertiesExResponse xmlns="urn:vim25"><returnval><objects>'
			. '<obj type="Task">task-1</obj>'
			. '<propSet><name>info</name><val><state>error</state><error><localizedMessage>Clone failed: no space</localizedMessage></error></val></propSet>'
			. '</objects></returnval></RetrievePropertiesExResponse></soapenv:Body></soapenv:Envelope>';
		$fake->when(
			fn(array $c) => str_contains($c['body'] ?? '', 'RetrievePropertiesEx'),
			fn() => ['code' => 200, 'body' => $body]
		);

		$inst = $this->instance($fake->callable());
		try {
			$inst->tasks()->wait(['type' => 'Task', 'id' => 'task-1'], 5, fn() => null);
			$this->fail('expected VCenterException');
		} catch (VCenterException $e) {
			$this->assertStringContainsString('no space', $e->getMessage());
			$this->assertSame('TaskError', $e->getErrorType());
		}
	}

	public function testTaskWaitTimeout(): void
	{
		$fake = new FakeHttp();
		$this->soapHandshake($fake);
		$body = '<?xml version="1.0"?><soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Body>'
			. '<RetrievePropertiesExResponse xmlns="urn:vim25"><returnval><objects>'
			. '<obj type="Task">task-2</obj>'
			. '<propSet><name>info</name><val><state>running</state><progress>40</progress></val></propSet>'
			. '</objects></returnval></RetrievePropertiesExResponse></soapenv:Body></soapenv:Envelope>';
		$fake->when(
			fn(array $c) => str_contains($c['body'] ?? '', 'RetrievePropertiesEx'),
			fn() => ['code' => 200, 'body' => $body]
		);

		$inst = $this->instance($fake->callable());
		try {
			$inst->tasks()->wait(['type' => 'Task', 'id' => 'task-2'], 0, fn() => null);
			$this->fail('expected VCenterException');
		} catch (VCenterException $e) {
			$this->assertSame('TaskTimeout', $e->getErrorType());
		}
	}

	public function testDatacenterWalk(): void
	{
		$fake = new FakeHttp();
		$this->soapHandshake($fake);
		$body = '<?xml version="1.0"?><soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Body>'
			. '<RetrievePropertiesExResponse xmlns="urn:vim25"><returnval>'
			. '<objects><obj type="VirtualMachine">vm-42</obj>'
			. '<propSet><name>parent</name><val type="Folder" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">group-v1</val></propSet>'
			. '</objects>'
			. '<objects><obj type="Folder">group-v1</obj>'
			. '<propSet><name>parent</name><val type="Datacenter">datacenter-1</val></propSet>'
			. '</objects>'
			. '<objects><obj type="Datacenter">datacenter-1</obj>'
			. '<propSet><name>name</name><val>DC1</val></propSet>'
			. '</objects>'
			. '</returnval></RetrievePropertiesExResponse></soapenv:Body></soapenv:Envelope>';
		$fake->when(
			fn(array $c) => str_contains($c['body'] ?? '', 'RetrievePropertiesEx'),
			fn() => ['code' => 200, 'body' => $body]
		);

		$dc = $this->instance($fake->callable())->properties()
			->datacenterOf(['type' => 'VirtualMachine', 'id' => 'vm-42']);
		$this->assertSame('datacenter-1', $dc['id']);
		$this->assertSame('DC1', $dc['name']);
	}
}
