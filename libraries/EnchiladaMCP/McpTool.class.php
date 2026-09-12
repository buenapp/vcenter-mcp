<?php

namespace EnchiladaMCP;

/* Enchilada Framework 3.0
 * MCP Tool Attribute
 *
 * PHP 8 attribute for marking methods as MCP tools.
 * Methods marked with this attribute are automatically discovered and
 * registered as callable tools in the MCP protocol.
 *
 * Software License Agreement (BSD License)
 * 
 * Copyright (c) 2026, The Daniel Morante Company, Inc.
 * All rights reserved.
 */

#[\Attribute(\Attribute::TARGET_METHOD)]
class McpTool
{
	/**
	 * Create a new McpTool attribute instance.
	 *
	 * @param string|null $name            Tool name (defaults to method name if null)
	 * @param string|null $description     Tool description for clients (defaults to docblock)
	 * @param array|null  $inputSchema     JSON Schema for tool parameters (auto-generated if null)
	 * @param bool|null   $readOnlyHint    If true, tool does not modify its environment
	 * @param bool|null   $destructiveHint If true, tool may perform destructive updates (only meaningful when readOnlyHint is not true)
	 * @param bool|null   $idempotentHint  If true, repeated calls with the same arguments have no additional effect
	 * @param bool|null   $openWorldHint   If true, tool interacts with external/unbounded entities outside a closed system
	 * @param string|null $renamedFrom     Former tool name if this tool was renamed. Not callable —
	 *                                     metadata only; used to improve unknown-tool suggestions.
	 * @param array|null  $outputSchema    JSON Schema for `structuredContent` (MCP 2025-06-18). When
	 *                                     set, the tool advertises that it returns structured output
	 *                                     and clients may validate against it. Declared last so that
	 *                                     existing positional construction is unaffected.
	 * @param string|null $title           Human-readable display title (MCP 2025-06-18+). Shown by
	 *                                     client UIs alongside or instead of the tool name
	 *                                     ("Recall memories" vs `recall`). Appended after all
	 *                                     earlier parameters so positional construction is
	 *                                     unaffected.
	 *
	 * Annotation hints follow the MCP specification's tool annotations. They are
	 * advisory only — clients may use them to inform UX decisions (e.g. confirmation
	 * prompts, tool filtering) but must not treat them as a security boundary.
	 */
	public function __construct(
		public ?string $name = null,
		public ?string $description = null,
		public ?array $inputSchema = null,
		public ?bool $readOnlyHint = null,
		public ?bool $destructiveHint = null,
		public ?bool $idempotentHint = null,
		public ?bool $openWorldHint = null,
		public ?string $renamedFrom = null,
		public ?array $outputSchema = null,
		public ?string $title = null
	) {}
}
