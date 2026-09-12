<?php
/**
 * vCenter MCP Server — Instance Tools
 *
 * @package    VCenterMCP\Tools
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

use EnchiladaMCP\McpTool;
use VCenter\InstanceManager;

class InstanceTools
{
	private InstanceManager $manager;

	public function __construct(InstanceManager $manager)
	{
		$this->manager = $manager;
	}

	#[McpTool(
		name: 'list_vcenter_instances',
		description: 'List configured vCenter instances.',
		readOnlyHint: true,
		inputSchema: [
			'type' => 'object',
			'properties' => new \stdClass(),
		]
	)]
	public function list_vcenter_instances(): array
	{
		return [
			'default' => $this->manager->getDefault(),
			'instances' => $this->manager->listInstances(),
		];
	}

	#[McpTool(
		name: 'get_vcenter_info',
		description: 'vCenter server info: fullName, version, build, apiVersion plus REST/SOAP session state.',
		readOnlyHint: true,
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'instance' => ['type' => 'string', 'description' => 'vCenter instance name (default instance when omitted)'],
			],
		]
	)]
	public function get_vcenter_info(string $instance = ''): array
	{
		$inst = $this->manager->instance($instance);
		$about = $inst->soap()->about();
		return [
			'url' => $inst->url(),
			'about' => $about,
			'rest_session' => $inst->rest()->hasSession(),
			'soap_session' => $inst->soap()->hasSession(),
		];
	}
}
