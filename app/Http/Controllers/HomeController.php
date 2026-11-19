<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Models\Service;
use App\Monitoring\StatusView;
use Illuminate\Support\Facades\Gate;

class HomeController extends Controller
{
    /**
     * The dashboard for anyone who may see services or servers; otherwise the first screen they may see, else login.
     */
    public function __invoke()
    {
        $canServices = Gate::allows('view-screen', 'services');
        $canServers = Gate::allows('view-screen', 'servers');

        if ($canServices || $canServers) {
            $services = $canServices ? Service::query()->with('server')->orderBy('group')->orderBy('name')->get() : collect();
            $servers = $canServers ? Server::query()->orderBy('group')->orderBy('name')->get() : collect();

            return view('dashboard', [
                'canServices' => $canServices,
                'canServers' => $canServers,
                'serviceGroups' => $services->groupBy(fn (Service $s) => $s->group ?: ''),
                'serverGroups' => $servers->groupBy(fn (Server $s) => $s->group ?: ''),
                'summary' => [
                    'services' => StatusView::summary($services),
                    'servers' => StatusView::summary($servers),
                ],
                'empty' => $services->isEmpty() && $servers->isEmpty(),
            ]);
        }

        foreach (['settings', 'users'] as $screen) {
            if (Gate::allows('view-screen', $screen)) {
                return redirect()->route($screen.'.index');
            }
        }

        return auth()->check() ? abort(403) : redirect()->route('login');
    }
}
