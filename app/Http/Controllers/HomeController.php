<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Gate;

class HomeController extends Controller
{
    /** Send the visitor to the first screen they may see, else to login. */
    public function __invoke()
    {
        foreach (['services', 'servers', 'settings', 'users'] as $screen) {
            if (Gate::allows('view-screen', $screen)) {
                return redirect()->route($screen.'.index');
            }
        }

        return auth()->check() ? abort(403) : redirect()->route('login');
    }
}
