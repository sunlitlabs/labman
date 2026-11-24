<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Models\Zone;
use App\Npm\CertificatePolicy;
use App\Npm\Connection;
use App\Rules\Check;
use App\Support\Domains;
use App\Support\Secrets;
use App\Support\Settings;
use App\Support\ZoneStatus;
use App\Support\Zones;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The zones setup wizard: name the zones, place the servers, connect each proxy, set domains and certificates, review.
 * Every step saves straight to the real settings and can be skipped or revisited in any order; nothing is held in a
 * half-finished state, and nothing is written to a proxy or to DNS.
 */
class ZoneWizardController extends Controller
{
    public const STEPS = [
        'zones' => 'Zones',
        'servers' => 'Servers',
        'proxies' => 'Proxies',
        'domains' => 'Domains and certificates',
        'review' => 'Review',
    ];

    /** Starting points for the first step: the zones a template adds (an existing zone with the same name is kept). */
    public const TEMPLATES = [
        'four' => ['label' => 'Four zones', 'zones' => [['Management', 90], ['Internal', 70], ['LAN', 40], ['External', 10]]],
        'single' => ['label' => 'A single zone', 'zones' => []],
    ];

    public function show(Request $request)
    {
        $step = $this->step($request->query('step', 'zones'));
        $zones = Zones::all();

        $data = ['step' => $step, 'steps' => self::STEPS, 'zones' => $zones, 'done' => $this->done($zones), 'finished' => Secrets::plain('zones.wizard_done') !== null];

        if ($step === 'zones') {
            $template = $request->query('template');
            $extra = [];
            foreach (self::TEMPLATES[$template]['zones'] ?? [] as [$name, $level]) {
                if (! $zones->contains(fn (Zone $z) => mb_strtolower($z->name) === mb_strtolower($name))) {
                    $extra[] = ['name' => $name, 'level' => $level, 'cidrs' => ''];
                }
            }
            $data += ['templates' => self::TEMPLATES, 'template' => $template, 'extra' => $extra];
        } elseif ($step === 'servers') {
            $servers = Server::query()->orderBy('name')->get();
            $data += ['servers' => $servers, 'suggestions' => $servers->mapWithKeys(fn (Server $s) => [$s->id => Zones::suggestFor($s->address, $zones)])];
        } elseif ($step === 'proxies') {
            $data += ['connections' => $zones->mapWithKeys(fn (Zone $z) => [$z->id => Connection::summary($z)]), 'statuses' => $zones->mapWithKeys(fn (Zone $z) => [$z->id => ZoneStatus::cached($z)])];
        } elseif ($step === 'review') {
            $data += ['connections' => $zones->mapWithKeys(fn (Zone $z) => [$z->id => Connection::exists($z)]), 'serverCounts' => $zones->mapWithKeys(fn (Zone $z) => [$z->id => Zones::serversIn($z)->count()])];
        }

        return view('zones.wizard', $data);
    }

    public function save(Request $request, string $step): RedirectResponse
    {
        $step = $this->step($step);

        match ($step) {
            'zones' => $this->saveZones($request),
            'servers' => $this->saveServers($request),
            'domains' => $this->saveDomains($request),
            'review' => Secrets::putPlain('zones.wizard_done', now()->toIso8601String()),
            default => null,
        };

        if ($step === 'review') {
            return redirect()->route('zones.index')->with('status', 'Zones are set up. Revisit any step from the wizard whenever something changes.');
        }

        $keys = array_keys(self::STEPS);
        $next = $keys[array_search($step, $keys, true) + 1] ?? 'review';

        return redirect()->route('zones.wizard', ['step' => $next])->with('status', self::STEPS[$step].' saved.');
    }

    private function step(mixed $step): string
    {
        return array_key_exists((string) $step, self::STEPS) ? (string) $step : 'zones';
    }

    /** A rough "is this step already in order" for the step list. @return array<string, bool> */
    private function done($zones): array
    {
        return [
            'zones' => $zones->count() > 1,
            'servers' => Server::query()->exists() && Server::query()->distinct()->count('zone_id') > 1,
            'proxies' => Connection::connectedZones() !== [],
            'domains' => $zones->contains(fn (Zone $z) => $z->domainNames() !== []),
            'review' => Secrets::plain('zones.wizard_done') !== null,
        ];
    }

    // ----------------------------------------------------------------------------- the steps

