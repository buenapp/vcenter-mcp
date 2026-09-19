<?php
/**
 * vCenter MCP Server — VM Tools
 *
 * @package    VCenterMCP\Tools
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

use EnchiladaMCP\McpTool;
use VCenter\InstanceManager;
use VCenter\VCenterException;

class VmTools
{
	private InstanceManager $manager;

	public function __construct(InstanceManager $manager)
	{
		$this->manager = $manager;
	}

	#[McpTool(
		name: 'list_vms',
		description: 'List virtual machines, optionally filtered by names, hosts, power states or folders.',
		readOnlyHint: true,
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'names' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Filter by VM names'],
				'hosts' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Filter by host MoRef ids'],
				'power_states' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'POWERED_ON, POWERED_OFF, SUSPENDED'],
				'folders' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Filter by folder MoRef ids'],
				'instance' => ['type' => 'string', 'description' => 'vCenter instance name'],
			],
		]
	)]
	public function list_vms(?array $names = null, ?array $hosts = null, ?array $power_states = null, ?array $folders = null, string $instance = ''): array
	{
		$filters = [];
		if ($names !== null) $filters['names'] = $names;
		if ($hosts !== null) $filters['hosts'] = $hosts;
		if ($power_states !== null) $filters['power_states'] = $power_states;
		if ($folders !== null) $filters['folders'] = $folders;
		return $this->manager->instance($instance)->inventory()->listVms($filters);
	}

	#[McpTool(
		name: 'get_vm',
		description: 'Full VM summary: hardware, disks, NICs (with MACs), CD-ROMs, boot config and power state.',
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
	public function get_vm(string $vm, string $instance = ''): array
	{
		$inst = $this->manager->instance($instance);
		$id = $inst->inventory()->resolveVm($vm)['vm'];
		$summary = $inst->rest()->get("vcenter/vm/{$id}") ?? [];
		// REST reports no controller type, disk mode or provisioning —
		// those come from vim25 (config.hardware.device).
		$summary['controllers'] = $inst->inventory()->vmStorageControllers($id);
		$summary['disks'] = $inst->inventory()->vmStorageDisks($id);
		return $summary;
	}

	#[McpTool(
		name: 'create_vm',
		description: 'Create a VM. Host exclusion is enforced; when host/datastore are omitted, a non-excluded host and the host-visible datastore with most free space are chosen automatically. With iso, boot order is CDROM then DISK. Accepts a hardware version (e.g. VMX_21) via version.',
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'name' => ['type' => 'string', 'description' => 'VM name'],
				'host' => ['type' => 'string', 'description' => 'Target host name or id (must not be excluded; auto-picked when omitted)'],
				'cluster' => ['type' => 'string', 'description' => 'Cluster name or id (scopes auto host pick)'],
				'datastore' => ['type' => 'string', 'description' => 'Datastore name or id (auto-picked when omitted)'],
				'network' => ['type' => 'string', 'description' => 'Network name or id for the NIC (default from instance config)'],
				'iso' => ['type' => 'string', 'description' => 'CD-ROM ISO path, e.g. "[CDImages] FreeBSD OS/x.iso"'],
				'folder' => ['type' => 'string', 'description' => 'VM folder name or id'],
				'resource_pool' => ['type' => 'string', 'description' => 'Resource pool name or id'],
				'datacenter' => ['type' => 'string', 'description' => 'Datacenter name or id'],
				'guest_os' => ['type' => 'string', 'description' => 'Guest OS id (default FREEBSD_14_64; FREEBSD_64 maps to the legacy pre-11 profile)'],
				'cpu' => ['type' => 'integer', 'description' => 'vCPU count (default 2)'],
				'memory_mib' => ['type' => 'integer', 'description' => 'Memory MiB (default 2048)'],
				'disk_gib' => ['type' => 'integer', 'description' => 'Disk size GiB (default 20)'],
				'firmware' => ['type' => 'string', 'description' => 'EFI or BIOS (default EFI)'],
				'nic_type' => ['type' => 'string', 'description' => 'NIC adapter type (default VMXNET3)'],
				'scsi_type' => ['type' => 'string', 'description' => 'SCSI adapter type for bus 0 (default PVSCSI); ignored when controllers is given'],
				'controllers' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'SCSI adapter types, one per bus in order (BUSLOGIC, LSILOGIC, LSILOGICSAS, PVSCSI) — e.g. ["LSILOGICSAS","PVSCSI"] for an LSI SAS boot adapter plus a paravirtual data adapter'],
				'disk_mode' => ['type' => 'string', 'description' => 'Boot disk mode: persistent (default), independent_persistent or independent_nonpersistent (applied via vim25 after create; provisioning follows the datastore default and cannot be changed by reconfig)'],
				'version' => ['type' => 'string', 'description' => 'VM hardware version (e.g. VMX_21); default: vCenter default'],
				'instance' => ['type' => 'string', 'description' => 'vCenter instance name'],
			],
			'required' => ['name'],
		]
	)]
	public function create_vm(
		string $name,
		?string $host = null,
		?string $cluster = null,
		?string $datastore = null,
		?string $network = null,
		?string $iso = null,
		?string $folder = null,
		?string $resource_pool = null,
		?string $datacenter = null,
		string $guest_os = 'FREEBSD_14_64',
		int $cpu = 2,
		int $memory_mib = 2048,
		int $disk_gib = 20,
		string $firmware = 'EFI',
		string $nic_type = 'VMXNET3',
		string $scsi_type = 'PVSCSI',
		?array $controllers = null,
		?string $disk_mode = null,
		?string $version = null,
		string $instance = ''
	): array {
		$inst = $this->manager->instance($instance);
		$inv = $inst->inventory();
		$defaults = $inst->defaults();

		if ($version !== null && preg_match('/^VMX_\d+$/i', $version) !== 1) {
			throw new VCenterException(
				"Invalid hardware version '{$version}' — expected VMX_<n> (e.g. VMX_21)",
				0, 'InvalidArgument'
			);
		}
		if ($disk_mode !== null && !in_array($disk_mode, \VCenter\Inventory::DISK_MODES, true)) {
			throw new VCenterException(
				"Invalid disk_mode '{$disk_mode}' — expected one of: " . implode(', ', \VCenter\Inventory::DISK_MODES),
				0, 'InvalidArgument'
			);
		}
		$adapterTypes = ['BUSLOGIC', 'LSILOGIC', 'LSILOGICSAS', 'PVSCSI'];
		if ($controllers !== null) {
			$controllers = array_map(fn($t) => strtoupper((string) $t), $controllers);
			if ($controllers === [] || count($controllers) > 4
				|| array_diff($controllers, $adapterTypes) !== []) {
				throw new VCenterException(
					'Invalid controllers — 1-4 adapter types from: ' . implode(', ', $adapterTypes),
					0, 'InvalidArgument'
				);
			}
		}

		$datacenter = $datacenter ?? ($defaults['datacenter'] ?? null);
		$cluster = $cluster ?? ($defaults['cluster'] ?? null);
		$network = $network ?? ($defaults['network'] ?? null);
		$datastore = $datastore ?? ($defaults['datastore'] ?? null);
		$folder = $folder ?? ($defaults['folder'] ?? null);

		// Host: explicit (exclusion enforced) or auto-picked
		$hostEntry = $host !== null
			? $inv->resolveHost($host)
			: $inv->pickHost($datacenter, $cluster);

		// Datastore: explicit or the host-visible one with most free space
		$dsEntry = $datastore !== null
			? $inv->resolveDatastore($datastore, $datacenter, $hostEntry['host'])
			: $inv->pickDatastore($hostEntry['host']);

		if ($network === null) {
			throw new VCenterException("No network given and no instance default 'network' configured", 0, 'MissingParam');
		}
		$netEntry = $inv->resolveNetwork($network, $datacenter);
		$netType = ($netEntry['type'] ?? 'STANDARD_PORTGROUP') === 'DISTRIBUTED_PORTGROUP'
			? 'DISTRIBUTED_PORTGROUP' : 'STANDARD_PORTGROUP';

		// Folder: explicit or first VIRTUAL_MACHINE folder in the datacenter
		if ($folder !== null) {
			$folderId = $inv->resolveFolder($folder, $datacenter)['folder'];
		} else {
			$folders = $inv->listFolders($datacenter, 'VIRTUAL_MACHINE');
			if (empty($folders)) {
				throw new VCenterException('No VIRTUAL_MACHINE folder found for VM placement', 0, 'NoPlacement');
			}
			$folderId = $folders[0]['folder'];
		}

		// Resource pool: explicit, else a pool on the placed host —
		// REST host filter first, then the host's compute-resource
		// resourcePool property via SOAP when the filter returns nothing.
		if ($resource_pool !== null) {
			$poolId = $inv->resolveResourcePool($resource_pool, $cluster, $hostEntry['host'])['resource_pool'];
		} else {
			$pools = $inv->listResourcePools($cluster, $hostEntry['host']);
			if (!empty($pools)) {
				$poolId = $pools[0]['resource_pool'];
			} else {
				$poolId = $this->hostResourcePool($inst, $hostEntry['host']);
			}
		}

		$body = [
			'name' => $name,
			'guest_OS' => $guest_os,
			'placement' => [
				'host' => $hostEntry['host'],
				'datastore' => $dsEntry['datastore'],
				'folder' => $folderId,
				'resource_pool' => $poolId,
			],
			'cpu' => ['count' => $cpu, 'cores_per_socket' => 1],
			'memory' => ['size_MiB' => $memory_mib],
			'scsi_adapters' => $controllers !== null
				? array_map(fn($t, $bus) => ['type' => $t, 'bus' => $bus], $controllers, array_keys($controllers))
				: [['type' => $scsi_type, 'bus' => 0]],
			'disks' => [['type' => 'SCSI', 'new_vmdk' => ['capacity' => $disk_gib * 1073741824]]],
			'nics' => [[
				'type' => $nic_type,
				'start_connected' => true,
				'backing' => ['type' => $netType, 'network' => $netEntry['network']],
			]],
			'boot' => ['type' => strtoupper($firmware)],
		];

		if ($version !== null) {
			$body['hardware_version'] = strtoupper($version);
		}

		if ($iso !== null) {
			$body['sata_adapters'] = [['type' => 'AHCI', 'bus' => 0]];
			$body['cdroms'] = [[
				'type' => 'SATA',
				'start_connected' => true,
				'backing' => ['type' => 'ISO_FILE', 'iso_file' => $iso],
			]];
			$body['boot_devices'] = [['type' => 'CDROM'], ['type' => 'DISK']];
		}

		$result = $inst->rest()->post('vcenter/vm', $body);
		$vmId = is_string($result) ? $result : ($result['vm'] ?? (string) $result);

		// Boot disk mode is not expressible in the REST create spec;
		// edit the backing over vim25 while the VM is off.
		if ($disk_mode !== null) {
			$this->setBootDiskBacking($inst, $vmId, $disk_mode);
		}

		return [
			'vm' => $vmId,
			'placement' => $body['placement'],
			'summary' => $inst->rest()->get("vcenter/vm/{$vmId}"),
		];
	}

	/**
	 * Edit the first disk's FlatVer2 backing mode on a freshly created,
	 * powered-off VM via ReconfigVM_Task.
	 *
	 * @throws VCenterException
	 */
	private function setBootDiskBacking(\VCenter\Instance $inst, string $vmId, string $diskMode): void
	{
		$device = null;
		foreach ($inst->soap()->vmHardwareDevices($vmId) as $d) {
			if (($d['device'] ?? '') === 'VirtualDisk') {
				$device = $d;
				break;
			}
		}
		if ($device === null) {
			throw new VCenterException("VM {$vmId} has no disk to set the mode on", 0, 'NotFound');
		}
		$deviceChange = '<deviceChange><operation>edit</operation>'
			. '<device xsi:type="VirtualDisk">'
			. '<key>' . (int) $device['key'] . '</key>'
			. '<backing xsi:type="VirtualDiskFlatVer2BackingInfo">'
			. '<fileName>' . \VCenter\SoapClient::esc((string) ($device['backing']['fileName'] ?? '')) . '</fileName>'
			. '<diskMode>' . $diskMode . '</diskMode>'
			. '</backing>'
			. '<controllerKey>' . (int) ($device['controllerKey'] ?? 0) . '</controllerKey>'
			. '<unitNumber>' . (int) ($device['unitNumber'] ?? 0) . '</unitNumber>'
			. '<capacityInKB>' . (int) ($device['capacityInKB'] ?? 0) . '</capacityInKB>'
			. '</device></deviceChange>';
		$task = $inst->soap()->reconfigVm(['type' => 'VirtualMachine', 'id' => $vmId], $deviceChange);
		$inst->tasks()->wait($task);
	}

	#[McpTool(
		name: 'delete_vm',
		description: 'Delete a VM. Refuses a powered-on VM unless force=true (powers off first).',
		destructiveHint: true,
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'vm' => ['type' => 'string', 'description' => 'VM name or id'],
				'force' => ['type' => 'boolean', 'description' => 'Power off first when the VM is on'],
				'instance' => ['type' => 'string', 'description' => 'vCenter instance name'],
			],
			'required' => ['vm'],
		]
	)]
	public function delete_vm(string $vm, bool $force = false, string $instance = ''): array
	{
		$inst = $this->manager->instance($instance);
		$id = $inst->inventory()->resolveVm($vm)['vm'];

		$power = $inst->rest()->get("vcenter/vm/{$id}/power");
		$state = is_array($power) ? ($power['state'] ?? null) : $power;
		if ($state === 'POWERED_ON' || $state === 'SUSPENDED') {
			if (!$force) {
				throw new VCenterException(
					"VM '{$vm}' is {$state}; pass force=true to power it off and delete",
					0, 'VmPoweredOn'
				);
			}
			$inst->rest()->post("vcenter/vm/{$id}/power", null, ['action' => 'stop']);
		}

		$inst->rest()->delete("vcenter/vm/{$id}");
		return ['deleted' => true, 'vm' => $id];
	}

	#[McpTool(
		name: 'set_vm_hardware',
		description: 'Change VM CPU count and/or memory size.',
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'vm' => ['type' => 'string', 'description' => 'VM name or id'],
				'cpu' => ['type' => 'integer', 'description' => 'New vCPU count'],
				'memory_mib' => ['type' => 'integer', 'description' => 'New memory size MiB'],
				'instance' => ['type' => 'string', 'description' => 'vCenter instance name'],
			],
			'required' => ['vm'],
		]
	)]
	public function set_vm_hardware(string $vm, ?int $cpu = null, ?int $memory_mib = null, string $instance = ''): array
	{
		$inst = $this->manager->instance($instance);
		$id = $inst->inventory()->resolveVm($vm)['vm'];

		$changed = [];
		if ($cpu !== null) {
			$inst->rest()->patch("vcenter/vm/{$id}/hardware/cpu", ['count' => $cpu]);
			$changed['cpu'] = $cpu;
		}
		if ($memory_mib !== null) {
			$inst->rest()->patch("vcenter/vm/{$id}/hardware/memory", ['size_MiB' => $memory_mib]);
			$changed['memory_mib'] = $memory_mib;
		}
		return ['vm' => $id, 'changed' => $changed];
	}

	/**
	 * Resource pool of a host's compute resource via SOAP:
	 * HostSystem.parent -> ComputeResource/ClusterComputeResource
	 * .resourcePool.
	 *
	 * @throws VCenterException When the chain yields no pool
	 */
	private function hostResourcePool(\VCenter\Instance $inst, string $hostId): string
	{
		$props = $inst->properties();
		$host = $props->get('HostSystem', $hostId, ['parent']);
		$parent = $host['parent'] ?? null;
		if (!is_array($parent) || empty($parent['id'])) {
			throw new VCenterException("Host {$hostId} has no parent compute resource for resource-pool pick", 0, 'NoPlacement');
		}
		$cr = $props->get((string) $parent['type'], (string) $parent['id'], ['resourcePool']);
		$pool = $cr['resourcePool'] ?? null;
		if (!is_array($pool) || empty($pool['id'])) {
			throw new VCenterException("No resource pool found on compute resource {$parent['id']}", 0, 'NoPlacement');
		}
		return (string) $pool['id'];
	}
}
