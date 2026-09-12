<?php

namespace EnchiladaMCP;

/* Enchilada Framework 3.0
 * MCP Tool & Resource Registry
 *
 * Reflection-based tool and resource discovery and invocation registry.
 * Automatically discovers methods marked with #[McpTool] or #[McpResource] attributes.
 *
 * Software License Agreement (BSD License)
 * 
 * Copyright (c) 2026, The Daniel Morante Company, Inc.
 * All rights reserved.
 */

class ToolRegistry
{
	/**
	 * Registered tools indexed by name.
	 *
	 * @var array<string,array{name:string,description:string,inputSchema:array,annotations?:array}>
	 */
	private array $tools = [];

	/**
	 * Tool handlers indexed by name.
	 *
	 * @var array<string,array{0:object,1:string}>
	 */
	private array $handlers = [];

	/**
	 * Rename history: former tool name => current tool name.
	 * Metadata only (not callable); feeds unknown-tool suggestions.
	 *
	 * @var array<string,string>
	 */
	private array $renamedFrom = [];

	/**
	 * Registered resource templates indexed by URI template.
	 *
	 * @var array<string,array{uriTemplate:string,name:string,description:string,mimeType:string}>
	 */
	private array $resourceTemplates = [];

	/**
	 * Resource handlers indexed by URI template.
	 *
	 * @var array<string,array{0:object,1:string}>
	 */
	private array $resourceHandlers = [];

