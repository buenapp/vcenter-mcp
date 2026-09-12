<?php

namespace EnchiladaMCP;

/* Enchilada Framework 3.0
 * MCP Tool Result Value Object
 *
 * Typed return value for MCP tool methods. Represents the content
 * array in a tools/call response per the MCP specification.
 *
 * Tools may return ToolResult for explicit content typing (image,
 * binary, mixed content) or continue returning plain values which
 * are auto-wrapped as text by the McpServer.
 *
 * Software License Agreement (BSD License)
 *
 * Copyright (c) 2026, The Daniel Morante Company, Inc.
 * All rights reserved.
 *
 * Redistribution and use of this software in source and binary forms,
 * with or without modification, are permitted provided that the following
 * conditions are met:
 *
 *   Redistributions of source code must retain the above copyright notice,
 *   this list of conditions and the following disclaimer.
 *
 *   Redistributions in binary form must reproduce the above copyright notice,
 *   this list of conditions and the following disclaimer in the documentation
 *   and/or other materials provided with the distribution.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
 * ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE
 * LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
 * CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 */

class ToolResult
{
	/** @var array<int, array<string, mixed>> Content blocks */
	private array $content;

	/** @var bool Whether this result represents an error */
	private bool $isError;

	/** @var array<string,mixed>|null Structured tool output (MCP 2025-06-18) */
	private ?array $structuredContent = null;

	/**
	 * @param array<int, array<string, mixed>> $content MCP content blocks
	 * @param bool $isError Whether this is an error result
	 */
	private function __construct(array $content, bool $isError = false)
	{
		$this->content = $content;
		$this->isError = $isError;
	}

	/**
	 * Create a text result.
	 *
	 * @param string $text Text content
	 */
	public static function text(string $text): self
	{
		return new self([['type' => 'text', 'text' => $text]]);
	}

	/**
	 * Create an image result.
	 *
	 * @param string $data Base64-encoded image data
	 * @param string $mimeType Image MIME type (default: image/png)
	 */
	public static function image(string $data, string $mimeType = 'image/png'): self
	{
		return new self([['type' => 'image', 'data' => $data, 'mimeType' => $mimeType]]);
	}

	/**
	 * Create an error result.
	 *
	 * @param string $message Error message
	 */
	public static function error(string $message): self
	{
		return new self([['type' => 'text', 'text' => $message]], true);
	}

	/**
	 * Create a text result that also carries a structured form of itself.
	 *
	 * Per the MCP specification a server returning `structuredContent` MUST
	 * also return backwards-compatible `content`, so the text rendering is a
	 * required argument rather than an optional one. Clients that predate
	 * structured output -- or that feed tool results straight to a model --
	 * keep working unchanged and never see the extra field.
	 *
	 * @param string              $text Human/LLM-readable rendering
	 * @param array<string,mixed> $data Structured form of the same result
	 */
	public static function structured(string $text, array $data): self
	{
		$result = new self([['type' => 'text', 'text' => $text]]);
		$result->structuredContent = $data;
		return $result;
	}

	/**
	 * Attach structured output to an existing result.
	 *
	 * @param  array<string,mixed> $data Structured form of this result
	 * @return self                      Fluent interface
	 */
	public function withStructuredContent(array $data): self
	{
		$this->structuredContent = $data;
		return $this;
	}

	/**
	 * Create a result with multiple content blocks (mixed types).
	 *
	 * @param array<int, array<string, mixed>> $contentBlocks Array of content blocks
	 * @param bool $isError Whether this is an error result
	 */
	public static function mixed(array $contentBlocks, bool $isError = false): self
	{
		return new self($contentBlocks, $isError);
	}

	/**
	 * Append content blocks to an existing result.
	 *
	 * Lets a result built by text()/structured() gain pointers
	 * (resource_link) or embedded resources after construction — the
	 * primary text block stays first for clients that read only the text.
	 *
	 * @param  array<string,mixed> ...$blocks MCP content blocks (build with
	 *                                        resourceLink()/embeddedResource())
	 * @return self Fluent interface
	 */
	public function withContent(array ...$blocks): self
	{
		foreach ($blocks as $block) {
			$this->content[] = $block;
		}
		return $this;
	}

