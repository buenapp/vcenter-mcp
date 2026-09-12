<?php
/**
 * vCenter MCP Server — Guest Tools
 *
 * @package    VCenterMCP\Tools
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

use EnchiladaMCP\McpTool;
use VCenter\InstanceManager;
use VCenter\VCenterException;

class GuestTools
{
	private InstanceManager $manager;

	public function __construct(InstanceManager $manager)
	{
		$this->manager = $manager;
	}

	#[McpTool(
		name: 'get_guest_info',
		description: 'Guest OS identity and network interfaces (requires VMware Tools; a clean message is returned when Tools is absent).',
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
	public function get_guest_info(string $vm, string $instance = ''): array
	{
		$inst = $this->manager->instance($instance);
		$id = $inst->inventory()->resolveVm($vm)['vm'];

		try {
			$identity = $inst->rest()->get("vcenter/vm/{$id}/guest/identity");
		} catch (VCenterException $e) {
			if ($e->getCode() === 503 || str_contains($e->getMessage(), 'tools')) {
				return ['vm' => $id, 'tools_running' => false,
					'message' => 'VMware Tools is not running in the guest; identity and network info are unavailable.'];
			}
			throw $e;
		}

		$interfaces = null;
		try {
			$interfaces = $inst->rest()->get("vcenter/vm/{$id}/guest/networking/interfaces");
		} catch (VCenterException $e) {
			// interfaces are best-effort once identity succeeded
		}

		return ['vm' => $id, 'tools_running' => true, 'identity' => $identity, 'interfaces' => $interfaces];
	}

	#[McpTool(
		name: 'find_vm_ip',
		description: 'Guest IP addresses when VMware Tools reports them; otherwise the NIC MACs with guidance for finding IPs via DHCP leases or the console.',
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
	public function find_vm_ip(string $vm, string $instance = ''): array
	{
		$inst = $this->manager->instance($instance);
		$id = $inst->inventory()->resolveVm($vm)['vm'];

		$addresses = [];
		$toolsOk = false;
		try {
			$interfaces = $inst->rest()->get("vcenter/vm/{$id}/guest/networking/interfaces");
			$toolsOk = true;
			foreach ((array) $interfaces as $iface) {
				foreach ((array) ($iface['ip']['ip_addresses'] ?? []) as $addr) {
					$addresses[] = [
						'nic' => $iface['nic'] ?? null,
						'mac' => $iface['mac_address'] ?? null,
						'address' => $addr['ip_address'] ?? $addr,
						'prefix' => $addr['prefix_length'] ?? null,
					];
				}
			}
		} catch (VCenterException $e) {
			$toolsOk = false;
		}

		if (!$toolsOk) {
			$macs = [];
			foreach ($inst->inventory()->vmDevices($id, 'ethernet') as $nic) {
				$macs[] = ['nic' => $nic['nic'] ?? null, 'mac' => $nic['mac_address'] ?? null];
			}
			return [
				'vm' => $id,
				'tools_running' => false,
				'macs' => $macs,
				'message' => 'VMware Tools is not reporting guest IPs. Match the NIC MACs against DHCP leases, or read addresses from the console (e.g. ifconfig).',
			];
		}

		return ['vm' => $id, 'tools_running' => true, 'addresses' => $addresses];
	}
}
