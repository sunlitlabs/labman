<?php

namespace App\Http\Middleware;

use App\Support\Setup;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Until an admin exists every request goes to /setup. Afterwards /setup is gone
 * (404) for good. The container health route is always exempt.
 */
class RedirectToSetup
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('up')) {
            return $next($request);
        }

        $onSetup = $request->is('setup');

        if (! Setup::isComplete()) {
            return $onSetup ? $next($request) : redirect('/setup');
        }

        abort_if($onSetup, 404);

        return $next($request);
    }
}