	/**
	 * Build a resource_link content block (MCP 2025-11-25+).
	 *
	 * A resource_link is a pointer the client can resources/read (or
	 * surface as a clickable artifact) without the model spending a turn
	 * on a tool call. Links are not required to appear in resources/list.
	 *
	 * @param  string              $uri         Resource URI (e.g.
	 *                                          myapp://document/{title})
	 * @param  string              $name        Human-readable display name
	 * @param  string|null         $description Short prose description
	 * @param  string|null         $mimeType    MIME type the resource
	 *                                          returns when read
	 * @param  array<string,mixed> $annotations audience ('user'/'assistant'),
	 *                                          priority (0..1), lastModified
	 *                                          (ISO-8601); validated
	 * @return array<string,mixed>              A content block for mixed()/withContent()
	 * @throws \InvalidArgumentException        On bad audience or priority
	 */
	public static function resourceLink(string $uri, string $name, ?string $description = null, ?string $mimeType = null, array $annotations = []): array
	{
		$block = [
			'type' => 'resource_link',
			'uri' => $uri,
			'name' => $name,
		];
		if ($description !== null) {
			$block['description'] = $description;
		}
		if ($mimeType !== null) {
			$block['mimeType'] = $mimeType;
		}
		$annotations = self::validateAnnotations($annotations);
		if (!empty($annotations)) {
			$block['annotations'] = $annotations;
		}
		return $block;
	}

	/**
	 * Build an embedded resource content block (MCP 2025-11-25+).
	 *
	 * The resource body is inlined with its identity (uri, mimeType) so
	 * clients that understand resources can render typed content instead
	 * of raw text. Binary bodies are not supported here — callers needing
	 * blob payloads can add them through mixed().
	 *
	 * @param  string              $uri         Resource URI
	 * @param  string              $mimeType    Body MIME type
	 * @param  string              $text        Resource body
	 * @param  array<string,mixed> $annotations audience/priority/lastModified; validated
	 * @return array<string,mixed>              A content block for mixed()/withContent()
	 * @throws \InvalidArgumentException        On bad audience or priority
	 */
	public static function embeddedResource(string $uri, string $mimeType, string $text, array $annotations = []): array
	{
		$resource = [
			'uri' => $uri,
			'mimeType' => $mimeType,
			'text' => $text,
		];
		$annotations = self::validateAnnotations($annotations);
		if (!empty($annotations)) {
			$resource['annotations'] = $annotations;
		}
		return [
			'type' => 'resource',
			'resource' => $resource,
		];
	}

	/**
	 * Validate content-block annotations, keeping only spec keys.
	 *
	 * @param  array<string,mixed> $annotations
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException
	 */
	public static function validateAnnotations(array $annotations): array
	{
		$clean = [];
		if (isset($annotations['audience'])) {
			$audience = array_values($annotations['audience']);
			foreach ($audience as $role) {
				if (!in_array($role, ['user', 'assistant'], true)) {
					throw new \InvalidArgumentException("annotations.audience must contain only 'user' and/or 'assistant'");
				}
			}
			$clean['audience'] = $audience;
		}
		if (isset($annotations['priority'])) {
			$priority = $annotations['priority'];
			if (!is_int($priority) && !is_float($priority)) {
				throw new \InvalidArgumentException('annotations.priority must be a number between 0 and 1');
			}
			if ($priority < 0.0 || $priority > 1.0) {
				throw new \InvalidArgumentException('annotations.priority must be between 0 and 1');
			}
			$clean['priority'] = $priority;
		}
		if (isset($annotations['lastModified'])) {
			if (!is_string($annotations['lastModified'])) {
				throw new \InvalidArgumentException('annotations.lastModified must be an ISO-8601 string');
			}
			$clean['lastModified'] = $annotations['lastModified'];
		}
		return $clean;
	}

	/**
	 * Get the MCP-formatted response array for tools/call result.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		$result = ['content' => $this->content];

		if ($this->structuredContent !== null) {
			$result['structuredContent'] = $this->structuredContent;
		}

		if ($this->isError) {
			$result['isError'] = true;
		}

		return $result;
	}

	/**
	 * Get the content blocks.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getContent(): array
	{
		return $this->content;
	}

	/**
	 * Get the structured output, or null if this result carries none.
	 *
	 * @return array<string,mixed>|null
	 */
	public function getStructuredContent(): ?array
	{
		return $this->structuredContent;
	}

	/**
	 * Check if this is an error result.
	 */
	public function isError(): bool
	{
		return $this->isError;
	}
}
