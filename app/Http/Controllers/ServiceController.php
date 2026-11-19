<?php

namespace App\Http\Controllers;

use App\Http\Requests\ServiceRequest;
use App\Models\Server;
use App\Models\Service;
use App\Monitoring\StatusView;
use Illuminate\Http\RedirectResponse;

class ServiceController extends Controller
{
    public function index()
    {
        $services = Service::query()->with('server')->orderBy('group')->orderBy('name')->get();

        return view('services.index', [
            'services' => $services,
            'grouped' => $services->groupBy(fn (Service $s) => $s->group ?: ''),
            'summary' => StatusView::summary($services),
        ]);
    }

    public function create()
    {
        return view('services.form', [
            'service' => new Service(['scheme' => 'http', 'path' => '/', 'expect' => '200-399', 'verify_tls' => true, 'public_check' => true]),
            'servers' => Server::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(ServiceRequest $request): RedirectResponse
    {
        Service::create($request->payload());

        return redirect()->route('services.index')->with('status', 'Service added. Its health appears after the next check round.');
    }

    public function edit(Service $service)
    {
        return view('services.form', [
            'service' => $service,
            'servers' => Server::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(ServiceRequest $request, Service $service): RedirectResponse
    {
        $service->update($request->payload());

        return redirect()->route('services.index')->with('status', 'Service updated.');
    }

    public function destroy(Service $service): RedirectResponse
    {
        $service->delete();

        return redirect()->route('services.index')->with('status', 'Service deleted.');
    }
}
