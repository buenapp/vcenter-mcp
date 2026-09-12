<?php
/**
 * vCenter MCP Server — Inventory Resolution
 *
 * Name <-> MoRef resolution over the REST list endpoints (names=
 * filters), host exclusion enforcement, and automatic placement
 * (non-excluded CONNECTED/POWERED_ON host, host-visible datastore with
 * the most free space).
 *
 * @package    VCenterMCP\VCenter
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

namespace VCenter;

class Inventory
{
	private Instance $instance;

	public function __construct(Instance $instance)
	{
		$this->instance = $instance;
	}

	/** True when the value already looks like a MoRef id (e.g. "host-12"). */
	public static function looksLikeMoRef(string $value): bool
	{
		return preg_match('/^[a-z]+[a-z0-9]*(-[a-z]+)?-\d+$/i', $value) === 1
			|| preg_match('/^domain-c\d+$/', $value) === 1;
	}

	// ── List endpoints (raw passthroughs) ─────────────────────────────

	public function listDatacenters(): array
	{
		return $this->instance->rest()->get('vcenter/datacenter') ?? [];
	}

	public function listClusters(?string $datacenter = null): array
	{
		$query = [];
		if ($datacenter !== null) {
			$query['datacenters'] = $this->resolveDatacenter($datacenter)['datacenter'];
		}
		return $this->instance->rest()->get('vcenter/cluster', $query) ?? [];
	}

	public function listHosts(?string $datacenter = null, ?string $cluster = null): array
	{
		$query = [];
		if ($datacenter !== null) {
			$query['datacenters'] = $this->resolveDatacenter($datacenter)['datacenter'];
		}
		if ($cluster !== null) {
			$query['clusters'] = $this->resolveCluster($cluster)['cluster'];
		}
		$hosts = $this->instance->rest()->get('vcenter/host', $query) ?? [];
		$excluded = $this->instance->excludeHosts();
		foreach ($hosts as &$host) {
			$host['excluded'] = in_array(strtolower((string) ($host['name'] ?? '')), $excluded, true);
		}
		return $hosts;
	}

	public function listDatastores(?string $datacenter = null, ?string $host = null, ?string $type = null): array
	{
		$query = [];
		if ($datacenter !== null) {
			$query['datacenters'] = $this->resolveDatacenter($datacenter)['datacenter'];
		}
		if ($host !== null) {
			$query['hosts'] = $this->resolveHost($host)['host'];
		}
		if ($type !== null) {
			$query['types'] = $type;
		}
		return $this->instance->rest()->get('vcenter/datastore', $query) ?? [];
	}

	public function getDatastore(string $id): array
	{
		// The detail endpoint omits the id (like the hardware endpoints);
		// merge it back in so callers get a complete entry.
		$detail = $this->instance->rest()->get('vcenter/datastore/' . rawurlencode($id));
		return is_array($detail) ? ($detail + ['datastore' => $id]) : ['datastore' => $id];
	}

	public function listNetworks(?string $datacenter = null, ?string $type = null): array
	{
		$query = [];
		if ($datacenter !== null) {
			$query['datacenters'] = $this->resolveDatacenter($datacenter)['datacenter'];
		}
		if ($type !== null) {
			$query['types'] = $type;
		}
		return $this->instance->rest()->get('vcenter/network', $query) ?? [];
	}

	public function listFolders(?string $datacenter = null, string $type = 'VIRTUAL_MACHINE'): array
	{
		$query = ['type' => $type];
		if ($datacenter !== null) {
			$query['datacenters'] = $this->resolveDatacenter($datacenter)['datacenter'];
		}
		return $this->instance->rest()->get('vcenter/folder', $query) ?? [];
	}

	public function listResourcePools(?string $cluster = null, ?string $host = null): array
	{
		$query = [];
		if ($cluster !== null) {
			$query['clusters'] = $this->resolveCluster($cluster)['cluster'];
		}
		if ($host !== null) {
			$query['hosts'] = $this->resolveHost($host)['host'];
		}
		return $this->instance->rest()->get('vcenter/resource-pool', $query) ?? [];
	}

	public function listVms(array $filters = []): array
	{
		return $this->instance->rest()->get('vcenter/vm', $filters) ?? [];
	}

	// ── Name -> id resolution ─────────────────────────────────────────

	public function resolveDatacenter(string $nameOrId): array
	{
		return $this->resolveOne('vcenter/datacenter', 'datacenter', [], $nameOrId, 'datacenter');
	}

	public function resolveCluster(string $nameOrId, ?string $datacenter = null): array
	{
		$query = [];
		if ($datacenter !== null) {
			$query['datacenters'] = $this->resolveDatacenter($datacenter)['datacenter'];
		}
		return $this->resolveOne('vcenter/cluster', 'cluster', $query, $nameOrId, 'cluster');
	}

	/**
	 * Resolve a host and enforce the exclusion list.
	 *
	 * @throws VCenterException When the resolved host is excluded
	 */
	public function resolveHost(string $nameOrId, bool $enforceExclusion = true): array
	{
		if (self::looksLikeMoRef($nameOrId)) {
			// Fetch the record anyway so exclusion matches the host name
			$rows = $this->instance->rest()->get('vcenter/host', ['hosts' => $nameOrId]) ?? [];
			$entry = $this->single($rows, $nameOrId, 'host');
			if ($enforceExclusion) {
				$this->assertNotExcluded($entry);
			}
			return $entry;
		}
		$entry = $this->resolveOne('vcenter/host', 'host', [], $nameOrId, 'host');
		if ($enforceExclusion) {
			$this->assertNotExcluded($entry);
		}
		return $entry;
	}

	public function resolveDatastore(string $nameOrId, ?string $datacenter = null, ?string $host = null): array
	{
		$query = [];
		if ($datacenter !== null) {
			$query['datacenters'] = $this->resolveDatacenter($datacenter)['datacenter'];
		}
		if ($host !== null) {
			$query['hosts'] = $this->resolveHost($host)['host'];
		}
		return $this->resolveOne('vcenter/datastore', 'datastore', $query, $nameOrId, 'datastore');
	}

	public function resolveNetwork(string $nameOrId, ?string $datacenter = null): array
	{
		$query = [];
		if ($datacenter !== null) {
			$query['datacenters'] = $this->resolveDatacenter($datacenter)['datacenter'];
		}
		return $this->resolveOne('vcenter/network', 'network', $query, $nameOrId, 'network');
	}

	public function resolveFolder(string $nameOrId, ?string $datacenter = null, string $type = 'VIRTUAL_MACHINE'): array
	{
		return $this->resolveOne('vcenter/folder', 'folder', ['type' => $type] + ($datacenter !== null ? ['datacenters' => $this->resolveDatacenter($datacenter)['datacenter']] : []), $nameOrId, 'folder');
	}

	public function resolveResourcePool(string $nameOrId, ?string $cluster = null, ?string $host = null): array
	{
		$query = [];
		if ($cluster !== null) {
			$query['clusters'] = $this->resolveCluster($cluster)['cluster'];
		}
		if ($host !== null) {
			$query['hosts'] = $this->resolveHost($host)['host'];
		}
		return $this->resolveOne('vcenter/resource-pool', 'resource_pool', $query, $nameOrId, 'resource pool');
	}

	public function resolveVm(string $nameOrId): array
	{
		return $this->resolveOne('vcenter/vm', 'vm', [], $nameOrId, 'VM');
	}

	// ── Automatic placement ───────────────────────────────────────────

	/**
	 * Pick a placement host: CONNECTED + POWERED_ON and not excluded.
	 *
	 * @throws VCenterException When no eligible host exists
	 */
	public function pickHost(?string $datacenter = null, ?string $cluster = null): array
	{
		$hosts = $this->listHosts($datacenter, $cluster);
		$eligible = array_values(array_filter($hosts, fn($h) =>
			empty($h['excluded'])
			&& ($h['connection_state'] ?? '') === 'CONNECTED'
			&& ($h['power_state'] ?? '') === 'POWERED_ON'));
		if (empty($eligible)) {
			throw new VCenterException(
				'No eligible host for VM placement (all hosts are excluded or not CONNECTED/POWERED_ON)',
				0, 'NoPlacement'
			);
		}
		return $eligible[0];
	}

	/**
	 * Pick the host-visible datastore with the most free space. The
	 * host's datastore list comes from the HostSystem 'datastore'
	 * property (SOAP); free space from REST datastore/{id}.
	 *
	 * @throws VCenterException When the host sees no datastore
	 */
	public function pickDatastore(string $hostId): array
	{
		$props = $this->instance->properties()->get('HostSystem', $hostId, ['datastore']);
		$refs = $props['datastore'] ?? [];
		if (isset($refs['id'])) {
			$refs = [$refs];
		}
		if (empty($refs)) {
			throw new VCenterException("Host {$hostId} reports no datastores", 0, 'NoPlacement');
		}

		$best = null;
		foreach ($refs as $ref) {
			$id = is_array($ref) ? ($ref['id'] ?? null) : null;
			if ($id === null) {
				continue;
			}
			try {
				$ds = $this->getDatastore($id);
			} catch (VCenterException $e) {
				continue;
			}
			if ($best === null || ($ds['free_space'] ?? 0) > ($best['free_space'] ?? 0)) {
				$best = $ds;
			}
		}
		if ($best === null) {
			throw new VCenterException("No usable datastore found on host {$hostId}", 0, 'NoPlacement');
		}
		return $best;
	}

	/**
	 * Full device list for a VM: the collection endpoint returns only
	 * ids ({nic, cdrom, disk}), so each entry is fetched individually.
	 *
	 * @param string $vmId VM MoRef id
	 * @param string $kind hardware sub-collection (ethernet, cdrom, disk)
	 * @return array<int,array> device detail objects
	 * @throws VCenterException
	 */
	public function vmDevices(string $vmId, string $kind): array
	{
		$idKey = ['ethernet' => 'nic', 'cdrom' => 'cdrom', 'disk' => 'disk'][$kind] ?? $kind;
		$devices = [];
		foreach ((array) ($this->instance->rest()->get("vcenter/vm/{$vmId}/hardware/{$kind}") ?? []) as $entry) {
			$devId = is_array($entry) ? ($entry[$idKey] ?? null) : $entry;
			if ($devId === null) {
				continue;
			}
			$detail = $this->instance->rest()->get("vcenter/vm/{$vmId}/hardware/{$kind}/{$devId}");
			$devices[] = is_array($detail) ? ($detail + [$idKey => $devId]) : [$idKey => $devId];
		}
		return $devices;
	}

	/**
	 * Read a datastore file (e.g. a .vmx) via the /folder HTTP download
	 * with the SOAP session cookie — the same transport Screenshot uses.
	 * Refuses files over 8 MiB; returns raw bytes, callers pick an
	 * encoding.
	 *
	 * @return array{path:string,size:int,content:string}
	 * @throws VCenterException
	 */
	public function readDatastoreFile(string $datastore, string $path, ?string $datacenter = null): array
	{
		$ds = $this->resolveDatastore($datastore, $datacenter);
		$dc = $this->instance->properties()->datacenterOf(['type' => 'Datastore', 'id' => $ds['datastore']]);
		$datastorePath = "[{$ds['name']}] " . ltrim($path, '/');
		$content = $this->instance->soap()->downloadDatastoreFile($datastorePath, $dc['name'], (string) $ds['name']);
		$size = strlen($content);
		if ($size > 8388608) {
			throw new VCenterException(
				"Datastore file {$datastorePath} is {$size} bytes — read_datastore_file is meant for text (max 8 MiB)",
				0, 'TooLarge'
			);
		}
		return ['path' => $datastorePath, 'size' => $size, 'content' => $content];
	}

	/**
	 * Browse a datastore via SearchDatastore_Task on its
	 * HostDatastoreBrowser; returns [ {path, size?, folder} ].
	 *
	 * @throws VCenterException
	 */
	public function browseDatastore(string $datastore, string $path = '', string $pattern = '*', ?string $datacenter = null): array
	{
		$ds = $this->resolveDatastore($datastore, $datacenter);
		$dsId = $ds['datastore'];

		$props = $this->instance->properties()->get('Datastore', $dsId, ['name', 'browser']);
		// $ds['name'] is the id itself when a bare MoRef was passed
		$dsName = (string) ($props['name'] ?? $ds['name']);
		$browser = $props['browser'] ?? null;
		if (!is_array($browser) || empty($browser['id'])) {
			throw new VCenterException("Datastore {$dsId} reports no HostDatastoreBrowser", 0, 'Browse');
		}

		$datastorePath = "[{$dsName}]" . ($path !== '' ? ' ' . trim($path, '/') : '');
		$task = $this->instance->soap()->searchDatastore($browser, $datastorePath, $pattern);
		$result = $this->instance->tasks()->wait($task);

		$files = [];
		$results = $result['result'] ?? [];
		// result is one HostDatastoreBrowserSearchResults per matching
		// folder; a single folder decodes as the object itself, not a list
		if (is_array($results) && isset($results['folderPath'])) {
			$results = [$results];
		}
		foreach ((array) ($results ?? []) as $folder) {
			if (!is_array($folder)) {
				continue;
			}
			$folderPath = (string) ($folder['folderPath'] ?? $datastorePath);
			$fileList = $folder['file'] ?? [];
			if (is_array($fileList) && isset($fileList['path'])) {
				$fileList = [$fileList];
			}
			foreach ((array) $fileList as $file) {
				if (!is_array($file)) {
					continue;
				}
				$files[] = [
					'path' => rtrim($folderPath, '/') . '/' . ($file['path'] ?? ''),
					'size' => $file['fileSize'] ?? null,
				];
			}
		}
		return $files;
	}

	// ── Internals ─────────────────────────────────────────────────────

	/**
	 * Resolve name-or-id to a list entry. MoRef-looking values pass
	 * through as a minimal entry; names go through the names= filter and
	 * ambiguity (>1 match) is an error naming the candidates.
	 *
	 * @throws VCenterException
	 */
	private function resolveOne(string $endpoint, string $idField, array $query, string $nameOrId, string $kind): array
	{
		if (self::looksLikeMoRef($nameOrId)) {
			return [$idField => $nameOrId, 'name' => $nameOrId];
		}
		$rows = $this->instance->rest()->get($endpoint, $query + ['names' => $nameOrId]) ?? [];
		return $this->single($rows, $nameOrId, $kind);
	}

	/**
	 * @throws VCenterException On zero or ambiguous matches
	 */
	private function single(array $rows, string $nameOrId, string $kind): array
	{
		if (count($rows) === 0) {
			throw new VCenterException("No {$kind} found matching '{$nameOrId}'", 0, 'NotFound');
		}
		if (count($rows) > 1) {
			$candidates = implode(', ', array_map(function ($r) {
				foreach ($r as $k => $v) {
					if (is_string($v) && self::looksLikeMoRef($v)) {
						return ($r['name'] ?? '?') . " ({$v})";
					}
				}
				return ($r['name'] ?? '?');
			}, $rows));
			throw new VCenterException(
				"Ambiguous {$kind} '{$nameOrId}' — matches: {$candidates}",
				0, 'Ambiguous'
			);
		}
		return $rows[0];
	}

	/** @throws VCenterException When the host is excluded from placement */
	private function assertNotExcluded(array $host): void
	{
		$name = strtolower((string) ($host['name'] ?? ''));
		if ($name !== '' && in_array($name, $this->instance->excludeHosts(), true)) {
			throw new VCenterException(
				"Host '{$host['name']}' is excluded from VM placement by instance configuration",
				0, 'HostExcluded'
			);
		}
	}
}
