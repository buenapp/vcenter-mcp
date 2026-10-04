<?php
/**
 * vCenter MCP Server -- PCI Passthrough Tools
 *
 * DirectPath I/O (dynamic PCI passthrough) management: host PCI
 * inventory, per-VM passthrough device listing, attach and detach.
 * vim25 only; the REST model does not expose passthrough devices, so
 * everything here rides the SOAP property collector and
 * ReconfigVM_Task.
 *
 * @package    VCenterMCP\Tools
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

use EnchiladaMCP\McpTool;
use VCenter\InstanceManager;
use VCenter\VCenterException;

class PassthroughTools
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
		name: 'list_host_pci',
		description: 'List PCI devices on an ESXi host with their passthrough state. By default only passthrough-enabled devices; pass capable_only=false for the full PCI table.',
		readOnlyHint: true,
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'host' => ['type' => 'string', 'description' => 'Host name or id'],
				'capable_only' => ['type' => 'boolean', 'description' => 'Only passthrough-enabled devices (default true)'],
				'instance' => ['type' => 'string', 'description' => 'vCenter instance name; default instance if omitted (see list_vcenter_instances)'],
			],
			'required' => ['host'],
		]
	)]
	public function list_host_pci(string $host, bool $capable_only = true, string $instance = ''): array
	{
		$inst = $this->inst($instance);
		// Host exclusions guard VM placement, not read-only hardware
		// inventory (GPU hosts are commonly excluded from placement).
		$hostId = $inst->inventory()->resolveHost($host, false)['host'];

		$devices = $this->hostPciDevices($inst, $hostId);
		if ($capable_only) {
			$devices = array_values(array_filter($devices, fn($d) => $d['passthru_enabled']));
		}
		return ['host' => $hostId, 'devices' => $devices];
	}

	#[McpTool(
		name: 'list_passthrough_devices',
		description: 'List a VM\'s DirectPath/passthrough PCI devices with their backing PCI id.',
		readOnlyHint: true,
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'vm' => ['type' => 'string', 'description' => 'VM name or id'],
				'instance' => ['type' => 'string', 'description' => 'vCenter instance name; default instance if omitted (see list_vcenter_instances)'],
			],
			'required' => ['vm'],
		]
	)]
	public function list_passthrough_devices(string $vm, string $instance = ''): array
	{
		$inst = $this->inst($instance);
		$id = $this->vmId($inst, $vm);
		return ['vm' => $id, 'devices' => $this->vmPassthroughDevices($inst, $id)];
	}

	#[McpTool(
		name: 'attach_pci',
		description: 'Attach a host PCI device to a VM via Dynamic DirectPath I/O. Requires the VM powered off (no hot-add); locks all guest memory (memoryReservationLockedToMax) as DirectPath requires. Refuses a device already attached to another VM.',
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'vm' => ['type' => 'string', 'description' => 'VM name or id'],
				'device' => ['type' => 'string', 'description' => 'PCI id from list_host_pci (e.g. "0000:63:00.0"), or a unique substring of the device name'],
				'host' => ['type' => 'string', 'description' => 'Host owning the device (default: the host the VM currently runs on)'],
				'label' => ['type' => 'string', 'description' => 'customLabel for the virtual device (default: the host device name)'],
				'instance' => ['type' => 'string', 'description' => 'vCenter instance name; default instance if omitted (see list_vcenter_instances)'],
			],
			'required' => ['vm', 'device'],
		]
	)]
	public function attach_pci(
		string $vm, string $device, ?string $host = null,
		?string $label = null, string $instance = ''
	): array {
		$inst = $this->inst($instance);
		$id = $this->vmId($inst, $vm);

		$this->requirePoweredOff($inst, $id, 'Attaching a PCI passthrough device');

		// Default the device host to wherever the VM lives.
		if ($host === null) {
			$props = $inst->properties()->get('VirtualMachine', $id, ['runtime.host']);
			$hostRef = $props['runtime.host'] ?? null;
			if (!is_array($hostRef) || empty($hostRef['id'])) {
				throw new VCenterException(
					"Could not determine the runtime host of VM {$id}; pass host explicitly",
					0, 'NoPlacement'
				);
			}
			$hostId = (string) $hostRef['id'];
		} else {
			$hostId = $inst->inventory()->resolveHost($host, false)['host'];
		}

		$pci = $this->findHostPciDevice($inst, $hostId, $device);
		$this->assertDeviceFree($inst, $hostId, $pci['id'], $id);

		$customLabel = $label ?? (string) ($pci['name'] ?? $pci['id']);
		// hostPciDevices() hex-formats device_id; the backing wants int
		$deviceId = (int) hexdec($pci['device_id']);
		$deviceChange = '<deviceChange><operation>add</operation>'
			. '<device xsi:type="VirtualPCIPassthrough">'
			. '<key>-100</key>'
			. '<backing xsi:type="VirtualPCIPassthroughDynamicBackingInfo">'
			. '<id>' . \VCenter\SoapClient::esc($pci['id']) . '</id>'
			. '<deviceId>' . $deviceId . '</deviceId>'
			. '<customLabel>' . \VCenter\SoapClient::esc($customLabel) . '</customLabel>'
			. '</backing>'
			. '</device></deviceChange>';
		// DirectPath I/O cannot support memory overcommit on the guest.
		$suffix = '<memoryReservationLockedToMax>true</memoryReservationLockedToMax>';

		$this->reconfigure($inst, $id, $deviceChange, $suffix);

		foreach ($this->vmPassthroughDevices($inst, $id) as $attached) {
			if ($attached['pci_id'] === $pci['id']) {
				return ['vm' => $id, 'host' => $hostId, 'attached' => true] + $attached;
			}
		}
		return ['vm' => $id, 'host' => $hostId, 'attached' => true, 'pci_id' => $pci['id']];
	}

	#[McpTool(
		name: 'detach_pci',
		description: 'Remove a passthrough PCI device from a VM. Identified by device key, backing PCI id, or a unique substring of its label. Requires the VM powered off (no hot-remove).',
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'vm' => ['type' => 'string', 'description' => 'VM name or id'],
				'device' => ['type' => 'string', 'description' => 'Device key, PCI id or label substring from list_passthrough_devices'],
				'instance' => ['type' => 'string', 'description' => 'vCenter instance name; default instance if omitted (see list_vcenter_instances)'],
			],
			'required' => ['vm', 'device'],
		]
	)]
	public function detach_pci(string $vm, string $device, string $instance = ''): array
	{
		$inst = $this->inst($instance);
		$id = $this->vmId($inst, $vm);

		$this->requirePoweredOff($inst, $id, 'Detaching a PCI passthrough device');

		$devices = $this->vmPassthroughDevices($inst, $id);
		$match = [];
		foreach ($devices as $d) {
			if ($device === $d['key'] || strcasecmp($device, (string) $d['pci_id']) === 0) {
				$match = [$d];
				break;
			}
			if (stripos((string) $d['label'], $device) !== false || stripos((string) $d['custom_label'], $device) !== false) {
				$match[] = $d;
			}
		}
		if (count($match) > 1) {
			throw new VCenterException(
				"Ambiguous passthrough device '{$device}' on VM {$id} -- matches: "
					. implode(', ', array_map(fn($d) => "{$d['label']} ({$d['key']})", $match)),
				0, 'Ambiguous'
			);
		}
		if ($match === []) {
			throw new VCenterException(
				"VM {$id} has no passthrough device matching '{$device}'",
				0, 'NotFound'
			);
		}
		$target = $match[0];

		$deviceChange = '<deviceChange><operation>remove</operation>'
			. '<device xsi:type="VirtualPCIPassthrough">'
			. '<key>' . (int) $target['key'] . '</key>'
			. '</device></deviceChange>';
		$this->reconfigure($inst, $id, $deviceChange);

		foreach ($this->vmPassthroughDevices($inst, $id) as $left) {
			if ($left['pci_id'] === $target['pci_id']) {
				throw new VCenterException(
					"Device {$target['pci_id']} still present on VM {$id} after detach",
					0, 'TaskError'
				);
			}
		}
		return ['vm' => $id, 'detached' => true, 'pci_id' => $target['pci_id'], 'key' => $target['key']];
	}

	// ── Internals ────────────────────────────────────────────────────

	/**
	 * HostSystem config.pciPassthrough + config.pciDevice joined on the
	 * PCI id, shaped for tool output.
	 *
	 * @return array<int,array{id:string,name:string,class:string,vendor_id:string,device_id:string,driver:string,vmk_name:string,passthru_capable:bool,passthru_enabled:bool,passthru_active:bool,dependent:bool}>
	 */
	private function hostPciDevices(\VCenter\Instance $inst, string $hostId): array
	{
		// Passthrough state lives on config.pciPassthruInfo; the PCI
		// table (names, ids) on hardware.pciDevice.
		$props = $inst->properties()->get('HostSystem', $hostId,
			['name', 'config.pciPassthruInfo', 'hardware.pciDevice']);

		$passthru = $this->listify($props['config.pciPassthruInfo'] ?? []);
		$stateById = [];
		foreach ($passthru as $p) {
			if (!is_array($p)) {
				continue;
			}
			$stateById[(string) ($p['id'] ?? '')] = $p;
		}

		$devices = [];
		foreach ($this->listify($props['hardware.pciDevice'] ?? []) as $d) {
			if (!is_array($d)) {
				continue;
			}
			$state = $stateById[(string) ($d['id'] ?? '')] ?? [];
			$devices[] = [
				'id' => (string) ($d['id'] ?? ''),
				'name' => (string) ($d['deviceName'] ?? ''),
				'class' => sprintf('%04x', (int) ($d['classId'] ?? 0)),
				'vendor_id' => sprintf('%04x', (int) ($d['vendorId'] ?? 0)),
				'device_id' => sprintf('%04x', (int) ($d['deviceId'] ?? 0)),
				'driver' => (string) ($d['configuredDriver'] ?? $d['vmkName'] ?? ''),
				'passthru_capable' => $this->truthy($state['passthruCapable'] ?? false),
				'passthru_enabled' => $this->truthy($state['passthruEnabled'] ?? false),
				'passthru_active' => $this->truthy($state['passthruActive'] ?? false),
				'dependent' => $this->truthy($state['dependentDevice'] ?? false),
			];
		}
		return $devices;
	}

	/**
	 * The VM's VirtualPCIPassthrough devices, shaped for tool output.
	 *
	 * @return array<int,array{key:string,label:string,custom_label:string,pci_id:string,device_id:string,connected:bool}>
	 */
	private function vmPassthroughDevices(\VCenter\Instance $inst, string $vmId): array
	{
		$devices = [];
		foreach ($inst->soap()->vmHardwareDevices($vmId) as $device) {
			if (($device['device'] ?? '') !== 'VirtualPCIPassthrough') {
				continue;
			}
			$backing = is_array($device['backing'] ?? null) ? $device['backing'] : [];
			$allowed = is_array($backing['allowedDevice'] ?? null) ? $backing['allowedDevice'] : [];
			$vendorId = (int) ($allowed['vendorId'] ?? 0);
			$deviceId = $allowed['deviceId'] ?? $backing['deviceId'] ?? 0;
			$devices[] = [
				'key' => (string) ($device['key'] ?? ''),
				'label' => (string) ($device['deviceInfo']['label'] ?? ''),
				'summary' => (string) ($device['deviceInfo']['summary'] ?? ''),
				'custom_label' => (string) ($backing['customLabel'] ?? ''),
				// Dynamic DirectPath reports the slot as assignedId
				'pci_id' => (string) ($backing['assignedId'] ?? $backing['id'] ?? ''),
				'vendor_id' => $vendorId !== 0 ? sprintf('%04x', $vendorId) : '',
				'device_id' => (int) $deviceId !== 0 ? sprintf('%04x', (int) $deviceId) : '',
				'connected' => $this->truthy($device['connectable']['connected'] ?? false),
			];
		}
		return $devices;
	}

	/**
	 * Resolve the selector to exactly one entry of the host PCI table:
	 * exact PCI id, else a unique case-insensitive device-name substring.
	 *
	 * @throws VCenterException On zero matches, ambiguous names, or a
	 *                          device the host cannot pass through
	 */
	private function findHostPciDevice(\VCenter\Instance $inst, string $hostId, string $selector): array
	{
		$devices = $this->hostPciDevices($inst, $hostId);
		foreach ($devices as $d) {
			if (strcasecmp($selector, $d['id']) === 0) {
				$this->assertPassthruReady($d, $hostId);
				return $d;
			}
		}
		$matches = array_values(array_filter($devices,
			fn($d) => stripos($d['name'], $selector) !== false));
		if (count($matches) > 1) {
			throw new VCenterException(
				"Ambiguous PCI device '{$selector}' on host {$hostId} -- matches: "
					. implode(', ', array_map(fn($d) => "{$d['name']} ({$d['id']})", $matches)),
				0, 'Ambiguous'
			);
		}
		if ($matches === []) {
			throw new VCenterException(
				"Host {$hostId} has no PCI device matching '{$selector}'; see list_host_pci",
				0, 'NotFound'
			);
		}
		$this->assertPassthruReady($matches[0], $hostId);
		return $matches[0];
	}

	/** @throws VCenterException When the device cannot be passed through */
	private function assertPassthruReady(array $pci, string $hostId): void
	{
		if (!$pci['passthru_capable']) {
			throw new VCenterException(
				"PCI device {$pci['id']} ({$pci['name']}) on host {$hostId} is not passthrough capable",
				0, 'Unsupported'
			);
		}
		if (!$pci['passthru_enabled']) {
			throw new VCenterException(
				"PCI device {$pci['id']} ({$pci['name']}) on host {$hostId} is not enabled for passthrough. "
					. 'Enable DirectPath I/O for it in the host PCI device settings, then reboot the host.',
				0, 'Unsupported'
			);
		}
	}

	/**
	 * Refuse the attach when any other VM on the host already owns the
	 * device. vCenter also rejects it, but with a generic reconfigure
	 * failure; name the conflicting VM instead.
	 *
	 * @throws VCenterException DeviceInUse
	 */
	private function assertDeviceFree(\VCenter\Instance $inst, string $hostId, string $pciId, string $vmId): void
	{
		foreach ($inst->inventory()->listVms(['hosts' => [$hostId]]) as $entry) {
			$otherId = (string) ($entry['vm'] ?? '');
			if ($otherId === '' || $otherId === $vmId) {
				continue;
			}
			foreach ($this->vmPassthroughDevices($inst, $otherId) as $d) {
				if (strcasecmp($d['pci_id'], $pciId) === 0) {
					$name = (string) ($entry['name'] ?? $otherId);
					throw new VCenterException(
						"PCI device {$pciId} is already attached to VM '{$name}' ({$otherId}); "
							. 'detach_pci it there first',
						0, 'DeviceInUse'
					);
				}
			}
		}
	}

	/**
	 * Run a ReconfigVM_Task and map power-state rejections to the
	 * PowerStateError callers pattern-match on.
	 *
	 * @throws VCenterException
	 */
	private function reconfigure(
		\VCenter\Instance $inst, string $vmId, string $deviceChange, string $specSuffix = ''
	): void {
		try {
			$task = $inst->soap()->reconfigVm(['type' => 'VirtualMachine', 'id' => $vmId], $deviceChange, $specSuffix);
			$inst->tasks()->wait($task);
		} catch (VCenterException $e) {
			if (in_array($e->getErrorType(), ['InvalidPowerState', 'InvalidState'], true)) {
				throw new VCenterException(
					"Passthrough reconfigure of VM {$vmId} rejected in its current power state: " . $e->getMessage(),
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
				"{$what} requires the VM powered off (VM {$vmId} is {$state}); "
					. 'passthrough devices cannot be hot-plugged. Power off with vm_power, or attach on next power-off.',
				0, 'PowerStateError'
			);
		}
	}

	/** Normalize a property value that vCenter sends unwrapped when single. */
	private function listify(mixed $value): array
	{
		if (!is_array($value) || $value === []) {
			return [];
		}
		return array_is_list($value) ? $value : [$value];
	}

	private function truthy(mixed $v): bool
	{
		return $v === true || $v === 1 || $v === 'true' || $v === '1';
	}
}
