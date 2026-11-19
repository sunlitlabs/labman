<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Models\Service;
use App\Monitoring\StatusView;
use App\Support\Access;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The small JSON endpoint the pages poll. Each section is included only if the
 * viewer may see that screen; a viewer who may see neither is refused exactly
 * like the pages themselves (guest: login / 401, signed in: 403).
 */
class StatusController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $canServices = Gate::allows('view-screen', 'services');
        $canServers = Gate::allows('view-screen', 'servers');

        if (! $canServices && ! $canServers) {
            Access::deny($request);
        }

        $payload = ['interval' => Settings::interval(), 'now' => now()->toIso8601String()];

        if ($canServices) {
            $services = Service::all();
            $payload['services'] = $services->map(fn ($s) => StatusView::of($s))->values();
            $payload['summary']['services'] = StatusView::summary($services);
        }

        if ($canServers) {
            $servers = Server::all();
            $payload['servers'] = $servers->map(fn ($s) => StatusView::of($s))->values();
            $payload['summary']['servers'] = StatusView::summary($servers);
        }

        return response()->json($payload)->header('Cache-Control', 'no-store');
    }
}
