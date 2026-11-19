<?php

namespace App\Http\Middleware;

use App\Support\Access;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/** Editing is always admin-only, whatever a screen's read visibility is. */
class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Gate::allows('admin')) {
            Access::deny($request);
        }

        return $next($request);
    }
}
