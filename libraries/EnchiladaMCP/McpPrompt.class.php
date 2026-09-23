<?php

namespace EnchiladaMCP;

/* Enchilada Framework 3.0
 * MCP Prompt Attribute
 *
 * PHP 8 attribute for marking methods as MCP prompt handlers.
 * Methods marked with this attribute are automatically discovered and
 * registered as prompt templates in the MCP protocol (prompts/list,
 * prompts/get).
 *
 * A prompt handler returns the message list for prompts/get:
 *   [['role' => 'user', 'content' => ['type' => 'text', 'text' => ...]], ...]
 * Resource links and embedded resources use the same content shapes as
 * tool results (see the 2026-07-28 spec, server/prompts Data Types).
 *
 * Software License Agreement (BSD License)
 *
 * Copyright (c) 2026, The Daniel Morante Company, Inc.
 * All rights reserved.
 */

#[\Attribute(\Attribute::TARGET_METHOD)]
class McpPrompt
{
	/**
	 * Create a new McpPrompt attribute instance.
	 *
	 * @param string      $name        Prompt name (snake_case, unique per server)
	 * @param string|null $title       Human-readable display name
	 * @param string|null $description Description for clients (defaults to docblock)
	 * @param array       $arguments   Argument metadata:
	 *                                 [['name' => ..., 'description' => ?, 'required' => ?], ...]
	 */
	public function __construct(
		public string $name,
		public ?string $title = null,
		public ?string $description = null,
		public array $arguments = []
	) {}
}
