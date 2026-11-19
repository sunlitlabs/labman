<?php

namespace App\Mcp;

use RuntimeException;

/** A problem the AI client should see as a tool error (not found, bad state, ...), not a protocol error. */
final class ToolException extends RuntimeException {}
