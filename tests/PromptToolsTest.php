<?php

use PHPUnit\Framework\TestCase;
use EnchiladaMCP\McpServer;
use VCenter\InstanceManager;

require_once __DIR__ . '/../tools/PromptTools.php';
require_once __DIR__ . '/lib/FakeHttp.php';

class PromptToolsTest extends TestCase
{
	private function server(): McpServer
	{
		$manager = new InstanceManager([
			'a' => ['url' => 'https://a.test', 'username' => 'u', 'password' => 'p'],
		]);
		$server = new McpServer('test', '0');
		$server->register(new PromptTools($manager));
		return $server;
	}

	private function call(McpServer $server, int $id, string $method, array $params = []): array
	{
		return $server->handleRequest(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params]);
	}

	public function testCapabilitiesAdvertisePrompts(): void
	{
		$r = $this->call($this->server(), 1, 'initialize', [
			'protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 't', 'version' => '0'],
		]);
		$this->assertTrue(isset($r['result']['capabilities']['prompts']));
		$this->assertTrue(isset($r['result']['capabilities']['completions']));
	}

	public function testPromptsListShowsDeployVm(): void
	{
		$r = $this->call($this->server(), 1, 'prompts/list');
		$prompts = array_column($r['result']['prompts'], null, 'name');
		$this->assertArrayHasKey('deploy_vm', $prompts);
		$args = array_column($prompts['deploy_vm']['arguments'], 'required', 'name');
		$this->assertTrue($args['name']);
		$this->assertFalse((bool) ($args['datacenter'] ?? false));
	}

	public function testDeployVmRendersSpec(): void
	{
		$r = $this->call($this->server(), 1, 'prompts/get', [
			'name' => 'deploy_vm',
			'arguments' => ['name' => 'box1', 'memory_gib' => '8', 'zfs_volumes' => '2', 'zfs_gib' => '40'],
		]);
		$text = $r['result']['messages'][0]['content']['text'] ?? '';
		foreach ([
			'box1', 'FREEBSD_14_64', 'VMX_21', "'LSILOGICSAS', 'PVSCSI'",
			"use_auto_detect=true", "'independent_persistent'", 'BSDINSTALL_DISTSITE',
			'autoprovision_server.sh', "'wheel operator'", 'size_gib=40',
		] as $must) {
			$this->assertStringContainsString($must, $text);
		}
		$this->assertSame(2, substr_count($text, "add_disk(vm='box1'"));
		$this->assertStringContainsString('memory_mib=8192', $text);
	}

	public function testMissingNameIs32602(): void
	{
		$r = $this->call($this->server(), 1, 'prompts/get', ['name' => 'deploy_vm']);
		$this->assertSame(-32602, $r['error']['code'] ?? null);
	}

	public function testIsoCompletionIsOffline(): void
	{
		$r = $this->call($this->server(), 1, 'completion/complete', [
			'ref' => ['type' => 'ref/prompt', 'name' => 'deploy_vm'],
			'argument' => ['name' => 'iso', 'value' => 'bootonly'],
		]);
		$this->assertSame(
			['[CDImages] FreeBSD OS/FreeBSD-15.1-RELEASE-amd64-bootonly.iso'],
			$r['result']['completion']['values'] ?? null
		);
	}

	public function testUnknownRefIs32602(): void
	{
		$r = $this->call($this->server(), 1, 'completion/complete', [
			'ref' => ['type' => 'ref/prompt', 'name' => 'nope'],
			'argument' => ['name' => 'name', 'value' => ''],
		]);
		$this->assertSame(-32602, $r['error']['code'] ?? null);
	}
}
