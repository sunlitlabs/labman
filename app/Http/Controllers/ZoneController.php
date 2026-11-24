<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Models\Service;
use App\Models\Zone;
use App\Rules\Check;
use App\Support\Domains;
use App\Support\Settings;
use App\Support\Zones;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Admin: the trust zones servers belong to. */
class ZoneController extends Controller
{
    public function index()
    {
        return view('zones.index', [
            'zones' => Zones::all(),
            'serverCounts' => Server::query()->selectRaw('zone_id, count(*) as n')->groupBy('zone_id')->pluck('n', 'zone_id'),
            'serviceCounts' => Service::query()->whereNotNull('zone_id')->selectRaw('zone_id, count(*) as n')->groupBy('zone_id')->pluck('n', 'zone_id'),
        ]);
    }

    public function create()
    {
        return view('zones.form', ['zone' => new Zone(['level' => 50])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $zone = DB::transaction(function () use ($request) {
            [$data, $default] = $this->data($request);
            $zone = Zone::create($data + ['is_default' => false]);
            $default && $this->makeDefault($zone);

            return $zone;
        });

        return redirect()->route('zones.index')->with('status', "Zone {$zone->name} added.");
    }

    public function edit(Zone $zone)
    {
        return view('zones.form', ['zone' => $zone]);
    }

    public function update(Request $request, Zone $zone): RedirectResponse
    {
        DB::transaction(function () use ($request, $zone) {
            [$data, $default] = $this->data($request, $zone);
            $zone->update($data);
            $default && $this->makeDefault($zone);
        });

        return redirect()->route('zones.index')->with('status', "Zone {$zone->name} updated.");
    }

    public function destroy(Zone $zone): RedirectResponse
    {
        if ($zone->is_default) {
            return back()->withErrors(['zone' => 'The default zone cannot be deleted. Make another zone the default first.']);
        }

        $servers = $zone->servers()->count();
        if ($servers > 0) {
            return back()->withErrors(['zone' => "{$zone->name} still has {$servers} server(s). Move them to another zone first."]);
        }

        Service::query()->where('zone_id', $zone->id)->update(['zone_id' => null]);
        $zone->delete();

        return redirect()->route('zones.index')->with('status', "Zone {$zone->name} deleted.");
    }

    /** @return array{0: array<string, mixed>, 1: bool} the stored fields, and whether this zone becomes the default */
    private function data(Request $request, ?Zone $zone = null): array
    {
        $v = $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('zones', 'name')->ignore($zone)],
            'level' => ['required', 'integer', 'between:0,100'],
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'icon' => ['nullable', 'string', 'max:80', Check::icon()],
            'cidrs' => ['nullable', 'string', 'max:1000'],
            'local_domains' => ['nullable', Check::domainList()],
            'public_domains' => ['nullable', Check::domainList()],
            'proxy_address' => ['nullable', 'string', 'max:253', Check::hostOrIp()],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $cidrs = Zones::parseCidrs($v['cidrs'] ?? '');
        foreach ($cidrs as $cidr) {
            if (! Zones::validCidr($cidr)) {
                throw ValidationException::withMessages(['cidrs' => "'{$cidr}' is not a network block like 192.0.2.0/24."]);
            }
        }

        $data = [
            'name' => trim($v['name']),
            'level' => (int) $v['level'],
            'color' => filled($v['color'] ?? null) ? strtolower($v['color']) : null,
            'icon' => filled($v['icon'] ?? null) ? strtolower($v['icon']) : null,
            'cidrs' => $cidrs ?: null,
            'proxy_address' => filled($v['proxy_address'] ?? null) ? trim($v['proxy_address']) : null,
            'notes' => $v['notes'] ?? null,
        ];

        // The default zone's domains are the two lists on the Nginx Proxy Manager page; only other zones keep their own.
        if (! $zone?->is_default) {
            $local = Domains::parse($v['local_domains'] ?? '');
            $public = Domains::parse($v['public_domains'] ?? '');
            $data['domains'] = $local || $public ? ['local' => $local, 'public' => $public] : null;
        }

        return [$data, $request->boolean('is_default')];
    }

    /**
     * Only the default zone reads its domains from the settings, so handing the flag over swaps them: the old
     * default keeps what the settings held, and the new default's own domains become the settings.
     */
    private function makeDefault(Zone $zone): void
    {
        if ($zone->is_default) {
            return;
        }

        $old = Zones::default();
        if ($old && $old->id !== $zone->id) {
            $old->forceFill(['is_default' => false, 'domains' => array_filter($old->domains()) ?: null])->save();
        }

        $own = $zone->domains();
        Settings::set(['local_domains' => implode(', ', $own['local']), 'public_domains' => implode(', ', $own['public'])]);
        $zone->forceFill(['is_default' => true, 'domains' => null])->save();
    }
}
