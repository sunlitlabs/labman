<?php

namespace App\Dns;

use App\Inventory\Slug;
use App\Models\Server;
use App\Models\Service;
use App\Models\Zone;
use App\Npm\Mirrors;
use App\Support\Domains;
use Illuminate\Support\Collection;

/**
 * The DNS records the zone model needs, listed per resolver, without writing any of them. Two resolvers are
 * distinguished: 'inside' (the one on your network) and 'public' (your DNS provider on the internet).
 *
 *   - a wildcard per zone domain pointing at the zone's proxy (inside), or at the zone's public address (public);
 *   - with split-horizon on, a zone's public names are also answered inside, by its proxy;
 *   - an explicit record per server, pointing at the server's own address;
 *   - an explicit record for any service name a wildcard does not cover;
 *   - for each mirror, its name answered inside by the mirroring zone's proxy.
 *
 * Collisions are flagged, never guessed: two zones wanting the same wildcard or the same name need an owner picked
 * (see DnsOwners); a server record that hides a proxied name, a name stranded below an explicit record, a zone with
 * no proxy address, and a mirror whose upstream would resolve to its own proxy are flagged too.
 */
final class DnsPlan
{
    private const KIND_ORDER = ['wildcard' => 0, 'mirror' => 1, 'service' => 2, 'server' => 3];

