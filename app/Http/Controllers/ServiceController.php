<?php

namespace App\Http\Controllers;

use App\Domain\ServiceInput;
use App\Http\Requests\ServiceRequest;
use App\Models\Server;
use App\Models\Service;
use App\Monitoring\Diagnoser;
use App\Monitoring\StatusView;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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
            'service' => new Service(['scheme' => 'http', 'path' => '/', 'expect' => '200-499', 'verify_tls' => true, 'public_check' => true, 'monitor' => true, 'check_tcp' => true]),
            'servers' => Server::orderBy('name')->get(['id', 'name', 'address', 'zone_id']),
            'workbooks' => \App\Semaphore\Targets::workbooks(),
        ]);
    }

    public function store(ServiceRequest $request): RedirectResponse
    {
        Service::create($request->payload());

        return redirect()->route('services.index')->with('status', 'Service added. Its health appears after the next check round.');
    }

    /** Start this service's update workbook: opens the run form aimed at its server, to be reviewed and confirmed like any run. */
    public function runUpdate(Service $service): RedirectResponse
    {
        if (! $service->hasUpdate()) {
            return redirect()->route('services.edit', $service)->withErrors(['update_workbook' => 'Choose which workbook updates this service first.']);
        }

        $query = ['project' => $service->update_project, 'template' => $service->update_template, 'message' => 'Update '.$service->name];
        if ($service->server) {
            $query['hosts'] = [\App\Semaphore\Targets::hostFor($service->server)];
        }

        return redirect()->route('semaphore.run', $query)->with('status', $service->server ? null : 'This service is not linked to a server, so choose where the update runs.');
    }

    public function edit(Service $service)
    {
        return view('services.form', [
            'service' => $service,
            'servers' => Server::orderBy('name')->get(['id', 'name', 'address', 'zone_id']),
            'workbooks' => \App\Semaphore\Targets::workbooks(),
        ]);
    }

    public function update(ServiceRequest $request, Service $service): RedirectResponse
    {
        $service->update($request->payload());

        return redirect()->route('services.index')->with('status', 'Service updated.');
    }

    /** Probe the service several ways and show what would make its health check pass. */
    public function diagnose(Service $service)
    {
        @set_time_limit(120);
        $service->load('server');

        return view('services.diagnose', [
            'service' => $service,
            'result' => (new Diagnoser)->run($service, Settings::timeout()),
        ]);
    }

    /** Save the recommended settings (re-validated like any other edit). */
    public function applyDiagnosis(Request $request, Service $service): RedirectResponse
    {
        $allowed = array_flip(['scheme', 'port', 'health_path', 'expect', 'check_tcp']);
        $changes = array_intersect_key((array) $request->input('changes', []), $allowed);

        if ($changes === []) {
            return redirect()->route('services.edit', $service)->with('status', 'Nothing to change.');
        }

        $service->update(ServiceInput::validate($changes, $service));

        return redirect()->route('services.edit', $service)->with('status', 'Health check settings updated. The result appears after the next check round.');
    }

    public function destroy(Service $service): RedirectResponse
    {
        $service->delete();

        return redirect()->route('services.index')->with('status', 'Service deleted.');
    }
}
