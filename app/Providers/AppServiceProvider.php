<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Access;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // May this viewer (possibly a guest) see this screen? Backed by the per-screen visibility setting.
        Gate::define('view-screen', fn (?User $user, string $screen) => Access::allows($user, $screen));

        // 120 MCP calls a minute per token (or per IP for unauthenticated attempts).
        RateLimiter::for('mcp', fn (Request $request) => Limit::perMinute(120)->by(($request->bearerToken() ? 't'.substr(hash('sha256', $request->bearerToken()), 0, 16) : 'ip').'|'.$request->ip()));

        // Editing anything is admin-only.
        Gate::define('admin', fn (?User $user) => Access::isAdmin($user));
    }
}
