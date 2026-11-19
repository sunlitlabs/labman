<?php

use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureScreenAccess;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\RedirectToSetup;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind a reverse proxy, set LABMAN_TRUSTED_PROXIES (comma separated IPs/CIDRs, or *).
        if ($proxies = env('LABMAN_TRUSTED_PROXIES')) {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }

        $middleware->web(prepend: [RedirectToSetup::class], append: [EnsureUserIsActive::class, SecurityHeaders::class]);

        $middleware->alias([
            'screen' => EnsureScreenAccess::class,
            'admin' => EnsureAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
