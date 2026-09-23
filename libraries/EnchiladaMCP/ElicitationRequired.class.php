<?php

namespace EnchiladaMCP;

/* Enchilada Framework 3.0
 * Elicitation Required Exception
 *
 * Thrown by tool handlers that need user confirmation mid-call
 * (2026-07-28 Multi Round-Trip Requests). McpServer converts it to an
 * InputRequiredResult carrying an elicitation/create request when the
 * modern client declared the elicitation capability; otherwise the call
 * resolves to a tool-level error explaining the direct-argument escape
 * hatch (tools receiving the answer object already filled in).
 *
 * On retry, inputResponses[<key>] is passed through to the tool as the
 * argument named <key>: the full {action, content} result. Callers use
 * answer() to reduce it.
 *
 * Software License Agreement (BSD License)
 *
 * Copyright (c) 2026, The Daniel Morante Company, Inc.
 * All rights reserved.
 */

class ElicitationRequired extends \RuntimeException
{
	/**
	 * @param string $key     inputRequests/inputResponses key; also the tool
	 *                        argument name the answer is delivered under
	 * @param string $message The confirmation question shown to the user
	 * @param array  $schema  requestedSchema for the form (object schema);
	 *                        answer() reads content.approve by convention
	 */
	public function __construct(
		public readonly string $key,
		string $message,
		public readonly array $schema
	) {
		parent::__construct($message);
	}

	/**
	 * Reduce an elicitation answer to a decision.
	 *
	 * @param  array|null $confirmation The {action, content} answer object,
	 *                                  null when not yet asked
	 * @return bool|null                null = not answered (elicit);
	 *                                  true = accepted (approve: true);
	 *                                  false = declined/cancelled/refused
	 */
	public static function answer(?array $confirmation): ?bool
	{
		if ($confirmation === null) {
			return null;
		}
		if (($confirmation['action'] ?? '') !== 'accept') {
			return false;
		}
		return (bool)(($confirmation['content']['approve'] ?? false));
	}
}
