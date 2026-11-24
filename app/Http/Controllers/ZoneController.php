<?php

namespace App\Http\Controllers;

use App\Models\ProxyLog;
use App\Models\Server;
use App\Models\Service;
use App\Models\Zone;
use App\Npm\CertificatePolicy;
use App\Npm\Connection;
use App\Npm\Mirrors;
use App\Npm\NpmClient;
use App\Rules\Check;
use App\Support\Domains;
use App\Support\Settings;
use App\Support\ZoneStatus;
use App\Support\Zones;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Admin: the trust zones servers belong to. */
class ZoneController extends Controller
{
    /** One card per zone, most trusted first, from the last remembered proxy status (Refresh asks the proxies again). */
    public function index()
    {
        $cards = Zones::all()->map(fn (Zone $z) => $this->card($z))->all();

        return view('zones.index', ['cards' => $cards, 'multiple' => count($cards) > 1]);
    }

    /** One zone with its servers, services, issues (each with its fix) and the log of what was applied to its proxy. */
    public function show(Zone $zone)
    {
        $servers = Zones::serversIn($zone)->orderBy('name')->get();
        $others = Zones::all()->reject(fn (Zone $z) => $z->id === $zone->id);

        return view('zones.show', [
            'card' => $this->card($zone),
            'servers' => $servers,
            'suggestions' => $servers->mapWithKeys(fn (Server $s) => [$s->id => ($z = Zones::suggestFor($s->address)) && $z->id !== $zone->id ? $z : null])->filter(),
            'services' => Zones::servicesIn($zone)->with('server')->orderBy('name')->get(),
            'others' => $others,
            'mirrorable' => $others->filter(fn (Zone $o) => ! isset(Mirrors::sourcesOf($o)[$zone->id]))->values(),
            'log' => ProxyLog::query()->where('zone_id', $zone->id)->orderByDesc('id')->limit(10)->get(),
        ]);
    }

    /** Put a server in this zone (the inline fix for "assign this server to a zone"). */
    public function assign(Request $request): RedirectResponse
    {
        $v = $request->validate(['server_id' => ['required', 'integer', 'exists:servers,id'], 'zone_id' => ['required', 'integer', 'exists:zones,id']]);
        $server = Server::findOrFail($v['server_id']);
        $zone = Zone::findOrFail($v['zone_id']);
        $server->update(['zone_id' => $zone->id]);

        return back()->with('status', "{$server->name} is now in the {$zone->name} zone.");
    }

    /** Ask the proxies for their status again: every zone, or the one given. */
    public function refresh(?Zone $zone = null): RedirectResponse
    {
        foreach ($zone ? [$zone] : Zones::all() as $z) {
            ZoneStatus::refresh($z);
        }

        return back()->with('status', $zone ? "Checked the {$zone->name} proxy." : 'Checked every proxy.');
    }