    private function saveZones(Request $request): void
    {
        $existing = (array) $request->input('zones', []);
        $new = array_filter((array) $request->input('new', []), fn ($r) => filled($r['name'] ?? null));
        $errors = [];
        $names = [];

        $rows = [];
        foreach ($existing as $id => $r) {
            $rows['z'.$id] = ['id' => (int) $id] + $r;
        }
        foreach ($new as $i => $r) {
            $rows['n'.$i] = ['id' => null] + $r;
        }

        foreach ($rows as $key => $r) {
            $name = trim((string) ($r['name'] ?? ''));
            $level = $r['level'] ?? null;
            if ($name === '' || mb_strlen($name) > 60) {
                $errors["zones.{$key}"] = 'Every zone needs a name of up to 60 characters.';
            } elseif (isset($names[mb_strtolower($name)])) {
                $errors["zones.{$key}"] = "Two zones are called {$name}.";
            }
            $names[mb_strtolower($name)] = true;
            if (! is_numeric($level) || (int) $level < 0 || (int) $level > 100) {
                $errors["level.{$key}"] = "The level of {$name} must be a number from 0 to 100.";
            }
            foreach (Zones::parseCidrs($r['cidrs'] ?? '') as $cidr) {
                if (! Zones::validCidr($cidr)) {
                    $errors["cidrs.{$key}"] = "'{$cidr}' is not a network block like 192.0.2.0/24.";
                }
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($rows) {
            foreach ($rows as $r) {
                $data = ['name' => trim($r['name']), 'level' => (int) $r['level'], 'cidrs' => Zones::parseCidrs($r['cidrs'] ?? '') ?: null];
                if ($r['id']) {
                    Zone::query()->findOrFail($r['id'])->update($data);
                } else {
                    Zone::create($data + ['is_default' => false]);
                }
            }
        });
    }

    private function saveServers(Request $request): void
    {
        $v = $request->validate(['assign' => ['nullable', 'array'], 'assign.*' => ['nullable', 'integer', 'exists:zones,id']]);
        $zones = Zones::all();

        foreach (Server::query()->get() as $server) {
            $zoneId = $v['assign'][$server->id] ?? null;
            if ($request->has('suggest') && ($suggested = Zones::suggestFor($server->address, $zones))) {
                $zoneId = $suggested->id;
            }
            if ($zoneId && (int) $zoneId !== (int) $server->zone_id) {
                $server->update(['zone_id' => (int) $zoneId]);
            }
        }
    }

    private function saveDomains(Request $request): void
    {
        $input = (array) $request->input('z', []);
        $errors = [];
        $changes = [];

        foreach (Zone::query()->get() as $zone) {
            $r = $input[$zone->id] ?? null;
            if ($r === null) {
                continue;
            }

            $v = Validator::make($r, [
                'local_domains' => ['nullable', Check::domainList()],
                'public_domains' => ['nullable', Check::domainList()],
                'public_address' => ['nullable', 'string', 'max:253', Check::hostOrIp()],
                'cert_strategy' => ['nullable', 'in:'.implode(',', CertificatePolicy::STRATEGIES)],
                'cert_email' => ['nullable', 'email', 'max:254'],
            ], [], ['local_domains' => "local domains of {$zone->name}", 'public_domains' => "public domains of {$zone->name}", 'public_address' => "public address of {$zone->name}", 'cert_strategy' => "certificate choice of {$zone->name}", 'cert_email' => "Let's Encrypt e-mail of {$zone->name}"]);
            if ($v->fails()) {
                foreach ($v->errors()->all() as $m) {
                    $errors[] = $m;
                }

                continue;
            }

            $current = $zone->certificatePolicy();
            [$policy, $problems] = CertificatePolicy::validate([
                'strategy' => $r['cert_strategy'] ?? $current->strategy, 'edge_managed' => $current->edgeManaged, 'pins' => $current->pins,
                'origin_certificates' => $current->originIds, 'letsencrypt_email' => $r['cert_email'] ?? $current->email, 'dns_template' => $current->dnsTemplate, 'wildcard' => $current->wildcard,
            ]);
            if ($problems) {
                foreach ($problems as $p) {
                    $errors[] = "{$zone->name}: {$p}";
                }

                continue;
            }

            $changes[] = [$zone, $r, $policy];
        }

        if ($errors) {
            throw ValidationException::withMessages(['domains' => array_map('ucfirst', $errors)]);
        }

        DB::transaction(function () use ($changes) {
            foreach ($changes as [$zone, $r, $policy]) {
                $local = Domains::parse($r['local_domains'] ?? '');
                $public = Domains::parse($r['public_domains'] ?? '');
                $dns = $zone->dnsSettings();
                $dns['public_address'] = filled($r['public_address'] ?? null) ? trim($r['public_address']) : null;

                $data = ['certificate_policy' => $policy ?: null, 'dns' => array_filter(['split_horizon' => $dns['split_horizon'] ?: null, 'public_address' => $dns['public_address']], fn ($x) => $x !== null) ?: null];
                if ($zone->is_default) {
                    Settings::set(['local_domains' => implode(', ', $local), 'public_domains' => implode(', ', $public)]);
                } else {
                    $data['domains'] = $local || $public ? ['local' => $local, 'public' => $public] : null;
                }
                $zone->update($data);
            }
        });
    }
}
