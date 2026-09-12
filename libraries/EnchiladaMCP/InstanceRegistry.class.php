<?php

namespace EnchiladaMCP;

/* Enchilada Framework 3.0
 * MCP Multi-Instance Registry
 *
 * Generic base class for MCP servers that support multiple named backend
 * "instances" (accounts, orgs, sites, etc.) with a convenience "default"
 * used when a tool call omits an explicit instance name.
 *
 * This factors out the near-identical InstanceManager pattern that was
 * independently hand-written in ForgejoMCP, MailMCP, BookstackMCP, and
 * PhorgeMCP (config array -> named client cache -> default fallback).
 * New projects should extend this instead of re-deriving it; existing
 * projects can migrate opportunistically since callers already interact
 * with a similar interface.
 *
 * IMPORTANT — concurrency caveat:
 * The "default" instance is process-wide mutable state. For stdio
 * transport, a single process may in practice serve multiple concurrent
 * logical MCP client sessions (IDE hosts commonly share one server
 * process across several chat sessions in the same window). Tools built
 * on this registry MUST accept an explicit instance-name parameter for
 * every operation and only fall back to getDefault()/resolve(null) as a
 * convenience — never make a multi-step stateful workflow depend on the
 * default surviving unmolested across calls. If a workflow requires that
 * kind of continuity (e.g. a live external session/handle that must be
 * reused across many calls), it needs its own explicit session-id
 * parameter threaded by the caller — see SeleniumMCP\SessionManager for
 * a cautionary example of what happens when that isn't done.
 *
 * Usage:
 *   class ForgejoInstanceManager extends InstanceRegistry
 *   {
 *       protected function createClient(string $name, array $config): object
 *       {
 *           return new Client($config['url'], $config['token']);
 *       }
 *   }
 *
 * Software License Agreement (BSD License)
 *
 * Copyright (c) 2026, The Daniel Morante Company, Inc.
 * All rights reserved.
 */

abstract class InstanceRegistry
{
	/** @var array<string,array<string,mixed>> Instance name => config */
	protected array $instances;

	/** @var string Name of the current default instance. */
	protected string $default;

	/** @var array<string,object> Cached clients indexed by instance name. */
	private array $clients = [];

	/**
	 * @param array<string,array<string,mixed>> $instances Named instance configs
	 * @param string|null                       $default   Initial default instance name
	 *                                                      (falls back to first key if omitted)
	 */
	public function __construct(array $instances, ?string $default = null)
	{
		if (empty($instances)) {
			throw new \InvalidArgumentException('At least one instance must be configured');
		}

		$this->instances = $instances;
		$this->default = $default ?? array_key_first($instances);

		if (!isset($this->instances[$this->default])) {
			throw new \InvalidArgumentException("Default instance '{$this->default}' not found in configuration");
		}
	}

	/**
	 * Construct a backend client for a named instance. Implemented by subclasses.
	 *
	 * @param  string             $name   Instance name
	 * @param  array<string,mixed> $config Instance configuration
	 * @return object                     Backend client (Forgejo\Client, IMAP client, etc.)
	 */
	abstract protected function createClient(string $name, array $config): object;

	/**
	 * Get (and lazily create) the cached client for a named instance,
	 * or the default instance if $name is null.
	 *
	 * @param  string|null $name Instance name, or null for the default
	 * @return object            Cached backend client
	 * @throws \InvalidArgumentException If the named instance doesn't exist
	 */
	public function resolve(?string $name = null): object
	{
		$name = $name ?? $this->default;

		if (!isset($this->instances[$name])) {
			$available = implode(', ', array_keys($this->instances));
			throw new \InvalidArgumentException("Instance '{$name}' not found. Available: {$available}");
		}

		if (!isset($this->clients[$name])) {
			$this->clients[$name] = $this->createClient($name, $this->instances[$name]);
		}

		return $this->clients[$name];
	}

	/**
	 * Get raw configuration for a named instance (or the default).
	 *
	 * @param  string|null $name Instance name, or null for the default
	 * @return array<string,mixed>
	 */
	public function getConfig(?string $name = null): array
	{
		$name = $name ?? $this->default;

		if (!isset($this->instances[$name])) {
			throw new \InvalidArgumentException("Instance '{$name}' not found");
		}

		return $this->instances[$name];
	}

	/**
	 * List all configured instance names.
	 *
	 * @return string[]
	 */
	public function listNames(): array
	{
		return array_keys($this->instances);
	}

	/**
	 * Check if a named instance exists.
	 */
	public function hasInstance(string $name): bool
	{
		return isset($this->instances[$name]);
	}

	/**
	 * Get the name of the current default instance.
	 */
	public function getDefault(): string
	{
		return $this->default;
	}

	/**
	 * Change the default instance used when tool calls omit an explicit name.
	 *
	 * Note the concurrency caveat in the class docblock: this mutates
	 * process-wide state. Tools should treat this purely as a convenience
	 * for interactive single-session use, not as a substitute for passing
	 * an explicit instance parameter.
	 *
	 * @throws \InvalidArgumentException If the instance doesn't exist
	 */
	public function setDefault(string $name): void
	{
		if (!isset($this->instances[$name])) {
			$available = implode(', ', array_keys($this->instances));
			throw new \InvalidArgumentException("Instance '{$name}' not found. Available: {$available}");
		}

		$this->default = $name;
	}
}
