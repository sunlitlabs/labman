<?php

namespace App\Mcp;

use RuntimeException;

/** A JSON-RPC level failure (unknown method, bad params), as opposed to a tool error. */
final class McpProtocolError extends RuntimeException
{
    public function __construct(public readonly int $rpcCode, string $message)
    {
        parent::__construct($message);
    }
}