    /**
     * Everything the overview shows for a zone.
     *
     * @return array<string, mixed>
     */
    private function card(Zone $zone): array
    {
        $status = ZoneStatus::cached($zone);
        $connected = Connection::exists($zone);
        $issues = [];

        if (! $connected) {
            $issues[] = ['text' => 'This zone has no proxy yet.', 'label' => 'Connect one', 'url' => route('npm.index', ['zone' => $zone->id]).'#connect'];
        } elseif ($status && $status['state'] === 'error') {
            $issues[] = ['text' => 'The proxy could not be reached: '.$status['message'], 'label' => 'Check the connection', 'url' => route('npm.index', ['zone' => $zone->id])];
        } elseif ($status && $status['state'] === 'ok') {
            if ($status['cert_problems'] > 0) {
                $issues[] = ['text' => $status['cert_problems'].' host name(s) have a certificate that is missing or does not work.', 'label' => 'See the certificates', 'url' => route('npm.index', ['zone' => $zone->id]).'#certificates'];
            }
            if ($status['to_push'] > 0) {
                $issues[] = ['text' => $status['to_push'].' change(s) are waiting to be sent to the proxy.', 'label' => 'Preview the push', 'url' => route('npm.push', ['zone' => $zone->id])];
            }
            if ($status['unknown_to_labman'] > 0) {
                $issues[] = ['text' => $status['unknown_to_labman'].' proxy host(s) exist that LabMan does not know.', 'label' => 'Preview the import', 'url' => route('npm.import', ['zone' => $zone->id])];
            }
        }
        if ($zone->domainNames() === []) {
            $issues[] = ['text' => 'No domain is set for this zone, so its hosts cannot get names.', 'label' => 'Set a domain', 'url' => route('zones.edit', $zone)];
        }

        return [
            'zone' => $zone, 'connected' => $connected, 'status' => $status, 'issues' => $issues,
            'servers' => Zones::serversIn($zone)->count(), 'services' => Zones::servicesIn($zone)->count(),
            'domains' => $zone->domainNames(), 'mirrors' => count(Mirrors::sourcesOf($zone)),
            'policy' => $zone->certificatePolicy(),
        ];
    }

    public function create()
    {
        return view('zones.form', ['zone' => new Zone(['level' => 50]), 'certificates' => null]);
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
        // The proxy's certificates help fill in the certificate settings; without a (working) proxy the form still works.
        $certificates = null;
        if ($connection = Connection::get($zone)) {
            try {
                $certificates = (new NpmClient($connection, 4.0))->certificates();
            } catch (\Throwable) {
                $certificates = null;
            }
        }

        return view('zones.form', ['zone' => $zone, 'certificates' => $certificates]);
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
            'cert_strategy' => ['nullable', Rule::in(CertificatePolicy::STRATEGIES)],
            'cert_pins' => ['nullable', 'string', 'max:2000'],
            'cert_origin' => ['nullable', 'string', 'max:200'],
            'cert_email' => ['nullable', 'string', 'max:254'],
            'cert_dns_template' => ['nullable', 'integer', 'min:1'],
            'dns_public_address' => ['nullable', 'string', 'max:253', Check::hostOrIp()],
            'mirror_from' => ['nullable', 'array'], 'mirror_from.*' => ['integer', 'exists:zones,id'],
            'mirror_upstream' => ['nullable', 'array'], 'mirror_upstream.*' => ['nullable', Rule::in(Mirrors::UPSTREAMS)],
        ]);

        $sources = [];
        foreach ((array) ($v['mirror_from'] ?? []) as $id) {
            if ($zone === null || (int) $id !== $zone->id) {
                $sources[] = ['zone_id' => (int) $id, 'upstream' => $v['mirror_upstream'][$id] ?? 'auto'];
            }
        }

        [$policy, $problems] = CertificatePolicy::validate([
            'strategy' => $v['cert_strategy'] ?? 'existing',
            'edge_managed' => $request->boolean('cert_edge_managed'),
            'pins' => CertificatePolicy::parsePinText($v['cert_pins'] ?? ''),
            'origin_certificates' => preg_split('/[\s,;]+/', (string) ($v['cert_origin'] ?? ''), -1, PREG_SPLIT_NO_EMPTY),
            'letsencrypt_email' => $v['cert_email'] ?? '',
            'dns_template' => $v['cert_dns_template'] ?? null,
            'wildcard' => $request->boolean('cert_wildcard'),
        ]);
        if ($problems) {
            throw ValidationException::withMessages(['certificates' => array_map('ucfirst', $problems)]);
        }

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
            'certificate_policy' => $policy ?: null,
            'mirror_sources' => Mirrors::refs($sources) ?: null,
            'dns' => array_filter(['split_horizon' => $request->boolean('dns_split_horizon') ?: null, 'public_address' => filled($v['dns_public_address'] ?? null) ? trim($v['dns_public_address']) : null], fn ($x) => $x !== null) ?: null,
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
