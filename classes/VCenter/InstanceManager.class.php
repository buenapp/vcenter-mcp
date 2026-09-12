<?php
/**
 * vCenter MCP Server — Instance Manager
 *
 * Multi-instance registry over EnchiladaMCP\InstanceRegistry. Each
 * instance is one vCenter with a single credential set; the "default"
 * from instances.json is used when a tool call omits 'instance'.
 *
 * @package    VCenterMCP\VCenter
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

namespace VCenter;

class InstanceManager extends \EnchiladaMCP\InstanceRegistry
{
	/** @var callable|null HTTP callable for testing, propagated to Instances */
	private $httpClient;

	/** @var \EnchiladaMCP\Logger|null */
	private ?\EnchiladaMCP\Logger $logger = null;

	/** @var \Enchilada\Tortilla\EventLoop|null */
	private ?\Enchilada\Tortilla\EventLoop $loop = null;

	/** @var \Closure|null function(): void */
	private ?\Closure $progress = null;

	/** @var callable|null fn(Instance, string $vmId): Console\WebMksClient — test seam */
	private $webMksFactory = null;

	/** @var array<string,Instance> Instances built by createClient() (parent keeps its cache private) */
	private array $created = [];

	/**
	 * @param array<string,array> $instances  Instance configurations
	 * @param string|null         $default    Default instance name
	 * @param callable|null       $httpClient Optional HTTP callable for testing
	 */
	public function __construct(array $instances, ?string $default = null, ?callable $httpClient = null)
	{
		$this->httpClient = $httpClient;
		parent::__construct($instances, $default);
	}

	/**
	 * Create an InstanceManager from a JSON configuration file.
	 *
	 * @param  string        $path       Path to instances.json
	 * @param  callable|null $httpClient Optional HTTP callable for testing
	 * @return self
	 */
	public static function fromFile(string $path, ?callable $httpClient = null): self
	{
		if (!file_exists($path)) {
			throw new \RuntimeException("Configuration file not found: {$path}");
		}

		$json = file_get_contents($path);
		if ($json === false) {
			throw new \RuntimeException("Failed to read configuration file: {$path}");
		}

		$config = json_decode($json, true);
		if ($config === null && json_last_error() !== JSON_ERROR_NONE) {
			throw new \RuntimeException(
				"Invalid JSON in configuration file {$path}: " . json_last_error_msg()
			);
		}

		return new self(
			$config['instances'] ?? [],
			$config['default'] ?? ($config['default_instance'] ?? null),
			$httpClient
		);
	}

	/** @param \EnchiladaMCP\Logger|null $logger Logger propagated to Instances */
	public function setLogger(?\EnchiladaMCP\Logger $logger): void
	{
		$this->logger = $logger;
	}

	/**
	 * Wire the transport context Instances' HTTP paths run on. Call before
	 * the first resolve(); already-created Instances are not rebuilt.
	 *
	 * @param \Enchilada\Tortilla\EventLoop|null $loop     Shared event loop, or null (blocking waits)
	 * @param callable|null                      $progress function(): void progress emitter
	 */
	public function setHttpTransport(?\Enchilada\Tortilla\EventLoop $loop, ?callable $progress): void
	{
		$this->loop = $loop;
		$this->progress = $progress !== null ? $progress(...) : null;
	}

	/**
	 * Test seam: WebMKS client factory propagated to Instances.
	 *
	 * @param callable|null $factory fn(Instance, string $vmId): Console\WebMksClient
	 */
	public function setWebMksFactory(?callable $factory): void
	{
		$this->webMksFactory = $factory;
	}

	/**
	 * Shutdown hook: close cached WebMKS console sessions and end the
	 * REST/SOAP sessions on every resolved instance, best-effort.
	 */
	public function shutdown(): void
	{
		foreach ($this->created as $client) {
			if (!$client instanceof Instance) {
				continue;
			}
			try {
				$client->closeConsoles();
			} catch (\Throwable $e) {
				// best-effort
			}
			foreach (['rest', 'soap'] as $facade) {
				try {
					$client->{$facade}()->logout();
				} catch (\Throwable $e) {
					// never logged in, or already gone
				}
			}
		}
	}

	/**
	 * Get the Instance for a name, or the default instance.
	 *
	 * @param  string|null $name Instance name ('' treated as null)
	 * @return Instance
	 */
	public function instance(?string $name = null): Instance
	{
		/** @var Instance */
		return $this->resolve($name === '' ? null : $name);
	}

	protected function createClient(string $name, array $config): object
	{
		$instance = new Instance($name, $config, $this->httpClient, $this->loop, $this->progress, $this->logger, $this->webMksFactory);
		$this->created[$name] = $instance;
		if ($this->logger !== null) {
			$this->logger->debug("Created instance {$name} ({$config['url']})");
		}
		return $instance;
	}

	/** Instance summaries for list_vcenter_instances (no credentials). */
	public function listInstances(): array
	{
		$result = [];
		foreach ($this->instances as $name => $config) {
			$result[$name] = [
				'url' => $config['url'] ?? '',
				'description' => $config['description'] ?? '',
				'username' => $config['username'] ?? '',
				'netrc' => !empty($config['netrc']),
				'exclude_hosts' => $config['exclude_hosts'] ?? [],
				'defaults' => $config['defaults'] ?? [],
			];
		}
		return $result;
	}

	public function count(): int
	{
		return count($this->instances);
	}
}
