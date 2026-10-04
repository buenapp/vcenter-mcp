<?php
/**
 * vCenter MCP Server -- OVF/OVA Tools
 *
 * OVA/OVF introspection and deployment. Introspection stages the
 * package locally (datastore /folder download or plain HTTPS GET) and
 * parses the OVF descriptor. Deployment imports the package into a
 * content library item server-side (content library PULL of a ds:///
 * or http(s) source; no bulk bytes cross this server), deploys it
 * through vcenter/ovf/library-item, then deletes the staged item.
 *
 * @package    VCenterMCP\Tools
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

use EnchiladaMCP\McpTool;
use VCenter\Instance;
use VCenter\InstanceManager;
use VCenter\OvfPackage;
use VCenter\VCenterException;

class OvfTools
{
	private InstanceManager $manager;

	/** @var array<string,\Enchilada\Tortilla\HttpClient> Per-host clients for URL fetches */
	private array $fetchClients = [];

	public function __construct(InstanceManager $manager)
	{
		$this->manager = $manager;
	}

	private function inst(string $instance): Instance
	{
		return $this->manager->instance($instance);
	}

	#[McpTool(
		name: 'ovf_info',
		description: 'Inspect an OVA/OVF package and report its declared name, networks, vApp properties, disks and EULAs. Source is "[Datastore] path/file.ova" or an http(s) URL. Use the network names as deploy_ova network_mappings keys.',
		readOnlyHint: true,
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'image' => ['type' => 'string', 'description' => '"[Datastore] path/to/appliance.ova" (or .ovf), or an http(s) URL'],
				'datacenter' => ['type' => 'string', 'description' => 'Name or id; set when the datastore name is ambiguous across datacenters'],
				'instance' => ['type' => 'string', 'description' => 'vCenter instance name; default instance if omitted (see list_vcenter_instances)'],
			],
			'required' => ['image'],
		]
	)]
	public function ovf_info(string $image, ?string $datacenter = null, string $instance = ''): array
	{
		$inst = $this->inst($instance);

		if (preg_match('#^https?://#i', $image) === 1) {
			return $this->describeStaged($inst, $image, $this->stageUrl($inst, $image));
		}

		[$dsName, $path] = $this->splitDatastorePath($image);
		if (preg_match('/\.ova$/i', $path) === 1) {
			return $this->describeStaged($inst, $image, $this->stageDatastoreFile($inst, $image, $datacenter));
		}

		// Bare .ovf descriptors are small text: read directly.
		$file = $inst->inventory()->readDatastoreFile($dsName, $path, $datacenter);
		$package = OvfPackage::fromDescriptor($file['content'], basename($path));
		return $package->info() + ['source' => $file['path'], 'kind' => 'ovf'];
	}

	#[McpTool(
		name: 'deploy_ova',
		description: 'Deploy a VM from an OVA ("[Datastore] path/file.ova" or http(s) URL) staged through a content library. Network bindings come from network_mappings (OVF network name -> vCenter network) or, for single-network appliances, network. Returns the new VM id; the VM is created powered off unless power_on is true. accept_eula=true is required when the package declares EULAs (see ovf_info).',
		inputSchema: [
			'type' => 'object',
			'properties' => [
				'image' => ['type' => 'string', 'description' => '"[Datastore] path/to/appliance.ova" or an http(s) URL'],
				'name' => ['type' => 'string', 'description' => 'New VM name'],
				'network' => ['type' => 'string', 'description' => 'vCenter network for all OVF networks of single-network appliances; use network_mappings for multi-network packages'],
				'network_mappings' => ['type' => 'object', 'description' => 'OVF network name -> vCenter network name or id (see ovf_info networks)'],
				'host' => ['type' => 'string', 'description' => 'Name or id; omitted picks a non-excluded host'],
				'cluster' => ['type' => 'string', 'description' => 'Name or id; scopes the host pick'],
				'datastore' => ['type' => 'string', 'description' => 'Name or id (also backs the staging library)'],
				'folder' => ['type' => 'string', 'description' => 'Name or id'],
				'resource_pool' => ['type' => 'string', 'description' => 'Name or id'],
				'datacenter' => ['type' => 'string', 'description' => 'Name or id; set when other names are ambiguous across datacenters'],
				'properties' => ['type' => 'object', 'description' => 'vApp property values (keys from ovf_info properties)'],
				'accept_eula' => ['type' => 'boolean', 'description' => 'Accept all declared EULAs (default false)'],
				'power_on' => ['type' => 'boolean', 'description' => 'Power on after deploy (default false)'],
				'thumbprint' => ['type' => 'string', 'description' => 'SHA-256/1 thumbprint of the URL server when its certificate cannot be validated'],
				'instance' => ['type' => 'string', 'description' => 'vCenter instance name; default instance if omitted (see list_vcenter_instances)'],
			],
			'required' => ['image', 'name'],
		]
	)]
	public function deploy_ova(
		string $image, string $name,
		?string $network = null, ?array $network_mappings = null,
		?string $host = null, ?string $cluster = null, ?string $datastore = null,
		?string $folder = null, ?string $resource_pool = null, ?string $datacenter = null,
		?array $properties = null, bool $accept_eula = false, bool $power_on = false,
		?string $thumbprint = null, string $instance = ''
	): array {
		$inst = $this->inst($instance);
		$inv = $inst->inventory();
		$library = new \VCenter\ContentLibrary($inst);

		if (preg_match('#^https?://#i', $image) === 1) {
			$sourceUri = $image;
			$fileName = basename(parse_url($image, PHP_URL_PATH) ?: 'package.ova');
			// size is mandatory (sizeless file specs import empty items),
			// and vCenter pulls with its own TLS chain validation, so HEAD
			// the URL first: Content-Length for the file spec, and the
			// peer certificate as a TOFU thumbprint when none is passed.
			[$imageSize, $peerThumbprint] = $this->urlInfo($inst, $image);
			$thumbprint = $thumbprint ?? $peerThumbprint;
		} else {
			[$dsName, $dsPath] = $this->splitDatastorePath($image);
			if (preg_match('/\.ova$/i', $dsPath) !== 1) {
				throw new VCenterException(
					'deploy_ova needs a tar .ova package; a bare .ovf references sibling files the library import cannot pull',
					0, 'InvalidArgument'
				);
			}
			$imageSize = $this->dsFileSize($inst, $dsName, $dsPath, $datacenter);
			$sourceUri = $this->datastoreUri($inst, $dsName, $dsPath, $datacenter);
			$fileName = basename($dsPath);
		}

		// Placement, mirroring create_vm.
		$datacenter = $datacenter ?? ($inst->defaults()['datacenter'] ?? null);
		$cluster = $cluster ?? ($inst->defaults()['cluster'] ?? null);
		$datastore = $datastore ?? ($inst->defaults()['datastore'] ?? null);
		$folder = $folder ?? ($inst->defaults()['folder'] ?? null);

		$hostEntry = $host !== null
			? $inv->resolveHost($host)
			: $inv->pickHost($datacenter, $cluster);
		$dsEntry = $datastore !== null
			? $inv->resolveDatastore($datastore, $datacenter, $hostEntry['host'])
			: $inv->pickDatastore($hostEntry['host']);

		if ($folder !== null) {
			$folderId = $inv->resolveFolder($folder, $datacenter)['folder'];
		} else {
			$folders = $inv->listFolders($datacenter, 'VIRTUAL_MACHINE');
			if (empty($folders)) {
				throw new VCenterException('No VIRTUAL_MACHINE folder found for VM placement', 0, 'NoPlacement');
			}
			$folderId = $folders[0]['folder'];
		}

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

		$target = [
			'resource_pool_id' => $poolId,
			'host_id' => $hostEntry['host'],
			'folder_id' => $folderId,
		];
		$spec = [
			'name' => $name,
			'annotation' => "Deployed from {$image} by vcenter-mcp deploy_ova",
			'accept_all_EULA' => $accept_eula,
			'default_datastore_id' => $dsEntry['datastore'],
		];
		if ($properties !== null) {
			// The deployment spec takes properties as a name->value map
			$spec['properties'] = array_map('strval', $properties);
		}

		// Stage: library -> item -> update session -> server-side PULL.
		$libraryId = $library->stagingLibrary($dsEntry['datastore']);
		$itemId = $library->createItem($libraryId, 'deploy-' . $name . '-' . date('Ymd-His'));
		$sessionId = null;
		try {
			$sessionId = $library->openUpdateSession($itemId);
			$library->addPullFile($sessionId, $fileName, $sourceUri, $thumbprint, $imageSize);
			// PULL runs during and after complete; done == session DONE
			$library->completeSession($sessionId);
			$library->waitSessionDone($sessionId);
			$sessionId = null;

			// Network mappings (a name->id map in the deployment spec):
			// explicit wins; else map every OVF network (discovered
			// through the imported item's filter) to $network.
			$mappings = [];
			if ($network_mappings !== null) {
				foreach ($network_mappings as $ovfNet => $vcNet) {
					$mappings[(string) $ovfNet] = $inv->resolveNetwork((string) $vcNet, $datacenter)['network'];
				}
			} elseif ($network !== null) {
				$netId = $inv->resolveNetwork($network, $datacenter)['network'];
				foreach ($this->ovfNetworks($library, $itemId, $target) as $ovfNet) {
					$mappings[$ovfNet] = $netId;
				}
			}
			if ($mappings !== []) {
				$spec['network_mappings'] = $mappings;
			}

			$result = $library->deployOvf($itemId, $target, $spec);
		} catch (\Throwable $e) {
			if ($sessionId !== null) {
				$library->cancelSession($sessionId);
			}
			$library->deleteItem($itemId);
			throw $e;
		}

		$library->deleteItem($itemId);

		$vmId = (string) ($result['resource_id']['id'] ?? '');
		if ($vmId === '') {
			throw new VCenterException(
				'OVF deploy succeeded but reported no VM id: ' . json_encode($result), 0, 'Deploy');
		}

		$poweredOn = false;
		if ($power_on) {
			$inst->rest()->post("vcenter/vm/{$vmId}/power", null, ['action' => 'start']);
			$poweredOn = true;
		}

		return [
			'vm' => $vmId,
			'name' => $name,
			'datastore' => $dsEntry['datastore'],
			'host' => $hostEntry['host'],
			'powered_on' => $poweredOn,
		];
	}

	// ── Staging + parsing ────────────────────────────────────────────

	/** Describe a staged local file, always cleaning it up. */
	private function describeStaged(Instance $inst, string $source, string $path): array
	{
		try {
			$package = OvfPackage::fromFile($path);
		} finally {
			@unlink($path);
		}
		return $package->info() + [
			'source' => $source,
			'kind' => preg_match('/\.ova$/i', $source) === 1 ? 'ova' : 'ovf',
		];
	}

	/**
	 * Download a datastore file to a temp path via the /folder endpoint.
	 *
	 * @throws VCenterException
	 */
	private function stageDatastoreFile(Instance $inst, string $image, ?string $datacenter): string
	{
		$this->bumpStagingMemory();
		[$dsName, $path] = $this->splitDatastorePath($image);
		$ds = $inst->inventory()->resolveDatastore($dsName, $datacenter);
		$dc = $inst->properties()->datacenterOf(['type' => 'Datastore', 'id' => $ds['datastore']]);
		$tmp = $this->tempPathFor($path);
		$inst->soap()->downloadDatastoreFileTo(
			"[{$ds['name']}] {$path}", $dc['name'], (string) $ds['name'], $tmp, 3600);
		return $tmp;
	}

	/**
	 * Download a URL to a temp path. Plain GET without credentials; the
	 * URL is fetched by this server (unlike deploy_ova, where vCenter
	 * pulls it).
	 *
	 * @throws VCenterException
	 */
	private function stageUrl(Instance $inst, string $url, int $timeout = 3600): string
	{
		$this->bumpStagingMemory();
		$tmp = $this->tempPathFor(basename((string) (parse_url($url, PHP_URL_PATH) ?: 'package.ova')));
		$fake = $inst->httpClient();
		if ($fake !== null) {
			$response = $fake('GET', $url, [], null);
			$code = $response['code'];
			file_put_contents($tmp, (string) ($response['body'] ?? ''));
		} else {
			$parts = parse_url($url);
			if ($parts === false || empty($parts['scheme']) || !isset($parts['host'], $parts['path'])) {
				throw new VCenterException("Malformed image URL: {$url}", 0, 'ParseError');
			}
			$port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
			$key = $parts['host'] . ':' . $port;
			$client = $this->fetchClients[$key] ?? null;
			if ($client === null) {
				$multi = new \EnchiladaMultiHTTP($parts['scheme'] . '://' . $parts['host'] . ':' . $port);
				$multi->setTimeout($timeout);
				$inst->tlsPolicy()->apply($multi, $parts['host'], $port);
				$client = $this->fetchClients[$key] = new \Enchilada\Tortilla\HttpClient($multi);
			}
			$fh = fopen($tmp, 'wb');
			if ($fh === false) {
				throw new VCenterException('Cannot write staging temp file', 0, 'Staging');
			}
			$path = ltrim($parts['path'], '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
			try {
				$client->call($path, null, 'GET', [], null, 'raw', function (string $chunk) use ($fh) {
					fwrite($fh, $chunk);
				});
			} finally {
				fclose($fh);
			}
			$code = $client->getHttpCode();
		}
		if ($code !== 200) {
			@unlink($tmp);
			throw new VCenterException("Image download failed (HTTP {$code}) for {$url}", $code, 'Download');
		}
		return $tmp;
	}

	/**
	 * The ds:/// URI a content-library PULL fetches server-side, built
	 * from the datastore's summary.url property.
	 *
	 * @throws VCenterException
	 */
	private function datastoreUri(Instance $inst, string $dsName, string $path, ?string $datacenter): string
	{
		$ds = $inst->inventory()->resolveDatastore($dsName, $datacenter);
		$props = $inst->properties()->get('Datastore', $ds['datastore'], ['summary.url']);
		$base = rtrim((string) ($props['summary.url'] ?? ''), '/');
		if ($base === '') {
			throw new VCenterException(
				"Datastore {$ds['datastore']} reports no summary.url for a ds:/// source URI", 0, 'NotSupported');
		}
		return $base . '/' . implode('/', array_map('rawurlencode', explode('/', trim($path, '/'))));
	}

	/** Split "[Datastore] path/file" into [datastore name, path]. */
	private function splitDatastorePath(string $image): array
	{
		if (preg_match('/^\[([^\]]+)\]\s+(.+)$/', trim($image), $m) !== 1) {
			throw new VCenterException(
				"Not a datastore path or URL: '{$image}' (expected \"[Datastore] path/file\" or http(s) URL)",
				0, 'InvalidArgument'
			);
		}
		return [$m[1], $m[2]];
	}

	/** Size of a datastore file via browse_datastore. */
	private function dsFileSize(Instance $inst, string $dsName, string $path, ?string $datacenter): ?int
	{
		$base = basename($path);
		$dir = trim(dirname($path), './');
		foreach ($inst->inventory()->browseDatastore($dsName, $dir, $base, $datacenter) as $file) {
			if (str_ends_with($file['path'], '/' . $base) && isset($file['size'])) {
				return (int) $file['size'];
			}
		}
		throw new VCenterException(
			"No datastore file '{$base}' in [{$dsName}] " . ($dir === '' ? '/' : $dir),
			0, 'NotFound'
		);
	}

	/**
	 * HEAD an http(s) URL: returns [size, sha1-thumbprint of the peer
	 * certificate]. Follows redirects; size/thumbprint null when they
	 * cannot be determined.
	 *
	 * @return array{0:?int,1:?string}
	 */
	private function urlInfo(Instance $inst, string $url, int $hops = 0): array
	{
		if ($hops > 5) {
			return [null, null];
		}

		$fake = $inst->httpClient();
		if ($fake !== null) {
			$response = $fake('HEAD', $url, [], null);
			$size = null;
			foreach ((array) ($response['headers']['content-length'] ?? []) as $v) {
				$size = (int) $v;
			}
			return [$size, null];
		}

		$parts = parse_url($url);
		if ($parts === false || empty($parts['scheme']) || !isset($parts['host'], $parts['path'])) {
			return [null, null];
		}
		$tls = $parts['scheme'] === 'https';
		$port = $parts['port'] ?? ($tls ? 443 : 80);
		$context = stream_context_create(['ssl' => [
			'capture_peer_cert' => true,
			'verify_peer' => false,
			'verify_peer_name' => false,
			'allow_self_signed' => true,
		]]);
		$fp = @stream_socket_client(($tls ? 'ssl://' : 'tcp://') . $parts['host'] . ':' . $port,
			$errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context);
		if ($fp === false) {
			return [null, null];
		}

		$thumbprint = null;
		$params = stream_context_get_params($fp);
		$cert = $params['options']['ssl']['peer_certificate'] ?? null;
		if ($cert !== null) {
			openssl_x509_export($cert, $pem);
			$der = base64_decode(implode('', array_diff(
				explode("\n", (string) $pem),
				['', '-----BEGIN CERTIFICATE-----', '-----END CERTIFICATE-----'])));
			foreach (str_split(sha1($der), 2) as $i => $byte) {
				$thumbprint = ($thumbprint === null ? '' : $thumbprint . ':') . $byte;
			}
		}

		$request = 'HEAD ' . $parts['path'] . (isset($parts['query']) ? '?' . $parts['query'] : '')
			. " HTTP/1.1\r\nHost: " . $parts['host'] . "\r\nConnection: close\r\n\r\n";
		fwrite($fp, $request);
		$headers = '';
		while (!feof($fp) && !str_contains($headers, "\r\n\r\n") && strlen($headers) < 16384) {
			$headers .= fgets($fp, 4096);
		}
		fclose($fp);

		if (preg_match('#^HTTP/\S+\s+(\d+)#mi', $headers, $m) !== 1) {
			return [null, $thumbprint];
		}
		$status = (int) $m[1];
		if ($status >= 300 && $status < 400 && preg_match('#^location:\s*(\S+)#mi', $headers, $lm) === 1) {
			return $this->urlInfo($inst, $lm[1], $hops + 1);
		}
		$size = null;
		if (preg_match('#^content-length:\s*(\d+)#mi', $headers, $cm) === 1) {
			$size = (int) $cm[1];
		}
		return [$size, $thumbprint];
	}

	/**
	 * The HTTP engine accumulates the body alongside the write callback,
	 * so staging big packages needs headroom: raise memory_limit to 4 GiB
	 * when it is lower.
	 */
	private function bumpStagingMemory(): void
	{
		$limit = ini_get('memory_limit');
		if ($limit !== false && $limit !== '-1' && $this->bytes((string) $limit) < 4294967296) {
			ini_set('memory_limit', '4096M');
		}
	}

	/** Parse an ini shorthand size. */
	private function bytes(string $s): int
	{
		$s = trim($s);
		$unit = strtoupper(substr($s, -1));
		$pos = stripos('KMGT', $unit);
		return $pos === false ? (int) $s : (int) ((float) $s * pow(1024, $pos + 1));
	}

	/**
	 * Temp path for a staged package, keeping an extension PharData
	 * accepts (it refuses unknown ones). Tar packages become .tar.
	 *
	 * @throws VCenterException
	 */
	private function tempPathFor(string $sourceName): string
	{
		$tmp = tempnam(sys_get_temp_dir(), 'ovf-');
		if ($tmp === false) {
			throw new VCenterException('Cannot create a staging temp file', 0, 'Staging');
		}
		$ext = match (strtolower(pathinfo($sourceName, PATHINFO_EXTENSION))) {
			'ova', 'tar' => '.tar',
			default => '.ovf',
		};
		$named = $tmp . $ext;
		if (!rename($tmp, $named)) {
			@unlink($tmp);
			throw new VCenterException('Cannot prepare the staging temp file', 0, 'Staging');
		}
		return $named;
	}

	/**
	 * OVF network names of the imported library item, via the deploy
	 * filter. Empty when the filter endpoint declines.
	 *
	 * @return string[]
	 */
	private function ovfNetworks(\VCenter\ContentLibrary $library, string $itemId, array $target): array
	{
		// The item's OVF metadata is processed asynchronously after the
		// update session completes; retry briefly.
		$info = null;
		$cause = null;
		for ($attempt = 0; $attempt < 12; $attempt++) {
			try {
				$info = $library->filterOvf($itemId, $target);
				break;
			} catch (VCenterException $e) {
				$cause = $e;
				usleep(5000000);
			}
		}
		if ($info === null) {
			throw new VCenterException(
				'Cannot discover the OVF networks of the imported item'
					. ($cause !== null ? ': ' . $cause->getMessage() : '')
					. '; pass explicit network_mappings (OVF network name -> vCenter network) instead of network',
				0, 'FilterError', $cause
			);
		}
		$names = [];
		// The filter response capitalizes its keys ("Networks")
		foreach ((array) ($info['Networks'] ?? $info['networks'] ?? []) as $net) {
			if (is_array($net) && isset($net['name'])) {
				$names[] = (string) $net['name'];
			} elseif (is_string($net)) {
				$names[] = $net;
			}
		}
		return $names;
	}

	/**
	 * Resource pool of a host's compute resource via SOAP, same fallback
	 * create_vm uses when no pool is visible through REST.
	 *
	 * @throws VCenterException When the chain yields no pool
	 */
	private function hostResourcePool(Instance $inst, string $hostId): string
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
