<?php

namespace App\Node;

/**
 * LabMan runs in one of two modes. "main" is the full app. "node" (LABMAN_MODE=node) is a small read-only
 * dashboard for one network zone: no sign-in, no admin, no health checks of its own. The main LabMan pushes
 * what to show to it (see NodePush), authenticated by the shared LABMAN_NODE_TOKEN.
 */
final class Mode
{
    public static function isNode(): bool
    {
        return config('labman.mode') === 'node';
    }

    /** The token a main LabMan must present to push to this node (empty = pushing is refused). */
    public static function token(): string
    {
        return (string) config('labman.node_token', '');
    }
}
