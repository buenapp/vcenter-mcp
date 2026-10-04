<?php
/**
 * vCenter MCP Server -- OVF/OVA Package Introspection
 *
 * Reads an OVF 1.x descriptor (either a bare .ovf XML stream or the
 * .ovf member inside a .ova tar) and summarizes what a caller needs
 * before deploy: product/virtual system name, networks, vApp
 * properties, disks and EULAs.
 *
 * @package    VCenterMCP\VCenter
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

namespace VCenter;

class OvfPackage
{
	/** Reference to the descriptor file inside the staged package. */
	private string $file;

	/** Parsed summary. */
	private array $summary;

	private function __construct(string $descriptorXml, string $file)
	{
		$this->file = $file;
		$this->summary = self::parse($descriptorXml);
	}

	/**
	 * Wrap an already-fetched descriptor text.
	 *
	 * @throws VCenterException When the XML is not an OVF envelope
	 */
	public static function fromDescriptor(string $ovfXml, string $file = 'inline.ovf'): self
	{
		return new self($ovfXml, $file);
	}

	/**
	 * Load a package: a bare .ovf file or a .ova tar on local disk.
	 *
	 * @throws VCenterException When no descriptor is found
	 */
	public static function fromFile(string $path): self
	{
		$head = @file_get_contents($path, false, null, 0, 512);
		if ($head === false) {
			throw new VCenterException("Cannot read package {$path}", 0, 'Staging');
		}
		// tar archives carry the ustar magic at offset 257
		if (!str_contains($head, 'ustar')) {
			$xml = file_get_contents($path);
			if ($xml === false) {
				throw new VCenterException("Cannot read OVF descriptor {$path}", 0, 'Staging');
			}
			return new self($xml, basename($path));
		}

		try {
			$phar = new \PharData($path);
		} catch (\Exception $e) {
			throw new VCenterException("Cannot open OVA archive {$path}: " . $e->getMessage(), 0, 'ParseError', $e);
		}
		foreach (new \RecursiveIteratorIterator($phar) as $entry) {
			/** @var \PharFileInfo $entry */
			if (preg_match('/\.ovf$/i', $entry->getFileName()) === 1) {
				return new self($entry->getContent(), $entry->getFileName());
			}
		}
		throw new VCenterException("OVA archive {$path} contains no .ovf descriptor", 0, 'ParseError');
	}

	/** The summary array (see parse()). */
	public function info(): array
	{
		return $this->summary + ['descriptor' => $this->file];
	}

	/** OVF network names the package declares. */
	public function networks(): array
	{
		return array_map(fn($n) => (string) $n['name'], $this->summary['networks']);
	}

	/**
	 * Parse an OVF descriptor into the tool-output shape.
	 *
	 * @throws VCenterException When the XML is not an OVF envelope
	 */
	public static function parse(string $ovfXml): array
	{
		$xml = @simplexml_load_string($ovfXml);
		if ($xml === false || !str_contains($xml->getName(), 'Envelope')) {
			throw new VCenterException('Input is not a valid OVF descriptor (no Envelope root)', 0, 'ParseError');
		}
		$xml->registerXPathNamespace('ovf', 'http://schemas.dmtf.org/ovf/envelope/1');
		$xp = fn(string $q) => $xml->xpath($q) ?: [];

		$name = null;
		foreach ($xp('/ovf:Envelope/*') as $top) {
			$topName = $top->getName();
			if ($topName === 'VirtualSystem' || $topName === 'VirtualSystemCollection') {
				$attrs = $top->attributes('http://schemas.dmtf.org/ovf/envelope/1');
				$name = (string) ($attrs['id'] ?? '');
				break;
			}
		}

		$networks = [];
		foreach ($xp('//ovf:NetworkSection/ovf:Network') as $net) {
			$attrs = $net->attributes('http://schemas.dmtf.org/ovf/envelope/1');
			$description = (string) (($net->xpath('./ovf:Description') ?: [null])[0] ?? '');
			$networks[] = ['name' => (string) ($attrs['name'] ?? ''), 'description' => $description];
		}

		$properties = [];
		foreach ($xp('//ovf:ProductSection/ovf:Property') as $prop) {
			$attrs = $prop->attributes('http://schemas.dmtf.org/ovf/envelope/1');
			$label = (string) (($prop->xpath('./ovf:Label') ?: [null])[0] ?? '');
			$properties[] = [
				'key' => (string) ($attrs['key'] ?? ''),
				'label' => $label,
				'type' => (string) ($attrs['type'] ?? ''),
				'default' => (string) ($attrs['value'] ?? ''),
				'user_configurable' => self::boolAttr($attrs['userConfigurable'] ?? null),
			];
		}

		$disks = [];
		foreach ($xp('//ovf:DiskSection/ovf:Disk') as $disk) {
			$attrs = $disk->attributes('http://schemas.dmtf.org/ovf/envelope/1');
			$disks[] = [
				'disk_id' => (string) ($attrs['diskId'] ?? ''),
				'capacity' => (string) ($attrs['capacity'] ?? ''),
				'units' => (string) ($attrs['capacityAllocationUnits'] ?? ''),
			];
		}

		$eulas = [];
		foreach ($xp('//ovf:EulaSection') as $eula) {
			$info = (string) (($eula->xpath('./ovf:Info') ?: [null])[0] ?? '');
			$license = trim((string) (($eula->xpath('./ovf:License') ?: [null])[0] ?? ''));
			$eulas[] = ['info' => $info, 'license' => $license];
		}

		return [
			'name' => $name,
			'networks' => $networks,
			'properties' => $properties,
			'disks' => $disks,
			'eulas' => $eulas,
		];
	}

	private static function boolAttr(?string $v): bool
	{
		return $v === 'true' || $v === '1';
	}
}
