<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Who may see which screen. Backed by the per-screen visibility setting
 * (everyone / signed-in users / admins only). Used by the `screen` middleware,
 * the `view-screen` gate, the Blade navigation and the JSON status endpoint, so
 * every path to the data goes through the same rule.
 */
final class Access
{
    public static function allows(?User $user, string $screen): bool
    {
        return match (Settings::visibility($screen)) {
            'everyone' => true,
            'users' => $user?->isActive() === true,
            default => $user?->isActive() === true && $user->isAdmin(),
        };
    }

    public static function isAdmin(?User $user): bool
    {
        return $user !== null && $user->isActive() && $user->isAdmin();
    }

    /** Guests are sent to login (401 for JSON callers); signed-in users get 403. */
    public static function deny(Request $request): never
    {
        if ($request->user() === null) {
            if ($request->expectsJson()) {
                abort(401, 'Authentication required.');
            }

            throw new \Illuminate\Http\Exceptions\HttpResponseException(redirect()->guest(route('login')));
        }

        abort(403);
    }
}
