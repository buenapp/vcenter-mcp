<?php
/**
 * vCenter MCP Server — Device Tools
 *
 * CD-ROM/ISO, disk, NIC and boot configuration for VMs.
 *
 * @package    VCenterMCP\Tools
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

use EnchiladaMCP\McpTool;
use VCenter\InstanceManager;
use VCenter\VCenterException;

class DeviceTools
{
	private InstanceManager $manager;

	public function __construct(InstanceManager $manager)
	{
		$this->manager = $manager;
	}

	private function inst(string $instance): \VCenter\Instance
	{
		return $this->manager->instance($instance);
	}

	private function vmId(\VCenter\Instance $inst, string $vm): string
	{
		return $inst->inventory()->resolveVm($vm)['vm'];
	}

	#[McpTool(
		name: 'list_cdroms',
		description: 'List a VM\'s CD-ROM devices and their backing.',
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
	public function list_cdroms(string $vm, string $instance = ''): array
	{
		$inst = $this->inst($instance);
		$id = $this->vmId($inst, $vm);
		return $inst->inventory()->vmDevices($id, 'cdrom');
	}

	#[McpTool(
		name: 'attach_iso',
		description: 'Attach an ISO to a VM CD-ROM. Patches the given/existing CD-ROM or creates a new SATA CD-ROM; connects it when the VM is powered on. ISO form: "[Datastore] path/file.iso".',
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'vm' => ['type' => 'string', 'description' => 'VM name or id'],
				'iso' => ['type' => 'string', 'description' => 'ISO datastore path'],
				'cdrom' => ['type' => 'string', 'description' => 'Existing CD-ROM device id (e.g. "16000"); a new SATA CD-ROM is created when omitted'],
				'instance' => ['type' => 'string', 'description' => 'vCenter instance name'],
			],
			'required' => ['vm', 'iso'],
		]
	)]
	public function attach_iso(string $vm, string $iso, ?string $cdrom = null, string $instance = ''): array
	{
		$inst = $this->inst($instance);
		$id = $this->vmId($inst, $vm);
		$backing = ['type' => 'ISO_FILE', 'iso_file' => $iso];

		if ($cdrom !== null) {
			$this->reconfigure($inst, $id,
				fn() => $inst->rest()->patch("vcenter/vm/{$id}/hardware/cdrom/{$cdrom}", ['backing' => $backing]));
			$cdromId = $cdrom;
		} else {
			// Reuse an existing empty CD-ROM, else create a SATA one.
			// The collection endpoint returns only ids; vmDevices fetches
			// each entry's backing.
			$existing = $inst->inventory()->vmDevices($id, 'cdrom');
			$cdromId = null;
			foreach ($existing as $dev) {
				if (($dev['backing']['type'] ?? null) !== 'ISO_FILE') {
					$cdromId = $dev['cdrom'] ?? null;
					break;
				}
			}
			if ($cdromId !== null) {
				$this->reconfigure($inst, $id,
					fn() => $inst->rest()->patch("vcenter/vm/{$id}/hardware/cdrom/{$cdromId}", ['backing' => $backing]));
			} else {
				$result = $this->reconfigure($inst, $id,
					fn() => $inst->rest()->post("vcenter/vm/{$id}/hardware/cdrom", [
						'type' => 'SATA',
						'start_connected' => false,
						'backing' => $backing,
					]));
				$cdromId = is_string($result) ? $result : ($result['cdrom'] ?? (string) $result);
			}
		}

		// Connect when the VM is powered on so the guest sees the media
		$power = $inst->rest()->get("vcenter/vm/{$id}/power");
		$state = is_array($power) ? ($power['state'] ?? null) : $power;
		if ($state === 'POWERED_ON') {
			$this->reconfigure($inst, $id,
				fn() => $inst->rest()->post("vcenter/vm/{$id}/hardware/cdrom/{$cdromId}", null, ['action' => 'connect']));
		}

		return ['vm' => $id, 'cdrom' => $cdromId, 'iso' => $iso, 'connected' => $state === 'POWERED_ON'];
	}

	#[McpTool(
		name: 'detach_iso',
		description: 'Detach an ISO: disconnect the CD-ROM and switch its backing to CLIENT_DEVICE.',
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'vm' => ['type' => 'string', 'description' => 'VM name or id'],
				'cdrom' => ['type' => 'string', 'description' => 'CD-ROM device id (defaults to the only/first CD-ROM)'],
				'instance' => ['type' => 'string', 'description' => 'vCenter instance name'],
			],
			'required' => ['vm'],
		]
	)]
	public function detach_iso(string $vm, ?string $cdrom = null, string $instance = ''): array
	{
		$inst = $this->inst($instance);
		$id = $this->vmId($inst, $vm);

		if ($cdrom === null) {
			$existing = $inst->rest()->get("vcenter/vm/{$id}/hardware/cdrom") ?? [];
			if (empty($existing)) {
				throw new VCenterException("VM '{$vm}' has no CD-ROM devices", 0, 'NotFound');
			}
			$cdrom = $existing[0]['cdrom'] ?? null;
		}

		try {
			$this->reconfigure($inst, $id,
				fn() => $inst->rest()->post("vcenter/vm/{$id}/hardware/cdrom/{$cdrom}", null, ['action' => 'disconnect']));
		} catch (VCenterException $e) {
			if ($e->getErrorType() === 'QuestionPending') {
				throw $e;
			}
			// already disconnected — proceed to re-back
		}
		$this->reconfigure($inst, $id,
			fn() => $inst->rest()->patch("vcenter/vm/{$id}/hardware/cdrom/{$cdrom}", ['backing' => ['type' => 'CLIENT_DEVICE']]));
		return ['vm' => $id, 'cdrom' => $cdrom, 'detached' => true];
	}

	/**
	 * Run a device mutation; when the REST call fails at transport level
	 * (the PATCH hangs ~30 s when a question is pending), check
	 * runtime.question — a pending VM question blocks
	 * reconfigures until answered, which is what usually sits behind
	 * such a hang. Replaces the bare timeout with an actionable error.
	 *
	 * @throws VCenterException
	 */
	private function reconfigure(\VCenter\Instance $inst, string $vmId, callable $call): mixed
	{
		try {
			return $call();
		} catch (VCenterException $e) {
			if ($e->getErrorType() !== 'Transport') {
				throw $e;
			}
			$question = null;
			try {
				$props = $inst->properties()->get('VirtualMachine', $vmId, ['runtime.question']);
				$question = $props['runtime.question'] ?? null;
			} catch (VCenterException $ignored) {
				// best-effort; fall through to the original error
			}
			if (is_array($question) && !empty($question['id'])) {
				$choices = [];
				foreach ((array) ($question['choice']['choiceInfo'] ?? []) as $c) {
					$choices[] = (string) ($c['key'] ?? '');
				}
				throw new VCenterException(
					"Reconfigure of VM {$vmId} timed out — the VM has a pending question blocking it: "
						. "'" . (string) ($question['text'] ?? '') . "'. "
						. 'Inspect with get_vm_question and answer with answer_vm_question'
						. ($choices !== [] ? ' (choices: ' . implode(', ', array_filter($choices)) . ')' : ''),
					0, 'QuestionPending', $e
				);
			}
			throw $e;
		}
	}

	#[McpTool(
		name: 'list_disks',
		description: 'List a VM\'s disks.',
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
	public function list_disks(string $vm, string $instance = ''): array
	{
		$inst = $this->inst($instance);
		$id = $this->vmId($inst, $vm);
		return $inst->inventory()->vmDevices($id, 'disk');
	}

	#[McpTool(
		name: 'add_disk',
		description: 'Add a new SCSI disk to a VM.',
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'vm' => ['type' => 'string', 'description' => 'VM name or id'],
				'size_gib' => ['type' => 'integer', 'description' => 'Disk size in GiB'],
				'instance' => ['type' => 'string', 'description' => 'vCenter instance name'],
			],
			'required' => ['vm', 'size_gib'],
		]
	)]
	public function add_disk(string $vm, int $size_gib, string $instance = ''): array
	{
		$inst = $this->inst($instance);
		$id = $this->vmId($inst, $vm);
		$result = $inst->rest()->post("vcenter/vm/{$id}/hardware/disk", [
			'type' => 'SCSI',
			'new_vmdk' => ['capacity' => $size_gib * 1073741824],
		]);
		return ['vm' => $id, 'disk' => $result];
	}

	#[McpTool(
		name: 'list_nics',
		description: 'List a VM\'s NICs with MAC addresses and backing networks.',
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
	public function list_nics(string $vm, string $instance = ''): array
	{
		$inst = $this->inst($instance);
		$id = $this->vmId($inst, $vm);
		return $inst->inventory()->vmDevices($id, 'ethernet');
	}

	#[McpTool(
		name: 'add_nic',
		description: 'Add a NIC to a VM on the given network (name or id).',
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'vm' => ['type' => 'string', 'description' => 'VM name or id'],
				'network' => ['type' => 'string', 'description' => 'Network name or id'],
				'type' => ['type' => 'string', 'description' => 'Adapter type (default VMXNET3)'],
				'instance' => ['type' => 'string', 'description' => 'vCenter instance name'],
			],
			'required' => ['vm', 'network'],
		]
	)]
	public function add_nic(string $vm, string $network, string $type = 'VMXNET3', string $instance = ''): array
	{
		$inst = $this->inst($instance);
		$id = $this->vmId($inst, $vm);
		$net = $inst->inventory()->resolveNetwork($network);
		$netType = ($net['type'] ?? 'STANDARD_PORTGROUP') === 'DISTRIBUTED_PORTGROUP'
			? 'DISTRIBUTED_PORTGROUP' : 'STANDARD_PORTGROUP';
		$result = $inst->rest()->post("vcenter/vm/{$id}/hardware/ethernet", [
			'type' => $type,
			'start_connected' => true,
			'backing' => ['type' => $netType, 'network' => $net['network']],
		]);
		return ['vm' => $id, 'nic' => $result, 'network' => $net['network']];
	}

	#[McpTool(
		name: 'set_boot',
		description: 'Set VM boot firmware, boot device order and/or enter-setup-mode flag.',
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'vm' => ['type' => 'string', 'description' => 'VM name or id'],
				'firmware' => ['type' => 'string', 'description' => 'BIOS or EFI'],
				'order' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Boot device order: CDROM, DISK, ETHERNET, FLOPPY'],
				'enter_setup_mode' => ['type' => 'boolean', 'description' => 'Enter firmware setup on next boot'],
				'instance' => ['type' => 'string', 'description' => 'vCenter instance name'],
			],
			'required' => ['vm'],
		]
	)]
	public function set_boot(string $vm, ?string $firmware = null, ?array $order = null, ?bool $enter_setup_mode = null, string $instance = ''): array
	{
		$inst = $this->inst($instance);
		$id = $this->vmId($inst, $vm);

		if ($firmware !== null || $enter_setup_mode !== null) {
			$patch = [];
			if ($firmware !== null) $patch['type'] = strtoupper($firmware);
			if ($enter_setup_mode !== null) $patch['enter_setup_mode'] = $enter_setup_mode;
			$inst->rest()->patch("vcenter/vm/{$id}/hardware/boot", $patch);
		}
		if ($order !== null) {
			$inst->rest()->put("vcenter/vm/{$id}/hardware/boot/device", [
				'devices' => array_map(fn($d) => ['type' => strtoupper($d)], $order),
			]);
		}
		return [
			'boot' => $inst->rest()->get("vcenter/vm/{$id}/hardware/boot"),
			'devices' => $inst->rest()->get("vcenter/vm/{$id}/hardware/boot/device"),
		];
	}
}