    /**
     * @return array{records: list<array<string, mixed>>, conflicts: list<array<string, mixed>>}
     */
    public static function build(): array
    {
        $zones = Zone::query()->orderByDesc('level')->orderBy('name')->get();
        $byId = $zones->keyBy('id');
        $default = $zones->firstWhere('is_default', true) ?? $zones->first();
        $owners = DnsOwners::all();
        $records = [];
        $conflicts = [];
        $noProxy = [];

        $zoneOf = fn (Server|Service|null $item) => $item === null ? $default : ($byId->get($item instanceof Service ? ($item->zone_id ?: ($item->server?->zone_id ?? 0)) : ($item->zone_id ?? 0)) ?? $default);
        $proxyOf = function (Zone $z) use (&$conflicts, &$noProxy): ?string {
            if (blank($z->proxy_address)) {
                if (! isset($noProxy[$z->id])) {
                    $noProxy[$z->id] = true;
                    $conflicts[] = ['kind' => 'no_proxy_address', 'severity' => 'warning', 'key' => $z->name, 'zones' => [$z->name], 'message' => "The {$z->name} zone has names to answer but no proxy address, so those records are left out. Set it on the zone's page."];
                }

                return null;
            }

            return $z->proxy_address;
        };
        $add = function (string $name, string $kind, Zone $z, string $resolver, ?string $value, string $note = '', bool $derived = false) use (&$records) {
            $name = mb_strtolower($name);
            $records[$resolver.'|'.$name.'|'.$z->id] ??= [
                'name' => $name, 'type' => self::type($value), 'value' => $value, 'kind' => $kind, 'zone' => $z->name, 'zone_id' => $z->id,
                'resolver' => $resolver, 'note' => $note, 'derived' => $derived,
            ];
        };

        // ---- wildcards: one per zone domain, per resolver
        $claims = ['inside' => [], 'public' => []];
        foreach ($zones as $z) {
            $d = $z->domains();
            foreach ($d['local'] as $dom) {
                $claims['inside'][$dom][$z->id] = $z;
            }
            foreach ($d['public'] as $dom) {
                $claims['public'][$dom][$z->id] = $z;
                if ($z->dnsSettings()['split_horizon']) {
                    $claims['inside'][$dom][$z->id] = $z;
                }
            }
        }
        foreach ($claims as $resolver => $domains) {
            foreach ($domains as $dom => $claimants) {
                if (count($claimants) > 1) {
                    $owner = $owners['domains'][$dom] ?? null;
                    $valid = $owner !== null && isset($claimants[$owner]);
                    $conflicts[] = [
                        'kind' => 'domain', 'severity' => $valid ? 'resolved' : 'error', 'key' => $dom, 'resolver' => $resolver,
                        'zones' => array_values(array_map(fn ($c) => $c->name, $claimants)), 'zone_ids' => array_keys($claimants), 'owner' => $valid ? $owner : null,
                        'message' => "{$dom} is listed by ".implode(' and ', array_map(fn ($c) => $c->name, $claimants))." on the {$resolver} resolver".($valid ? '; '.$byId[$owner]->name.' owns it.' : '. Pick the zone that owns it; no record is made until you do.'),
                    ];
                    if (! $valid) {
                        continue;
                    }
                    $claimants = [$owner => $claimants[$owner]];
                }
                foreach ($claimants as $z) {
                    if ($resolver === 'inside') {
                        $value = $proxyOf($z);
                        $value !== null && $add('*.'.$dom, 'wildcard', $z, 'inside', $value, 'every name under '.$dom.' reaches the '.$z->name.' proxy');
                    } else {
                        $value = $z->dnsSettings()['public_address'];
                        $add('*.'.$dom, 'wildcard', $z, 'public', $value, $value === null ? 'Point this at your CDN, tunnel or public address (set it on the zone).' : 'every name under '.$dom.' reaches the '.$z->name.' zone from the internet');
                    }
                }
            }
        }

        // ---- what each server and service wants answered
        $wants = [];   // resolver|name => zone id => list of [kind, label, zone]
        $explicit = []; // resolver|name => true (names with a record of their own)
        $servers = Server::query()->orderBy('name')->get();
        $services = Service::query()->with('server')->orderBy('name')->get();

        foreach ($servers as $s) {
            $z = $zoneOf($s);
            $names = Domains::namesOf($s)['local'];
            $derived = false;
            if ($names === [] && ($first = ($z->domains()['local'][0] ?? null)) !== null) {
                $names = [strtolower(trim((string) preg_replace('/[^A-Za-z0-9-]+/', '-', Slug::host($s->name)), '-')).'.'.$first];
                $derived = true;
            }
            foreach ($names as $name) {
                $wants['inside|'.mb_strtolower($name)][$z->id][] = ['kind' => 'server', 'label' => $s->name, 'zone' => $z, 'value' => $s->address, 'derived' => $derived];
            }
        }
        foreach ($services as $svc) {
            $z = $zoneOf($svc);
            $names = Domains::namesOf($svc);
            foreach ($names['local'] as $name) {
                $wants['inside|'.mb_strtolower($name)][$z->id][] = ['kind' => 'service', 'label' => $svc->name, 'zone' => $z, 'value' => 'proxy', 'derived' => false];
            }
            foreach ($names['public'] as $name) {
                $wants['public|'.mb_strtolower($name)][$z->id][] = ['kind' => 'service', 'label' => $svc->name, 'zone' => $z, 'value' => 'public', 'derived' => false];
                if ($z->dnsSettings()['split_horizon']) {
                    $wants['inside|'.mb_strtolower($name)][$z->id][] = ['kind' => 'service', 'label' => $svc->name, 'zone' => $z, 'value' => 'proxy', 'derived' => false];
                }
            }
        }

        // ---- the same name wanted by two zones: pick an owner, never guess
        foreach ($wants as $key => $byZone) {
            if (count($byZone) < 2) {
                continue;
            }
            [$resolver, $name] = explode('|', $key, 2);
            $owner = $owners['names'][$name] ?? null;
            $valid = $owner !== null && isset($byZone[$owner]);
            $zoneNames = array_values(array_map(fn ($items) => $items[0]['zone']->name, $byZone));
            $conflicts[] = [
                'kind' => 'name', 'severity' => $valid ? 'resolved' : 'error', 'key' => $name, 'resolver' => $resolver, 'zones' => $zoneNames, 'zone_ids' => array_keys($byZone), 'owner' => $valid ? $owner : null,
                'message' => "{$name} is used by ".implode(' and ', array_map(fn ($items) => $items[0]['label'].' in '.$items[0]['zone']->name, $byZone))." ({$resolver} resolver)".($valid ? '; '.$byId[$owner]->name.' owns it.' : '. Pick the zone that owns it; no record is made until you do.'),
            ];
            $wants[$key] = $valid ? [$owner => $byZone[$owner]] : [];
        }

        // ---- servers first (explicit records), then services where a wildcard does not already do the job
        foreach ($wants as $key => $byZone) {
            foreach ($byZone as $items) {
                foreach ($items as $w) {
                    if ($w['kind'] === 'server') {
                        [, $name] = explode('|', $key, 2);
                        $add($name, 'server', $w['zone'], 'inside', $w['value'], $w['derived'] ? 'suggested name for '.$w['label'].' (it has no domain set)' : $w['label'], $w['derived']);
                        $explicit['inside|'.$name] = true;
                    }
                }
            }
        }

        $covered = function (string $resolver, string $name, ?string $value) use (&$records, &$explicit): string {
            // 'yes' | 'no' | 'shadowed' (a record of its own sits between the wildcard and the name)
            foreach ($records as $r) {
                if ($r['resolver'] !== $resolver || $r['kind'] !== 'wildcard') {
                    continue;
                }
                $dom = substr($r['name'], 2);
                if (! str_ends_with($name, '.'.$dom) || $r['value'] !== $value) {
                    continue;
                }
                foreach (array_keys($explicit) as $e) {
                    [$er, $en] = explode('|', $e, 2);
                    if ($er === $resolver && $en !== $name && str_ends_with($name, '.'.$en) && str_ends_with($en, '.'.$dom)) {
                        return 'shadowed';
                    }
                }

                return 'yes';
            }

            return 'no';
        };

        foreach ($wants as $key => $byZone) {
            [$resolver, $name] = explode('|', $key, 2);
            foreach ($byZone as $items) {
                foreach ($items as $w) {
                    if ($w['kind'] !== 'service') {
                        continue;
                    }
                    $z = $w['zone'];
                    $value = $w['value'] === 'public' ? $z->dnsSettings()['public_address'] : $proxyOf($z);
                    if ($resolver === 'inside' && $value === null) {
                        continue;
                    }
                    $state = $covered($resolver, $name, $value);
                    if ($state === 'yes') {
                        continue;
                    }
                    if ($state === 'shadowed') {
                        $conflicts[] = ['kind' => 'shadowed', 'severity' => 'warning', 'key' => $name, 'zones' => [$z->name], 'message' => "{$name} sits below a name that has its own record, so the wildcard no longer matches it. It gets an explicit record."];
                    }
                    $add($name, 'service', $z, $resolver, $value, $w['label'].($value === null ? ' (set the zone\'s public address)' : ''));
                    $explicit[$resolver.'|'.$name] = true;
                }
            }
        }

        // a server's own record hides the proxied name of a service that uses the same name
        foreach ($servers as $s) {
            foreach ($services as $svc) {
                foreach (Domains::namesOf($svc)['local'] as $name) {
                    if (isset($explicit['inside|'.mb_strtolower($name)]) && collect($records)->contains(fn ($r) => $r['resolver'] === 'inside' && $r['kind'] === 'server' && $r['name'] === mb_strtolower($name)) && in_array(mb_strtolower($name), array_map('mb_strtolower', Domains::namesOf($s)['local']), true)) {
                        $conflicts[] = ['kind' => 'server_shadows_service', 'severity' => 'warning', 'key' => mb_strtolower($name), 'zones' => [$zoneOf($s)->name], 'message' => "The record for server {$s->name} at {$name} also hides the proxied name of {$svc->name}: clients get the server, not the proxy."];
                    }
                }
            }
        }

        // ---- mirrors: the mirroring zone's resolver answers the name with its own proxy
        $mirrorInfo = [];
        foreach ($zones as $b) {
            foreach (Mirrors::wanted($b, null, $zones) as $name => $w) {
                $value = $proxyOf($b);
                if ($value === null) {
                    continue;
                }
                $mirrorInfo[] = ['target' => $b, 'source' => $w['source'], 'name' => $name, 'value' => $value];
                if ($covered('inside', $name, $value) !== 'yes') {
                    $add($name, 'mirror', $b, 'inside', $value, 'mirror of '.$w['service']->name.' from the '.$w['source']->name.' zone: inside clients reach it through the '.$b->name.' proxy');
                    $explicit['inside|'.$name] = true;
                }
            }
        }

        $records = array_values($records);
        usort($records, fn ($a, $b) => [$a['resolver'] === 'inside' ? 0 : 1, -($byId[$a['zone_id']]->level ?? 0), self::KIND_ORDER[$a['kind']], $a['name']] <=> [$b['resolver'] === 'inside' ? 0 : 1, -($byId[$b['zone_id']]->level ?? 0), self::KIND_ORDER[$b['kind']], $b['name']]);

        // ---- a mirror whose upstream name resolves back to the mirroring proxy loops
        foreach ($mirrorInfo as $m) {
            $up = $m['source']->proxy_address;
            if ($up === null || filter_var($up, FILTER_VALIDATE_IP)) {
                continue;
            }
            $resolved = self::lookup($records, 'inside', mb_strtolower($up));
            if ($up !== null && (mb_strtolower($up) === $m['name'] || $resolved === $m['value'])) {
                $conflicts[] = ['kind' => 'mirror_loop', 'severity' => 'error', 'key' => $m['name'], 'zones' => [$m['target']->name, $m['source']->name], 'message' => "The mirror of {$m['name']} forwards to '{$up}', which resolves to the {$m['target']->name} proxy itself: it would loop. Use an IP address, or a name that resolves straight to the {$m['source']->name} proxy."];
            }
        }

        return ['records' => $records, 'conflicts' => $conflicts];
    }

    /** What a resolver would answer for a name: an exact record, else the wildcard that covers it. @param list<array<string, mixed>> $records */
    public static function lookup(array $records, string $resolver, string $name): ?string
    {
        $wild = null;
        foreach ($records as $r) {
            if ($r['resolver'] !== $resolver) {
                continue;
            }
            if ($r['name'] === $name) {
                return $r['value'];
            }
            if ($r['kind'] === 'wildcard' && str_ends_with($name, '.'.substr($r['name'], 2)) && ($wild === null || strlen($r['name']) > strlen($wild['name']))) {
                $wild = $r;
            }
        }

        return $wild['value'] ?? null;
    }

    /** A, AAAA or CNAME for an answer. */
    public static function type(?string $value): string
    {
        return match (true) {
            $value === null => 'A',
            (bool) filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) => 'AAAA',
            (bool) filter_var($value, FILTER_VALIDATE_IP) => 'A',
            default => 'CNAME',
        };
    }

    /** Conflicts that still need a decision. @param list<array<string, mixed>> $conflicts @return list<array<string, mixed>> */
    public static function open(array $conflicts): array
    {
        return array_values(array_filter($conflicts, fn ($c) => $c['severity'] === 'error'));
    }
}
