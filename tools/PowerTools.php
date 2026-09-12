<?php
/**
 * vCenter MCP Server — Power Tools
 *
 * @package    VCenterMCP\Tools
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

use EnchiladaMCP\McpTool;
use VCenter\InstanceManager;
use VCenter\VCenterException;

class PowerTools
{
	private InstanceManager $manager;

	public function __construct(InstanceManager $manager)
	{
		$this->manager = $manager;
	}

	private const ACTIONS = [
		'on' => ['endpoint' => 'power', 'action' => 'start'],
		'off' => ['endpoint' => 'power', 'action' => 'stop'],
		'reset' => ['endpoint' => 'power', 'action' => 'reset'],
		'suspend' => ['endpoint' => 'power', 'action' => 'suspend'],
		'shutdown_guest' => ['endpoint' => 'guest/power', 'action' => 'shutdown'],
		'reboot_guest' => ['endpoint' => 'guest/power', 'action' => 'reboot'],
	];

	#[McpTool(
		name: 'vm_power',
		description: 'Power action on a VM: on, off, reset, suspend (hard) or shutdown_guest, reboot_guest (requires VMware Tools).',
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'vm' => ['type' => 'string', 'description' => 'VM name or id'],
				'action' => ['type' => 'string', 'description' => 'on|off|reset|suspend|shutdown_guest|reboot_guest'],
				'instance' => ['type' => 'string', 'description' => 'vCenter instance name'],
			],
			'required' => ['vm', 'action'],
		]
	)]
	public function vm_power(string $vm, string $action, string $instance = ''): array
	{
		if (!isset(self::ACTIONS[$action])) {
			throw new VCenterException(
				"Unknown power action '{$action}'. Valid: " . implode(', ', array_keys(self::ACTIONS)),
				0, 'InvalidArgument'
			);
		}
		$inst = $this->manager->instance($instance);
		$id = $inst->inventory()->resolveVm($vm)['vm'];
		$target = self::ACTIONS[$action];

		$inst->rest()->post("vcenter/vm/{$id}/{$target['endpoint']}", null, ['action' => $target['action']]);
		$power = $inst->rest()->get("vcenter/vm/{$id}/power");
		return ['vm' => $id, 'action' => $action, 'power' => $power];
	}

	#[McpTool(
		name: 'get_vm_power',
		description: 'Current power state of a VM.',
		readOnlyHint: true,
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'vm' => ['type' => 'string', 'description' => 'VM name or id'],
				'instance' => ['type' => 'string', 'description' => 'vCenter instance name'],
			],
			'required' => ['vm'],
		]
	)]
	public function get_vm_power(string $vm, string $instance = ''): array
	{
		$inst = $this->manager->instance($instance);
		$id = $inst->inventory()->resolveVm($vm)['vm'];
		return $inst->rest()->get("vcenter/vm/{$id}/power") ?? [];
	}
}
