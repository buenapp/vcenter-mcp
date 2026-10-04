<?php

use PHPUnit\Framework\TestCase;
use VCenter\InstanceManager;
use VCenter\VCenterException;

require_once __DIR__ . '/lib/FakeHttp.php';
require_once __DIR__ . '/../tools/OvfTools.php';

class OvfToolsTest extends TestCase
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
		return file_get_contents(__DIR__ . '/fixtures/' . $name);
	}

	private function fake(): FakeHttp
	{
		$fake = new FakeHttp();
		$fake->on('POST', '/api/session', fn() => ['code' => 200, 'body' => '"tok"']);
		$fake->when(fn(array $c) => str_contains($c['body'] ?? '', 'RetrieveServiceContent'),
			fn() => ['code' => 200, 'body' => $this->fixture('soap/service-content.xml')]);
		$fake->when(fn(array $c) => str_contains($c['body'] ?? '', '<Login '),
			fn() => ['code' => 200, 'body' => $this->fixture('soap/login-response.xml'),
				'headers' => FakeHttp::soapSessionHeaders()]);
		return $fake;
	}

	/** SOAP property dispatch: datastore summary.url + parent walk to DC1. */
	private function soapProperties(FakeHttp $fake): void
	{
		$fake->when(fn(array $c) => str_contains($c['body'] ?? '', 'RetrievePropertiesEx'), function (array $c) {
			if (str_contains($c['body'], '<pathSet>info</pathSet>')) {
				// browseDatastore task poll
				return ['code' => 200, 'body' => '<?xml version="1.0"?>'
					. '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><soapenv:Body>'
					. '<RetrievePropertiesExResponse xmlns="urn:vim25"><returnval><objects>'
					. '<obj type="Task">task-99</obj>'
					. '<propSet><name>info</name><val xsi:type="TaskInfo">'
					. '<state>success</state>'
					. '<result xsi:type="HostDatastoreBrowserSearchResults">'
					. '<folderPath>[CDImages] packages</folderPath>'
					. '<file xsi:type="IsoImageFileInfo"><path>app.ova</path><fileSize>4096</fileSize></file>'
					. '</result></val>'
					. '</propSet>'
					. '</objects></returnval></RetrievePropertiesExResponse></soapenv:Body></soapenv:Envelope>'];
			}
			if (str_contains($c['body'], '<pathSet>browser</pathSet>')) {
				return ['code' => 200, 'body' => '<?xml version="1.0"?>'
					. '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><soapenv:Body>'
					. '<RetrievePropertiesExResponse xmlns="urn:vim25"><returnval><objects>'
					. '<obj type="Datastore">datastore-21</obj>'
					. '<propSet><name>name</name><val xsi:type="xsd:string">CDImages</val></propSet>'
					. '<propSet><name>browser</name><val xsi:type="ManagedObjectReference" type="HostDatastoreBrowser">datastoreBrowser</val></propSet>'
					. '</objects></returnval></RetrievePropertiesExResponse></soapenv:Body></soapenv:Envelope>'];
			}
			$objects = '';
			if (str_contains($c['body'], 'summary.url')) {
				return ['code' => 200, 'body' => $this->fixture('soap/datastore-summary-url.xml')];
			}
			if (str_contains($c['body'], '>datastore-21<')) {
				$objects = '<obj type="Datastore">datastore-21</obj>'
					. '<propSet><name>parent</name><val type="Folder" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">group-s4</val></propSet>';
			} elseif (str_contains($c['body'], '>group-s4<')) {
				$objects = '<obj type="Folder">group-s4</obj>'
					. '<propSet><name>parent</name><val type="Datacenter" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">datacenter-1</val></propSet>'
					. '<propSet><name>name</name><val>datastores</val></propSet>';
			} else {
				$objects = '<obj type="Datacenter">datacenter-1</obj><propSet><name>name</name><val>DC1</val></propSet>';
			}
			return ['code' => 200, 'body' => '<?xml version="1.0"?>'
				. '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Body>'
				. '<RetrievePropertiesExResponse xmlns="urn:vim25"><returnval><objects>' . $objects
				. '</objects></returnval></RetrievePropertiesExResponse></soapenv:Body></soapenv:Envelope>'];
		});
		$fake->when(fn(array $c) => str_contains($c['body'] ?? '', 'SearchDatastore_Task'),
			fn() => ['code' => 200, 'body' => '<?xml version="1.0"?>'
				. '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Body>'
				. '<SearchDatastore_TaskResponse xmlns="urn:vim25"><returnval type="Task">task-99</returnval></SearchDatastore_TaskResponse>'
				. '</soapenv:Body></soapenv:Envelope>']);
	}

	private function datastoreRoute(FakeHttp $fake): void
	{
		$fake->on('GET', '/api/vcenter/datastore', fn(array $c) => str_contains($c['url'], 'names=CDImages')
			? ['code' => 200, 'body' => '[{"datastore":"datastore-21","name":"CDImages","type":"NFS"}]']
			: ['code' => 200, 'body' => '[]']);
	}

	/** Content library routes; $sessionState drives the import wait. */
	private function libraryRoutes(FakeHttp $fake, string $sessionState = 'DONE', ?string $errorMessage = null): void
	{
		// nested resource routes first: FakeHttp matching is substring + first hit
		$fake->when(fn(array $c) => $c['method'] === 'POST' && str_contains($c['url'], 'updatesession/file')
				&& str_contains($c['url'], '~action=add'),
			fn() => ['code' => 200, 'body' => '{"value":{"name":"app.ova","status":"WAITING_FOR_TRANSFER"}}']);
		$fake->when(fn(array $c) => $c['method'] === 'GET' && str_contains($c['url'], '/update-session/session-9'),
			fn() => ['code' => 200, 'body' => json_encode([
				'id' => 'session-9',
				'library_item_id' => 'item-55',
				'state' => $sessionState,
				'error_message' => $errorMessage !== null ? ['default_message' => $errorMessage] : null,
			])]);
		$fake->when(fn(array $c) => $c['method'] === 'POST' && str_contains($c['url'], '/update-session/session-9'),
			fn() => ['code' => 204, 'body' => '']);
		$fake->when(fn(array $c) => $c['method'] === 'POST' && str_contains($c['url'], '/content/library/item/update-session'),
			fn() => ['code' => 201, 'body' => '"session-9"']);
		$fake->when(fn(array $c) => $c['method'] === 'GET' && str_contains($c['url'], '/content/library/lib-1'),
			fn() => ['code' => 200, 'body' => '{"id":"lib-1","name":"vcenter-mcp-staging","type":"LOCAL"}']);
		$fake->on('GET', '/api/content/library', fn() => ['code' => 200, 'body' => '["lib-1"]']);
		$fake->when(fn(array $c) => $c['method'] === 'POST' && str_contains($c['url'], '/api/content/library/item'),
			fn() => ['code' => 201, 'body' => '"item-55"']);
		$fake->on('DELETE', '/api/content/library/item/item-55', fn() => ['code' => 204, 'body' => '']);
	}

	private function deployRoutes(FakeHttp $fake, array $filter = []): void
	{
		$fake->when(fn(array $c) => $c['method'] === 'POST' && str_contains($c['url'], 'action=filter'),
			fn() => ['code' => 200, 'body' => json_encode($filter)]);
		$fake->when(fn(array $c) => $c['method'] === 'POST' && str_contains($c['url'], 'action=deploy'),
			fn() => ['code' => 200, 'body' => '{"succeeded":true,"resource_id":{"type":"VirtualMachine","id":"vm-900"}}']);
		$fake->on('GET', '/api/vcenter/host', fn() => ['code' => 200, 'body' => '[{"host":"host-12","name":"esx1.example.com"}]']);
		$fake->on('GET', '/api/vcenter/folder', fn() => ['code' => 200,
			'body' => '[{"folder":"group-v50","name":"vm","type":"VIRTUAL_MACHINE"}]']);
		$fake->on('POST', '/api/vcenter/vm/vm-900/power', fn() => ['code' => 200, 'body' => '']);
	}

	public function testOvfInfoFromDatastoreOvf(): void
	{
		$fake = $this->fake();
		$this->datastoreRoute($fake);
		$this->soapProperties($fake);
		$fake->on('GET', '/folder/', fn() => ['code' => 200, 'body' => $this->fixture('app.ovf')]);

		$tools = new OvfTools($this->manager($fake->callable()));
		$info = $tools->ovf_info('[CDImages] packages/app.ovf');

		$this->assertSame('ovf', $info['kind']);
		$this->assertSame('Appliance-X', $info['name']);
		$this->assertSame(['VM Network-ovf'], array_column($info['networks'], 'name'));
		$this->assertSame('hostname', $info['properties'][0]['key']);
		$this->assertTrue($info['properties'][0]['user_configurable']);
		$this->assertFalse($info['properties'][1]['user_configurable']);
		$this->assertSame('vmdisk1', $info['disks'][0]['disk_id']);
		$this->assertSame('Sample terms', $info['eulas'][0]['license']);
	}

	public function testOvfInfoFromDatastoreOvaStagedStream(): void
	{
		$fake = $this->fake();
		$this->datastoreRoute($fake);
		$this->soapProperties($fake);
		$fake->on('GET', '/folder/', fn() => ['code' => 200, 'body' => $this->fixture('app.ova')]);

		$tools = new OvfTools($this->manager($fake->callable()));
		$info = $tools->ovf_info('[CDImages] packages/app.ova');

		$this->assertSame('ova', $info['kind']);
		$this->assertSame('Appliance-X', $info['name']);
		$this->assertSame('app.ovf', $info['descriptor']);
	}

	public function testOvfInfoFromUrl(): void
	{
		$fake = $this->fake();
		$fake->on('GET', 'https://files.example.com/app.ova',
			fn() => ['code' => 200, 'body' => $this->fixture('app.ova')]);

		$tools = new OvfTools($this->manager($fake->callable()));
		$info = $tools->ovf_info('https://files.example.com/app.ova');

		$this->assertSame('ova', $info['kind']);
		$this->assertSame('Appliance-X', $info['name']);
	}

	public function testOvfInfoUrlHttpError(): void
	{
		$fake = $this->fake();
		$fake->on('GET', 'https://files.example.com/app.ova', fn() => ['code' => 404, 'body' => '']);

		$tools = new OvfTools($this->manager($fake->callable()));
		try {
			$tools->ovf_info('https://files.example.com/app.ova');
			$this->fail('expected VCenterException');
		} catch (VCenterException $e) {
			$this->assertSame('Download', $e->getErrorType());
		}
	}

	public function testDeployOvaPullsFromDatastore(): void
	{
		$fake = $this->fake();
		$this->datastoreRoute($fake);
		$this->soapProperties($fake);
		$this->libraryRoutes($fake);
		$this->deployRoutes($fake);

		$tools = new OvfTools($this->manager($fake->callable()));
		$result = $tools->deploy_ova(
			'[CDImages] packages/app.ova', 'appliance-01',
			network_mappings: ['VM Network-ovf' => 'network-9'],
			host: 'host-12', datastore: 'datastore-21',
			folder: 'vm', resource_pool: 'resgroup-9',
			properties: ['hostname' => 'appliance-01'],
			accept_eula: true
		);

		$this->assertSame('vm-900', $result['vm']);
		$this->assertFalse($result['powered_on']);

		$add = $fake->calls('POST', 'updatesession/file')[0];
		$spec = json_decode($add['body'], true)['file_spec'];
		$this->assertSame('PULL', $spec['source_type']);
		$this->assertSame(4096, $spec['size']);
		$this->assertSame('ds:///vmfs/volumes/6655aa42-aaaa-bbbb-0050566332aa/packages/app.ova',
			$spec['source_endpoint']['uri']);
		$this->assertSame('app.ova', $spec['name']);

		$deploy = array_values(array_filter($fake->calls('POST', 'ovf/library-item'),
			fn($c) => str_contains($c['url'], 'action=deploy')))[0];
		$body = json_decode($deploy['body'], true);
		$this->assertSame('appliance-01', $body['deployment_spec']['name']);
		$this->assertTrue($body['deployment_spec']['accept_all_EULA']);
		$this->assertSame('datastore-21', $body['deployment_spec']['default_datastore_id']);
		$this->assertSame(['VM Network-ovf' => 'network-9'],
			$body['deployment_spec']['network_mappings']);
		$this->assertSame(['hostname' => 'appliance-01'],
			$body['deployment_spec']['properties']);
		$this->assertSame('host-12', $body['target']['host_id']);
		$this->assertNotEmpty($fake->calls('DELETE', '/item/item-55'));
	}

	public function testDeployOvaDiscoversNetworksThroughFilter(): void
	{
		$fake = $this->fake();
		$this->datastoreRoute($fake);
		$this->soapProperties($fake);
		$this->libraryRoutes($fake);
		$this->deployRoutes($fake, ['Networks' => ['VM Network-ovf']]);
		$fake->on('GET', '/api/vcenter/network', fn() => ['code' => 200,
			'body' => '[{"network":"network-9","name":"VM Network","type":"STANDARD_PORTGROUP"}]']);

		$tools = new OvfTools($this->manager($fake->callable()));
		$result = $tools->deploy_ova(
			'[CDImages] packages/app.ova', 'appliance-01',
			network: 'VM Network',
			host: 'host-12', datastore: 'datastore-21',
			folder: 'vm', resource_pool: 'resgroup-9',
			power_on: true
		);

		$this->assertSame('vm-900', $result['vm']);
		$this->assertTrue($result['powered_on']);

		$deploy = array_values(array_filter($fake->calls('POST', 'ovf/library-item'),
			fn($c) => str_contains($c['url'], 'action=deploy')))[0];
		$body = json_decode($deploy['body'], true);
		$this->assertSame(['VM Network-ovf' => 'network-9'],
			$body['deployment_spec']['network_mappings']);
		$this->assertContains('action=start', explode('?', $fake->calls('POST', '/vm/vm-900/power')[0]['url']));
	}

	public function testDeployOvaTransferErrorCleansUp(): void
	{
		$fake = $this->fake();
		$this->datastoreRoute($fake);
		$this->soapProperties($fake);
		$this->libraryRoutes($fake, 'ERROR', 'cannot read ds:/// volume: file locked');
		$this->deployRoutes($fake);

		$tools = new OvfTools($this->manager($fake->callable()));
		try {
			$tools->deploy_ova(
				'[CDImages] packages/app.ova', 'appliance-01',
				network_mappings: ['VM Network-ovf' => 'network-9'],
				host: 'host-12', datastore: 'datastore-21',
				folder: 'vm', resource_pool: 'resgroup-9'
			);
			$this->fail('expected VCenterException');
		} catch (VCenterException $e) {
			$this->assertSame('Transfer', $e->getErrorType());
			$this->assertStringContainsString('file locked', $e->getMessage());
		}
		// session cancel + item delete on failure
		$cancels = array_filter($fake->calls('POST', '/update-session/session-9'),
			fn($c) => str_contains($c['url'], 'action=cancel'));
		$this->assertNotEmpty($cancels);
		$this->assertNotEmpty($fake->calls('DELETE', '/item/item-55'));
		$deploys = array_filter($fake->calls('POST', 'ovf/library-item'),
			fn($c) => str_contains($c['url'], 'action=deploy'));
		$this->assertSame([], $deploys);
	}

	public function testDeployOvaRejectsBareOvf(): void
	{
		$fake = $this->fake();

		$tools = new OvfTools($this->manager($fake->callable()));
		try {
			$tools->deploy_ova('[CDImages] packages/app.ovf', 'appliance-01');
			$this->fail('expected VCenterException');
		} catch (VCenterException $e) {
			$this->assertSame('InvalidArgument', $e->getErrorType());
		}
		$this->assertSame([], $fake->calls('POST', '/api/content/library'));
	}
}
