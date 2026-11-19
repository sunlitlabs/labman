<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Access;
use Illuminate\Support\Facades\Gate;
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

        // Editing anything is admin-only.
        Gate::define('admin', fn (?User $user) => Access::isAdmin($user));
    }
}
