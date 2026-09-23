<?php

namespace EnchiladaMCP;

/* Enchilada Framework 3.0
 * MCP Completion Provider Attribute
 *
 * PHP 8 attribute for marking methods as completion providers for the
 * argument of a prompt or resource template (completion/complete).
 *
 * Handler signature: function(string $value, array $context): mixed
 *   $value    - the argument value typed so far
 *   $context  - already-resolved sibling arguments (params.context.arguments)
 * Return a list of suggestion strings, or the fuller shape
 * ['values' => [...], 'total' => N, 'hasMore' => bool].
 *
 * Software License Agreement (BSD License)
 *
 * Copyright (c) 2026, The Daniel Morante Company, Inc.
 * All rights reserved.
 */

#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class McpComplete
{
	/**
	 * Create a new McpComplete attribute instance.
	 *
	 * @param string $refType  'ref/prompt' or 'ref/resource'
	 * @param string $refName  Prompt name, or resource URI template
	 * @param string $argument The argument (or template placeholder) name
	 */
	public function __construct(
		public string $refType,
		public string $refName,
		public string $argument
	) {}
}
