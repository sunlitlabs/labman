<?php

namespace App\Http\Middleware;

use App\Node\Mode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A node shows the dashboard and nothing else: every other page is a 404, so there is no sign-in or admin to attack. */
class NodeModeOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Mode::isNode() && ! $request->is('/', 'status', 'up', 'node/push', 'manifest.webmanifest', 'icons/*')) {
            abort(404);
        }

        return $next($request);
    }
}
