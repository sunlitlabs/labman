<?php

namespace App\Http\Middleware;

use App\Support\Access;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/** The admin panel's landing page: admins, and anyone allowed to see the Settings or Users screens. */
class EnsureAdminArea
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Gate::allows('view-admin')) {
            Access::deny($request);
        }

        return $next($request);
    }
}
