<?php

namespace EnchiladaMCP;

/* Enchilada Framework 3.0
 * MCP Tool Warning Marker Interface
 *
 * Marker interface for exceptions that should be returned to the MCP
 * client as a NORMAL (non-error) tool result instead of an error.
 *
 * Use for conditions where the outcome is uncertain but not a failure
 * of the tool itself — e.g. an upstream request timed out and the
 * server may still have completed the operation. The exception message
 * becomes the tool's text result, so it must explain the situation and
 * any recommended verification steps.
 *
 * Software License Agreement (BSD License)
 *
 * Copyright (c) 2026, The Daniel Morante Company, Inc.
 * All rights reserved.
 */

interface ToolWarningInterface extends \Throwable
{
}
