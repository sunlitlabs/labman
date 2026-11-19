<?php

namespace App\Http\Middleware;

use App\Support\Access;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/** Usage: ->middleware('screen:servers'). Enforces the screen's configured visibility. */
class EnsureScreenAccess
{
    public function handle(Request $request, Closure $next, string $screen): Response
    {
        if (! Gate::allows('view-screen', $screen)) {
            Access::deny($request);
        }

        return $next($request);
    }
}
