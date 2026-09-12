<?php
/**
 * vCenter MCP Server — PropertyCollector Helpers
 *
 * Thin facade over SoapClient::properties(): read properties of MoRefs,
 * and walk the 'parent' chain up to the Datacenter (used by datastore
 * file download URLs and console helpers).
 *
 * @package    VCenterMCP\VCenter
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

namespace VCenter;

class PropertyCollector
{
	private SoapClient $soap;

	public function __construct(SoapClient $soap)
	{
		$this->soap = $soap;
	}

	/**
	 * Read properties of a single MoRef.
	 *
	 * @param  string   $type  Managed-object type (e.g. "HostSystem")
	 * @param  string   $id    MoRef id (e.g. "host-12")
	 * @param  string[] $props Property paths (e.g. ["name","parent","datastore"])
	 * @return array<string,mixed> name => value
	 */
	public function get(string $type, string $id, array $props): array
	{
		$rows = $this->soap->properties([['type' => $type, 'id' => $id]], [$type => $props]);
		return $rows["{$type}:{$id}"] ?? [];
	}

	/**
	 * Read properties of many MoRefs of one type.
	 *
	 * @param  array<int,array{type:string,id:string}>|string[] $objects MoRefs or bare ids
	 * @param  string   $type  Managed-object type (used when $objects are ids)
	 * @param  string[] $props
	 * @return array<string,array<string,mixed>> keyed by "type:id"
	 */
	public function getMany(array $objects, string $type, array $props): array
	{
		$refs = [];
		foreach ($objects as $obj) {
			$refs[] = is_array($obj) ? $obj : ['type' => $type, 'id' => $obj];
		}
		return $this->soap->properties($refs, [$type => $props]);
	}

	/**
	 * Walk 'parent' from a MoRef up to the enclosing Datacenter.
	 *
	 * @param  array{type:string,id:string} $mref Starting MoRef
	 * @param  int $maxHops Safety bound on the walk
	 * @return array{type:string,id:string,name:string}
	 * @throws VCenterException When no Datacenter is reached
	 */
	public function datacenterOf(array $mref, int $maxHops = 16): array
	{
		$current = $mref;
		for ($i = 0; $i < $maxHops; $i++) {
			if ($current['type'] === 'Datacenter') {
				$props = $this->get('Datacenter', $current['id'], ['name']);
				return ['type' => 'Datacenter', 'id' => $current['id'], 'name' => (string) ($props['name'] ?? '')];
			}
			$props = $this->get($current['type'], $current['id'], ['parent', 'name']);
			$parent = $props['parent'] ?? null;
			if (!is_array($parent) || empty($parent['id'])) {
				break;
			}
			$current = ['type' => (string) $parent['type'], 'id' => (string) $parent['id']];
		}
		throw new VCenterException(
			"Could not reach a Datacenter by walking parent from {$mref['type']}:{$mref['id']}",
			0, 'NoDatacenter'
		);
	}
}
