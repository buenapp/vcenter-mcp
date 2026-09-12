<?php

namespace EnchiladaMCP;

/* Enchilada Framework 3.0
 * MCP Resource Attribute
 *
 * PHP 8 attribute for marking methods as MCP resource handlers.
 * Methods marked with this attribute are automatically discovered and
 * registered as resource templates in the MCP protocol.
 *
 * Resource templates expose URI-addressable read-only data surfaces.
 * Each resource method receives the URI parameters extracted from
 * the template and returns the resource content.
 *
 * Software License Agreement (BSD License)
 * 
 * Copyright (c) 2026, The Daniel Morante Company, Inc.
 * All rights reserved.
 */

#[\Attribute(\Attribute::TARGET_METHOD)]
class McpResource
{
	/**
	 * Create a new McpResource attribute instance.
	 *
	 * @param string      $uriTemplate  URI template with {param} placeholders (e.g., "myapp://users/{id}")
	 * @param string|null $name         Resource name for display (defaults to method name)
	 * @param string|null $description  Description for clients (defaults to docblock)
	 * @param string      $mimeType     MIME type of resource content (default: application/json)
	 * @param array|null  $annotations  Static MCP annotations for this resource
	 *                                  (audience: 'user'/'assistant', priority: 0..1,
	 *                                  lastModified: ISO-8601). Surfaced in list output
	 *                                  and as the default on resources/read results;
	 *                                  a handler returning
	 *                                  ['text' => ..., 'annotations' => [...]] overrides
	 *                                  them with per-resource dynamic values.
	 *                                  Trailing so named and positional callers are
	 *                                  unaffected.
	 */
	public function __construct(
		public string $uriTemplate,
		public ?string $name = null,
		public ?string $description = null,
		public string $mimeType = 'application/json',
		public ?array $annotations = null
	) {}
}
