<?php
/**
 * vCenter MCP Server — Inventory Tools
 *
 * @package    VCenterMCP\Tools
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

use EnchiladaMCP\McpTool;
use VCenter\InstanceManager;

class InventoryTools
{
	private InstanceManager $manager;

	public function __construct(InstanceManager $manager)
	{
		$this->manager = $manager;
	}

	private function prop(string $desc, string $type = 'string'): array
	{
		return ['type' => $type, 'description' => $desc];
	}

	#[McpTool(
		name: 'list_datacenters',
		description: 'List vCenter datacenters.',
		readOnlyHint: true,
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'instance' => ['type' => 'string', 'description' => 'vCenter instance name (default instance when omitted)'],
			],
		]
	)]
	public function list_datacenters(string $instance = ''): array
	{
		return $this->manager->instance($instance)->inventory()->listDatacenters();
	}

	#[McpTool(
		name: 'list_clusters',
		description: 'List clusters, optionally scoped to a datacenter (name or id).',
		readOnlyHint: true,
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'datacenter' => ['type' => 'string', 'description' => 'Datacenter name or id'],
				'instance' => ['type' => 'string', 'description' => 'vCenter instance name'],
			],
		]
	)]
	public function list_clusters(?string $datacenter = null, string $instance = ''): array
	{
		return $this->manager->instance($instance)->inventory()->listClusters($datacenter);
	}

	#[McpTool(
		name: 'list_hosts',
		description: 'List ESXi hosts with connection/power state; hosts excluded from VM placement are marked excluded:true.',
		readOnlyHint: true,
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'datacenter' => ['type' => 'string', 'description' => 'Datacenter name or id'],
				'cluster' => ['type' => 'string', 'description' => 'Cluster name or id'],
				'instance' => ['type' => 'string', 'description' => 'vCenter instance name'],
			],
		]
	)]
	public function list_hosts(?string $datacenter = null, ?string $cluster = null, string $instance = ''): array
	{
		return $this->manager->instance($instance)->inventory()->listHosts($datacenter, $cluster);
	}

	#[McpTool(
		name: 'list_datastores',
		description: 'List datastores with capacity and free space.',
		readOnlyHint: true,
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'datacenter' => ['type' => 'string', 'description' => 'Datacenter name or id'],
				'host' => ['type' => 'string', 'description' => 'Restrict to datastores visible to this host (name or id)'],
				'type' => ['type' => 'string', 'description' => 'Datastore type filter (VMFS, NFS, VSAN, ...)'],
				'instance' => ['type' => 'string', 'description' => 'vCenter instance name'],
			],
		]
	)]
	public function list_datastores(?string $datacenter = null, ?string $host = null, ?string $type = null, string $instance = ''): array
	{
		return $this->manager->instance($instance)->inventory()->listDatastores($datacenter, $host, $type);
	}

	#[McpTool(
		name: 'list_networks',
		description: 'List networks (standard and distributed portgroups).',
		readOnlyHint: true,
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'datacenter' => ['type' => 'string', 'description' => 'Datacenter name or id'],
				'type' => ['type' => 'string', 'description' => 'Network type filter (STANDARD_PORTGROUP, DISTRIBUTED_PORTGROUP, OPAQUE_NETWORK)'],
				'instance' => ['type' => 'string', 'description' => 'vCenter instance name'],
			],
		]
	)]
	public function list_networks(?string $datacenter = null, ?string $type = null, string $instance = ''): array
	{
		return $this->manager->instance($instance)->inventory()->listNetworks($datacenter, $type);
	}

	#[McpTool(
		name: 'list_folders',
		description: 'List inventory folders (default type VIRTUAL_MACHINE).',
		readOnlyHint: true,
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'datacenter' => ['type' => 'string', 'description' => 'Datacenter name or id'],
				'type' => ['type' => 'string', 'description' => 'Folder type (VIRTUAL_MACHINE, HOST, DATASTORE, NETWORK)'],
				'instance' => ['type' => 'string', 'description' => 'vCenter instance name'],
			],
		]
	)]
	public function list_folders(?string $datacenter = null, string $type = 'VIRTUAL_MACHINE', string $instance = ''): array
	{
		return $this->manager->instance($instance)->inventory()->listFolders($datacenter, $type);
	}

	#[McpTool(
		name: 'list_resource_pools',
		description: 'List resource pools, optionally scoped to a cluster or host.',
		readOnlyHint: true,
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'cluster' => ['type' => 'string', 'description' => 'Cluster name or id'],
				'host' => ['type' => 'string', 'description' => 'Host name or id'],
				'instance' => ['type' => 'string', 'description' => 'vCenter instance name'],
			],
		]
	)]
	public function list_resource_pools(?string $cluster = null, ?string $host = null, string $instance = ''): array
	{
		return $this->manager->instance($instance)->inventory()->listResourcePools($cluster, $host);
	}

	#[McpTool(
		name: 'browse_datastore',
		description: 'List files on a datastore via the datastore browser (e.g. to find an ISO: path "FreeBSD OS", pattern "*.iso").',
		readOnlyHint: true,
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'datastore' => ['type' => 'string', 'description' => 'Datastore name or id'],
				'path' => ['type' => 'string', 'description' => 'Directory under the datastore root (default: root)'],
				'pattern' => ['type' => 'string', 'description' => 'File-name pattern (default "*")'],
				'datacenter' => ['type' => 'string', 'description' => 'Datacenter name or id'],
				'instance' => ['type' => 'string', 'description' => 'vCenter instance name'],
			],
			'required' => ['datastore'],
		]
	)]
	public function browse_datastore(string $datastore, string $path = '', string $pattern = '*', ?string $datacenter = null, string $instance = ''): array
	{
		return $this->manager->instance($instance)->inventory()->browseDatastore($datastore, $path, $pattern, $datacenter);
	}
}
