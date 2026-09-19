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

		$answered = false;
		for ($attempt = 0; ; $attempt++) {
			try {
				$this->reconfigure($inst, $id,
					fn() => $inst->rest()->post("vcenter/vm/{$id}/hardware/cdrom/{$cdrom}", null, ['action' => 'disconnect']));
				break;
			} catch (VCenterException $e) {
				if ($e->getErrorType() === 'QuestionPending') {
					// A guest-locked CD-ROM door raises a blocking question
					// whose answer is the point of the detach — say yes
					// and finish the reconfigure in the same call.
					if (!$answered && $this->answerBlockingQuestion($inst, $id, 'button.yes')) {
						$answered = true;
						continue;
					}
					throw $e;
				}
				// already disconnected — proceed to re-back
				break;
			}
		}
		$this->reconfigure($inst, $id,
			fn() => $inst->rest()->patch("vcenter/vm/{$id}/hardware/cdrom/{$cdrom}", ['backing' => ['type' => 'CLIENT_DEVICE']]));
		return ['vm' => $id, 'cdrom' => $cdrom, 'detached' => true, 'question_answered' => $answered];
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

	/**
	 * Answer the VM's currently pending question with the given choice
	 * (only when the choice is offered). Returns false when there is no
	 * pending question or the choice is not one of its options.
	 */
	private function answerBlockingQuestion(\VCenter\Instance $inst, string $vmId, string $choice): bool
	{
		try {
			$props = $inst->properties()->get('VirtualMachine', $vmId, ['runtime.question']);
			$question = $props['runtime.question'] ?? null;
		} catch (VCenterException) {
			return false;
		}
		if (!is_array($question) || empty($question['id'])) {
			return false;
		}
		foreach ((array) ($question['choice']['choiceInfo'] ?? []) as $c) {
			if (($c['key'] ?? '') === $choice) {
				$inst->soap()->answerVm(['type' => 'VirtualMachine', 'id' => $vmId],
					(string) $question['id'], $choice);
				return true;
			}
		}
		return false;
	}

	#[McpTool(
		name: 'list_disks',
		description: 'List a VM\'s disks with their controller binding (key, type, unit number), disk mode (persistent / independent_persistent / independent_nonpersistent) and provisioning (thin/thick). Read via vim25 SOAP; the REST API reports none of these.',
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
		return $inst->inventory()->vmStorageDisks($id);
	}

	#[McpTool(
		name: 'list_controllers',
		description: 'List a VM\'s SCSI controllers with key, bus number and type (buslogic / lsilogic / lsilogic-sas / paravirtual). Read via vim25 SOAP; the REST API does not model controller types.',
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
	public function list_controllers(string $vm, string $instance = ''): array
	{
		$inst = $this->inst($instance);
		$id = $this->vmId($inst, $vm);
		return $inst->inventory()->vmStorageControllers($id);
	}

	#[McpTool(
		name: 'add_disk',
		description: 'Add a new SCSI disk to a VM. Without further options it attaches to an existing controller via REST. With controller_type (buslogic / lsilogic / lsilogic-sas / paravirtual), disk_mode (persistent / independent_persistent / independent_nonpersistent) and/or thin, the disk (and the controller, when none of that type exists) is created in one ReconfigVM_Task over vim25 — creating a controller requires the VM powered off.',
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'vm' => ['type' => 'string', 'description' => 'VM name or id'],
				'size_gib' => ['type' => 'integer', 'description' => 'Disk size in GiB'],
				'controller_type' => ['type' => 'string', 'description' => 'Controller type to attach to (created when absent): buslogic, lsilogic, lsilogic-sas, paravirtual'],
				'disk_mode' => ['type' => 'string', 'description' => 'persistent (default), independent_persistent or independent_nonpersistent'],
				'thin' => ['type' => 'boolean', 'description' => 'Thin-provision the new VMDK'],
				'instance' => ['type' => 'string', 'description' => 'vCenter instance name'],
			],
			'required' => ['vm', 'size_gib'],
		]
	)]
	public function add_disk(
		string $vm, int $size_gib, ?string $controller_type = null,
		?string $disk_mode = null, ?bool $thin = null, string $instance = ''
	): array {
		$inst = $this->inst($instance);
		$id = $this->vmId($inst, $vm);

		if ($controller_type === null && $disk_mode === null && $thin === null) {
			$result = $inst->rest()->post("vcenter/vm/{$id}/hardware/disk", [
				'type' => 'SCSI',
				'new_vmdk' => ['capacity' => $size_gib * 1073741824],
			]);
			return ['vm' => $id, 'disk' => $result];
		}

		if ($disk_mode !== null && !in_array($disk_mode, \VCenter\Inventory::DISK_MODES, true)) {
			throw new VCenterException(
				"Invalid disk_mode '{$disk_mode}' — expected one of: " . implode(', ', \VCenter\Inventory::DISK_MODES),
				0, 'InvalidArgument'
			);
		}

		$devices = $inst->soap()->vmHardwareDevices($id);
		$controllers = $inst->inventory()->vmStorageControllers($id);

		// Target controller: an existing one of the requested type, else
		// the first controller, else one to be created.
		$target = null;
		if ($controller_type !== null) {
			foreach ($controllers as $c) {
				if ($c['controller_type'] === $controller_type) {
					$target = $c;
					break;
				}
			}
		} elseif ($controllers !== []) {
			$target = $controllers[0];
		}

		$deviceChange = '';
		$controllerKey = null;
		$createType = null;
		if ($target !== null) {
			$controllerKey = $target['key'];
		} else {
			$createType = $controller_type ?? 'lsilogic-sas';
			$class = \VCenter\Inventory::SCSI_CONTROLLER_CLASSES[$createType] ?? null;
			if ($class === null) {
				throw new VCenterException(
					"Invalid controller_type '{$createType}' — expected one of: " . implode(', ', array_keys(\VCenter\Inventory::SCSI_CONTROLLER_CLASSES)),
					0, 'InvalidArgument'
				);
			}
			// Adding a controller cannot be hot-plugged.
			$this->requirePoweredOff($inst, $id, 'Adding a SCSI controller');
			$usedBuses = array_map(fn($c) => $c['bus_number'], $controllers);
			$bus = null;
			for ($b = 0; $b < 4; $b++) {
				if (!in_array($b, $usedBuses, true)) {
					$bus = $b;
					break;
				}
			}
			if ($bus === null) {
				throw new VCenterException('All 4 SCSI controller buses are in use', 0, 'NoPlacement');
			}
			$controllerKey = -100 - $bus;
			$deviceChange .= '<deviceChange><operation>add</operation>'
				. '<device xsi:type="' . $class . '">'
				. '<key>' . $controllerKey . '</key>'
				. '<busNumber>' . $bus . '</busNumber>'
				. '<sharedBus>noSharing</sharedBus>'
				. '</device></deviceChange>';
		}

		// Next free unit on the target controller (0-15, skipping 7).
		$usedUnits = [];
		foreach ($devices as $device) {
			if (($device['device'] ?? '') === 'VirtualDisk'
				&& (int) ($device['controllerKey'] ?? 0) === $controllerKey) {
				$usedUnits[] = (int) ($device['unitNumber'] ?? 0);
			}
		}
		$unit = null;
		for ($u = 0; $u < 16; $u++) {
			if ($u !== 7 && !in_array($u, $usedUnits, true)) {
				$unit = $u;
				break;
			}
		}
		if ($unit === null) {
			throw new VCenterException("No free unit on controller {$controllerKey}", 0, 'NoPlacement');
		}

		$backing = '<backing xsi:type="VirtualDiskFlatVer2BackingInfo">'
			. '<fileName></fileName>'
			. '<diskMode>' . ($disk_mode ?? 'persistent') . '</diskMode>'
			. ($thin !== null ? '<thinProvisioned>' . ($thin ? 'true' : 'false') . '</thinProvisioned>' : '')
			. '</backing>';
		// fileOperation=create tells vCenter to create the VMDK; without
		// it the add is treated as attaching an existing file.
		$deviceChange .= '<deviceChange><operation>add</operation>'
			. '<fileOperation>create</fileOperation>'
			. '<device xsi:type="VirtualDisk">'
			. '<key>-200</key>'
			. $backing
			. '<controllerKey>' . $controllerKey . '</controllerKey>'
			. '<unitNumber>' . $unit . '</unitNumber>'
			. '<capacityInKB>' . ($size_gib * 1048576) . '</capacityInKB>'
			. '</device></deviceChange>';

		$this->reconfigureStorage($inst, $id, $deviceChange);

		// Re-read and return the disk that landed on the target unit.
		foreach ($inst->inventory()->vmStorageDisks($id) as $disk) {
			if ($disk['unit_number'] === $unit
				&& ($target === null || $disk['controller_key'] === $target['key'])) {
				return ['vm' => $id, 'controller_created' => $target === null] + $disk;
			}
		}
		return ['vm' => $id, 'controller_created' => $target === null,
			'controller_key' => $target['key'] ?? null, 'unit_number' => $unit];
	}

	#[McpTool(
		name: 'set_disk',
		description: 'Change an existing disk\'s mode (persistent / independent_persistent / independent_nonpersistent) via ReconfigVM_Task (vim25). The VM must be powered off. Independent modes exclude the disk from snapshots. Provisioning cannot be changed this way — vCenter silently ignores thinProvisioned on backing edits; thin/thick is chosen when the VMDK is created.',
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'vm' => ['type' => 'string', 'description' => 'VM name or id'],
				'disk' => ['type' => 'string', 'description' => 'Disk device key from list_disks (e.g. "2000")'],
				'disk_mode' => ['type' => 'string', 'description' => 'persistent, independent_persistent or independent_nonpersistent'],
				'instance' => ['type' => 'string', 'description' => 'vCenter instance name'],
			],
			'required' => ['vm', 'disk', 'disk_mode'],
		]
	)]
	public function set_disk(
		string $vm, string $disk, string $disk_mode, string $instance = ''
	): array {
		$inst = $this->inst($instance);
		$id = $this->vmId($inst, $vm);

		if (!in_array($disk_mode, \VCenter\Inventory::DISK_MODES, true)) {
			throw new VCenterException(
				"Invalid disk_mode '{$disk_mode}' — expected one of: " . implode(', ', \VCenter\Inventory::DISK_MODES),
				0, 'InvalidArgument'
			);
		}

		$device = null;
		foreach ($inst->soap()->vmHardwareDevices($id) as $d) {
			if (($d['device'] ?? '') === 'VirtualDisk' && (string) ($d['key'] ?? '') === $disk) {
				$device = $d;
				break;
			}
		}
		if ($device === null) {
			throw new VCenterException("VM {$id} has no disk with key {$disk}", 0, 'NotFound');
		}
		$backing = is_array($device['backing'] ?? null) ? $device['backing'] : [];
		if (!isset($backing['fileName'])) {
			throw new VCenterException(
				"Disk {$disk} on VM {$id} is not a flat VMDK backing — set_disk only edits FlatVer2 backings",
				0, 'Unsupported'
			);
		}

		$this->requirePoweredOff($inst, $id, 'Changing a disk\'s mode');

		$deviceChange = '<deviceChange><operation>edit</operation>'
			. '<device xsi:type="VirtualDisk">'
			. '<key>' . (int) $device['key'] . '</key>'
			. '<backing xsi:type="VirtualDiskFlatVer2BackingInfo">'
			. '<fileName>' . \VCenter\SoapClient::esc((string) $backing['fileName']) . '</fileName>'
			. '<diskMode>' . $disk_mode . '</diskMode>'
			. '</backing>'
			. '<controllerKey>' . (int) ($device['controllerKey'] ?? 0) . '</controllerKey>'
			. '<unitNumber>' . (int) ($device['unitNumber'] ?? 0) . '</unitNumber>'
			. '<capacityInKB>' . (int) ($device['capacityInKB'] ?? 0) . '</capacityInKB>'
			. '</device></deviceChange>';

		$this->reconfigureStorage($inst, $id, $deviceChange);

		foreach ($inst->inventory()->vmStorageDisks($id) as $d) {
			if ($d['disk'] === $disk) {
				return ['vm' => $id, 'updated' => true] + $d;
			}
		}
		return ['vm' => $id, 'updated' => true, 'disk' => $disk];
	}

	/**
	 * Run a ReconfigVM_Task and map power-state rejections to the
	 * PowerStateError callers pattern-match on.
	 *
	 * @throws VCenterException
	 */
	private function reconfigureStorage(\VCenter\Instance $inst, string $vmId, string $deviceChange): void
	{
		try {
			$task = $inst->soap()->reconfigVm(['type' => 'VirtualMachine', 'id' => $vmId], $deviceChange);
			$inst->tasks()->wait($task);
		} catch (VCenterException $e) {
			if (in_array($e->getErrorType(), ['InvalidPowerState', 'InvalidState'], true)) {
				throw new VCenterException(
					"Storage reconfigure of VM {$vmId} rejected in its current power state: " . $e->getMessage(),
					0, 'PowerStateError', $e
				);
			}
			throw $e;
		}
	}

	/**
	 * Fail with a PowerStateError unless the VM is powered off.
	 *
	 * @throws VCenterException
	 */
	private function requirePoweredOff(\VCenter\Instance $inst, string $vmId, string $what): void
	{
		$power = $inst->rest()->get("vcenter/vm/{$vmId}/power");
		$state = is_array($power) ? (string) ($power['state'] ?? '') : '';
		if ($state !== 'POWERED_OFF') {
			throw new VCenterException(
				"{$what} requires the VM to be powered off (VM {$vmId} is {$state})",
				0, 'PowerStateError'
			);
		}
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