	/**
	 * Register an object's methods marked with #[McpTool] or #[McpResource] attributes.
	 *
	 * @param object $handler Object containing tool/resource methods
	 */
	public function register(object $handler): void
	{
		$reflection = new \ReflectionClass($handler);

		foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
			// Check for resource attributes
			$resourceAttrs = $method->getAttributes(McpResource::class);
			if (!empty($resourceAttrs)) {
				$this->registerResource($handler, $method, $resourceAttrs[0]->newInstance());
			}

			// Check for tool attributes
			$attributes = $method->getAttributes(McpTool::class);

			if (empty($attributes)) {
				continue;
			}

			$attr = $attributes[0]->newInstance();
			$toolName = $attr->name ?? $method->getName();

			// Get description from attribute or docblock
			$description = $attr->description;
			if ($description === null) {
				$docComment = $method->getDocComment();
				if ($docComment) {
					preg_match('/\*\s+([^@\n]+)/', $docComment, $matches);
					$description = trim($matches[1] ?? '');
				}
			}

			// Build input schema from attribute or method parameters
			$inputSchema = $attr->inputSchema;
			if ($inputSchema === null) {
				$inputSchema = $this->buildSchemaFromMethod($method);
			}

			$tool = [
				'name' => $toolName,
				'description' => $description ?: "Tool: {$toolName}",
				'inputSchema' => $inputSchema,
			];

			// Advertised only when the tool declares one, so tools returning
			// plain text keep a byte-identical definition.
			if ($attr->outputSchema !== null) {
				$tool['outputSchema'] = $attr->outputSchema;
			}

			// Human-readable display title (MCP 2025-06-18+): client UIs
			// prefer it over the machine name. Advertised only when set.
			if ($attr->title !== null) {
				$tool['title'] = $attr->title;
			}

			$annotations = array_filter([
				'readOnlyHint' => $attr->readOnlyHint,
				'destructiveHint' => $attr->destructiveHint,
				'idempotentHint' => $attr->idempotentHint,
				'openWorldHint' => $attr->openWorldHint,
			], fn($v) => $v !== null);

			if (!empty($annotations)) {
				$tool['annotations'] = $annotations;
			}

			$this->tools[$toolName] = $tool;

			$this->handlers[$toolName] = [$handler, $method->getName()];

			if ($attr->renamedFrom !== null) {
				$this->renamedFrom[$attr->renamedFrom] = $toolName;
			}
		}
	}

	/**
	 * Build JSON Schema from method parameters using reflection.
	 *
	 * @param  ReflectionMethod $method Method to analyze
	 * @return array                    JSON Schema object
	 */
	private function buildSchemaFromMethod(\ReflectionMethod $method): array
	{
		$properties = [];
		$required = [];

		foreach ($method->getParameters() as $param) {
			$name = $param->getName();
			$type = $param->getType();

			$propSchema = ['type' => 'string']; // default

			if ($type !== null) {
				$typeName = $type->getName();
				$propSchema = match($typeName) {
					'int', 'integer' => ['type' => 'integer'],
					'float', 'double' => ['type' => 'number'],
					'bool', 'boolean' => ['type' => 'boolean'],
					'array' => ['type' => 'array'],
					'string' => ['type' => 'string'],
					default => ['type' => 'object'],
				};
			}

			$properties[$name] = $propSchema;

			if (!$param->isOptional()) {
				$required[] = $name;
			}
		}

		$schema = [
			'type' => 'object',
			'properties' => empty($properties) ? new \stdClass() : $properties,
		];
		if (!empty($required)) {
			$schema['required'] = $required;
		}
		return $schema;
	}

	/**
	 * List all registered tools in MCP protocol format.
	 *
	 * @return array<array{name:string,description:string,inputSchema:array,annotations?:array}>
	 */
	public function listTools(): array
	{
		return array_values($this->tools);
	}

	/**
	 * Call a tool by name with arguments.
	 *
	 * @param  string              $name      Tool name to call
	 * @param  array<string,mixed> $arguments Arguments for the tool
	 * @return mixed                          Tool execution result
	 * @throws \InvalidArgumentException      If tool not found or missing required argument
	 */
	public function callTool(string $name, array $arguments): mixed
	{
		if (!isset($this->handlers[$name])) {
			throw new \InvalidArgumentException("Unknown tool: {$name}");
		}

		[$handler, $methodName] = $this->handlers[$name];

		$method = new \ReflectionMethod($handler, $methodName);
		$params = [];

		foreach ($method->getParameters() as $param) {
			$paramName = $param->getName();
			if (isset($arguments[$paramName])) {
				$params[] = $arguments[$paramName];
			} elseif ($param->isOptional()) {
				$params[] = $param->getDefaultValue();
			} else {
				throw new \InvalidArgumentException("Missing required argument: {$paramName}");
			}
		}

		return $method->invokeArgs($handler, $params);
	}

	/**
	 * Check if a tool exists in the registry.
	 *
	 * @param  string $name Tool name to check
	 * @return bool         True if tool is registered
	 */
	public function hasTool(string $name): bool
	{
		return isset($this->handlers[$name]);
	}

	/**
	 * Suggest registered tool names closest to the given (unknown) name.
	 *
	 * Intended for "unknown tool" diagnostics: LLM clients occasionally
	 * hallucinate tool names (e.g. inventing a vendor prefix), and a
	 * suggestion list lets the caller self-correct on the next attempt.
	 *
	 * @param  string   $name Unknown tool name
	 * @param  int      $max  Maximum suggestions (default 3)
	 * @return string[]       Closest tool names, nearest first
	 */
	public function suggestTools(string $name, int $max = 3): array
	{
		// Exact rename history first: a caller using a pre-rename name should
		// always see the current name as the top suggestion.
		$known = [];
		if (isset($this->renamedFrom[$name])) {
			$known[] = $this->renamedFrom[$name];
		}

		$queryTokens = explode('_', strtolower($name));

		$scored = [];
		foreach (array_keys($this->handlers) as $candidate) {
			$overlap = 0;
			foreach (explode('_', strtolower($candidate)) as $candidateToken) {
				foreach ($queryTokens as $queryToken) {
					// Exact match, or prefix match for tokens long enough to be
					// meaningful (catches singular/plural: repo ~ repos)
					if (
						$candidateToken === $queryToken
						|| (strlen($queryToken) >= 4 && strlen($candidateToken) >= 4
							&& (str_starts_with($candidateToken, $queryToken) || str_starts_with($queryToken, $candidateToken)))
					) {
						$overlap++;
						break;
					}
				}
			}
			// Rank by token overlap (handles hallucinated vendor prefixes and
			// word-order swaps), then by edit distance as tiebreak
			$scored[$candidate] = [-$overlap, levenshtein(strtolower($name), strtolower($candidate))];
		}

		uasort($scored, fn($a, $b) => $a <=> $b);
		$ranked = array_keys($scored);
		return array_slice(array_values(array_unique(array_merge($known, $ranked))), 0, $max);
	}

	/**
	 * Register a single resource template from a method and attribute.
	 *
	 * @param object          $handler  Handler object
	 * @param \ReflectionMethod $method Method reflection
	 * @param McpResource     $attr     Resource attribute instance
	 */
	private function registerResource(object $handler, \ReflectionMethod $method, McpResource $attr): void
	{
		$uriTemplate = $attr->uriTemplate;
		$name = $attr->name ?? $method->getName();

		$description = $attr->description;
		if ($description === null) {
			$docComment = $method->getDocComment();
			if ($docComment) {
				preg_match('/\*\s+([^@\n]+)/', $docComment, $matches);
				$description = trim($matches[1] ?? '');
			}
		}

		$meta = [
			'uriTemplate' => $uriTemplate,
			'name' => $name,
			'description' => $description ?: "Resource: {$name}",
			'mimeType' => $attr->mimeType,
		];
		if ($attr->annotations !== null && ($clean = ToolResult::validateAnnotations($attr->annotations)) !== []) {
			$meta['annotations'] = $clean;
		}
		$this->resourceTemplates[$uriTemplate] = $meta;

		$this->resourceHandlers[$uriTemplate] = [$handler, $method->getName()];
	}

	/**
	 * List registered resource templates (URIs with {param} placeholders) in MCP protocol format.
	 *
	 * @return array<array{uriTemplate:string,name:string,description:string,mimeType:string}>
	 */
	public function listResourceTemplates(): array
	{
		return array_values(array_filter($this->resourceTemplates, function ($meta) {
			return str_contains($meta['uriTemplate'], '{');
		}));
	}

	/**
	 * List registered static resources (fixed URIs without placeholders) in MCP protocol format.
	 *
	 * @return array<array{uri:string,name:string,description:string,mimeType:string}>
	 */
	public function listResources(): array
	{
		$resources = [];
		foreach ($this->resourceTemplates as $meta) {
			if (!str_contains($meta['uriTemplate'], '{')) {
				$resources[] = array_filter([
					'uri' => $meta['uriTemplate'],
					'name' => $meta['name'],
					'description' => $meta['description'],
					'mimeType' => $meta['mimeType'],
					'annotations' => $meta['annotations'] ?? null,
				], fn($v) => $v !== null);
			}
		}
		return $resources;
	}

	/**
	 * Check if any resources (static or templated) are registered.
	 *
	 * @return bool True if at least one resource exists
	 */
	public function hasResources(): bool
	{
		return !empty($this->resourceTemplates);
	}

	/**
	 * Read a resource by URI, matching against registered templates.
	 *
	 * Extracts parameters from the URI by matching against templates
	 * and invokes the handler method with extracted values.
	 *
	 * @param  string $uri URI to resolve (e.g., "myapp://users/42")
	 * @return array       Resource content: {uri, mimeType, text}
	 * @throws \InvalidArgumentException If no template matches
	 */
	public function readResource(string $uri): array
	{
		foreach ($this->resourceTemplates as $template => $meta) {
			$params = $this->matchUriTemplate($template, $uri);
			if ($params !== null) {
				[$handler, $methodName] = $this->resourceHandlers[$template];
				$method = new \ReflectionMethod($handler, $methodName);

				// Map extracted params to method parameters by name
				$args = [];
				foreach ($method->getParameters() as $param) {
					$paramName = $param->getName();
					if (isset($params[$paramName])) {
						$args[] = $params[$paramName];
					} elseif ($param->isOptional()) {
						$args[] = $param->getDefaultValue();
					} else {
						throw new \InvalidArgumentException("Missing URI parameter: {$paramName}");
					}
				}

				$result = $method->invokeArgs($handler, $args);

				// Rich return: an array with a string 'text' AND an
				// 'annotations' array is treated as a full resource body so
				// handlers can attach per-read dynamic annotations
				// (e.g. lastModified of the thing actually read). Plain
				// strings and all other arrays keep the legacy behavior.
				if (is_array($result) && isset($result['annotations']) && is_string($result['text'] ?? null)) {
					$content = [
						'uri' => $uri,
						'mimeType' => $result['mimeType'] ?? $meta['mimeType'],
						'text' => $result['text'],
					];
					$annotations = ToolResult::validateAnnotations($result['annotations']) ?: ($meta['annotations'] ?? []);
					if ($annotations !== []) {
						$content['annotations'] = $annotations;
					}
					return $content;
				}

				$text = is_string($result) ? $result : json_encode($result, JSON_UNESCAPED_SLASHES);
				$content = [
					'uri' => $uri,
					'mimeType' => $meta['mimeType'],
					'text' => $text,
				];
				if (isset($meta['annotations'])) {
					$content['annotations'] = $meta['annotations'];
				}
				return $content;
			}
		}

		throw new \InvalidArgumentException("No resource template matches URI: {$uri}");
	}

	/**
	 * Match a URI against a template, extracting parameter values.
	 *
	 * Template: "myapp://users/{id}/repos/{repo}"
	 * URI:      "myapp://users/42/repos/hello"
	 * Returns:  ["id" => "42", "repo" => "hello"]
	 *
	 * @param  string     $template URI template with {param} placeholders
	 * @param  string     $uri      Actual URI to match
	 * @return array|null           Extracted params or null if no match
	 */
	private function matchUriTemplate(string $template, string $uri): ?array
	{
		// Build regex from template: quote literal parts, convert {param} to named groups
		$regex = '#^' . preg_replace_callback(
			'/\\\\\\{([^}]+)\\\\\\}/',
			function ($m) {
				return '(?P<' . $m[1] . '>[^/]+)';
			},
			preg_quote($template, '#')
		) . '$#';

		if (preg_match($regex, $uri, $matches)) {
			$params = [];
			foreach ($matches as $key => $value) {
				if (is_string($key)) {
					$params[$key] = $value;
				}
			}
			return $params;
		}

		return null;
	}
}
