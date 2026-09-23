<?php
/**
 * vCenter MCP Server — Instance
 *
 * One configured vCenter: URL, credentials (inline or ~/.netrc), TLS
 * policy, host exclusions and inventory defaults. Owns the shared HTTP
 * engine (Tortilla\HttpClient over EnchiladaMultiHTTP) and lazily builds
 * the RestClient / SoapClient / PropertyCollector / Task / Inventory
 * facades on top of it. Tests inject a plain HTTP callable through
 * InstanceManager — when set, no engine or TLS resolution happens.
 *
 * @package    VCenterMCP\VCenter
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

namespace VCenter;

class Instance
{
	/** @var string Instance name from instances.json */
	private string $name;

	/** @var array<string,mixed> Raw instance configuration */
	private array $config;

	/** @var callable|null HTTP callable for testing: fn(method, url, headers, body): array{code:int,body:string} */
	private $httpClient;

	/** @var \Enchilada\Tortilla\EventLoop|null Shared event loop (null = blocking waits) */
	private ?\Enchilada\Tortilla\EventLoop $loop;

	/** @var \Closure|null function(): void progress emitter for blocking-mode waits */
	private ?\Closure $progress;

	/** @var \EnchiladaMCP\Logger|null */
	private ?\EnchiladaMCP\Logger $logger;

	/** @var \EnchiladaMultiHTTP|null Underlying multi engine */
	private ?\EnchiladaMultiHTTP $multi = null;

	/** @var \Enchilada\Tortilla\HttpClient|null Shared HTTP engine */
	private ?\Enchilada\Tortilla\HttpClient $http = null;

	/** @var bool Whether the TLS decision has been applied to the engine */
	private bool $tlsApplied = false;

	private ?TlsPolicy $tlsPolicy = null;
	private ?RestClient $rest = null;
	private ?SoapClient $soap = null;
	private ?PropertyCollector $properties = null;
	private ?Task $tasks = null;
	private ?Inventory $inventory = null;
	private ?GuestOperations $guestOps = null;

	/** @var callable|null fn(Instance, string $vmId): Console\WebMksClient — test seam */
	private $webMksFactory;

	/** @var array<string,Console\WebMksClient> Open WebMKS sessions by vm id */
	private array $webMksSessions = [];

	/**
	 * @param string                          $name       Instance name
	 * @param array<string,mixed>             $config     Instance configuration
	 * @param callable|null                   $httpClient HTTP callable for testing
	 * @param \Enchilada\Tortilla\EventLoop|null $loop    Shared event loop
	 * @param callable|null                   $progress   function(): void progress emitter
	 * @param \EnchiladaMCP\Logger|null       $logger     Optional logger
	 */
	public function __construct(
		string $name,
		array $config,
		?callable $httpClient = null,
		?\Enchilada\Tortilla\EventLoop $loop = null,
		?callable $progress = null,
		?\EnchiladaMCP\Logger $logger = null,
		?callable $webMksFactory = null
	) {
		if (empty($config['url'])) {
			throw new \InvalidArgumentException("Instance '{$name}' is missing 'url'");
		}
		$this->name = $name;
		$this->config = $config;
		$this->httpClient = $httpClient;
		$this->loop = $loop;
		$this->progress = $progress !== null ? $progress(...) : null;
		$this->logger = $logger;
		$this->webMksFactory = $webMksFactory;
	}

	public function name(): string
	{
		return $this->name;
	}

	/** Base URL without trailing slash. */
	public function url(): string
	{
		return rtrim($this->config['url'], '/');
	}

	public function description(): string
	{
		return $this->config['description'] ?? '';
	}

	public function username(): string
	{
		return (string) ($this->config['username'] ?? '');
	}

	/**
	 * Password: inline config value, or ~/.netrc when 'netrc' is true.
	 *
	 * @throws VCenterException When no password can be resolved
	 */
	public function password(): string
	{
		$password = $this->config['password'] ?? null;
		if (is_string($password) && $password !== '') {
			return $password;
		}
		if (!empty($this->config['netrc'])) {
			$host = parse_url($this->url(), PHP_URL_HOST) ?: '';
			$found = Netrc::lookup($host, $this->config['netrc_path'] ?? null);
			if ($found !== null && $found['password'] !== '') {
				return $found['password'];
			}
			throw new VCenterException(
				"No .netrc entry with a password for '{$host}' (instance '{$this->name}')",
				0, 'Credentials'
			);
		}
		throw new VCenterException(
			"Instance '{$this->name}' has no password and netrc is not enabled",
			0, 'Credentials'
		);
	}

	/** Lowercased excluded hostnames (matched case-insensitively). */
	public function excludeHosts(): array
	{
		return array_map('strtolower', array_map('strval', $this->config['exclude_hosts'] ?? []));
	}

	/** Inventory defaults (datacenter, cluster, network, datastore, folder). */
	public function defaults(): array
	{
		return $this->config['defaults'] ?? [];
	}

	public function tlsPolicy(): TlsPolicy
	{
		if ($this->tlsPolicy === null) {
			$tls = $this->config['tls'] ?? [];
			$this->tlsPolicy = new TlsPolicy(
				$tls['verify'] ?? false,
				$tls['ca_cert'] ?? null,
				$tls['thumbprint'] ?? null,
				null, null,
				$this->logger !== null ? fn(string $m) => $this->logger->debug($m) : null
			);
		}
		return $this->tlsPolicy;
	}

	/** Test seam: override the TLS policy (e.g. inject TLSA lookup). */
	public function setTlsPolicy(TlsPolicy $policy): void
	{
		$this->tlsPolicy = $policy;
	}

	/** Test seam: the injected HTTP callable, or null in production. */
	public function httpClient(): ?callable
	{
		return $this->httpClient;
	}

	/**
	 * Shared loop-aware HTTP engine for this instance. The TLS decision is
	 * applied once, on first use — it can probe the network, so nothing
	 * here runs for tools/list or for tests (which inject a callable).
	 */
	public function http(): \Enchilada\Tortilla\HttpClient
	{
		if ($this->http === null) {
			$multi = new \EnchiladaMultiHTTP($this->url());
			$multi->setTimeout($this->config['timeout'] ?? 30);
			$this->http = new \Enchilada\Tortilla\HttpClient($multi, $this->loop, $this->progress);
			$this->multi = $multi;
		}
		if (!$this->tlsApplied) {
			$this->tlsApplied = true;
			$host = parse_url($this->url(), PHP_URL_HOST) ?: '';
			$port = parse_url($this->url(), PHP_URL_PORT) ?: 443;
			try {
				$this->tlsPolicy()->apply($this->multi, $host, $port);
			} catch (VCenterException $e) {
				$this->tlsApplied = false;
				throw $e;
			}
		}
		return $this->http;
	}

	public function rest(): RestClient
	{
		if ($this->rest === null) {
			$this->rest = new RestClient($this);
		}
		return $this->rest;
	}

	public function soap(): SoapClient
	{
		if ($this->soap === null) {
			$this->soap = new SoapClient($this);
		}
		return $this->soap;
	}

	public function properties(): PropertyCollector
	{
		if ($this->properties === null) {
			$this->properties = new PropertyCollector($this->soap());
		}
		return $this->properties;
	}

	public function tasks(): Task
	{
		if ($this->tasks === null) {
			$this->tasks = new Task($this->properties());
		}
		return $this->tasks;
	}

	public function inventory(): Inventory
	{
		if ($this->inventory === null) {
			$this->inventory = new Inventory($this);
		}
		return $this->inventory;
	}

	public function guestOps(): GuestOperations
	{
		if ($this->guestOps === null) {
			$this->guestOps = new GuestOperations($this);
		}
		return $this->guestOps;
	}

	/**
	 * Open WebMKS console session for a VM, cached per vm id (tickets
	 * are single-use; an established connection is reused). A session
	 * that is no longer connected is discarded and rebuilt.
	 */
	public function webMksSession(string $vmId): Console\WebMksClient
	{
		$client = $this->webMksSessions[$vmId] ?? null;
		if ($client !== null && $client->isOpen()) {
			return $client;
		}
		$client = $this->webMksFactory !== null
			? ($this->webMksFactory)($this, $vmId)
			: new Console\WebMksClient($this, $vmId);
		$client->open();
		return $this->webMksSessions[$vmId] = $client;
	}

	/** Drop a cached WebMKS session (after an I/O error). */
	public function dropWebMks(string $vmId): void
	{
		if (isset($this->webMksSessions[$vmId])) {
			$this->webMksSessions[$vmId]->close();
			unset($this->webMksSessions[$vmId]);
		}
	}

	/** Close all cached WebMKS sessions (shutdown hook). */
	public function closeConsoles(): void
	{
		foreach ($this->webMksSessions as $client) {
			$client->close();
		}
		$this->webMksSessions = [];
	}

	/** Summary for list_vcenter_instances (no credentials). */
	public function summary(): array
	{
		return [
			'url' => $this->url(),
			'description' => $this->description(),
			'username' => $this->username(),
			'netrc' => !empty($this->config['netrc']),
			'exclude_hosts' => $this->config['exclude_hosts'] ?? [],
			'defaults' => $this->defaults(),
		];
	}
}
