<?php

namespace App\Npm;

use App\Models\Mirror;
use App\Models\Service;
use App\Models\Zone;
use Illuminate\Support\Collection;

/**
 * Mirrors: an entry in one zone's proxy that forwards to another zone's proxy for a host that other zone owns, so
 * people inside can reach a name that is published elsewhere by the same name without leaving and coming back.
 *
 * What should exist is worked out from two settings, both off by default: a zone's "mirror these zones' hosts" rule
 * and a service's own "also publish in these zones" list (the service's wins). A mirror forwards to the source zone's
 * proxy address (an IP or a stable internal name, never the public name, so it cannot loop through DNS) and keeps the
 * original Host header, and SNI when the hop is HTTPS, so the target proxy routes it as if the client had connected.
 */
final class Mirrors
{
    public const UPSTREAMS = ['auto', 'http', 'https', 'https_insecure', 'direct'];

    public const UPSTREAM_LABELS = [
        'auto' => 'Automatic (the safest that works)',
        'http' => 'HTTP',
        'https' => 'HTTPS, verify the certificate',
        'https_insecure' => 'HTTPS, do not verify (the target presents an origin certificate)',
        'direct' => 'Direct to the service itself (skip the other proxy)',
    ];

    /** Marks a proxy host's advanced config as LabMan's mirror (and says what it mirrors). */
    public const MARKER = '# Managed by LabMan: mirror of';

    /**
     * Clean a list of {zone_id, upstream, names?} (the stored shape) from a form or file. `names` (only on a service's
     * own list) picks which of the service's names that mirror covers; without it the zone's own domains decide.
     *
     * @return list<array{zone_id: int, upstream: string, names?: list<string>}>
     */
    public static function refs(mixed $value): array
    {
        $out = [];
        foreach (is_array($value) ? $value : [] as $ref) {
            if (is_array($ref) && isset($ref['zone_id']) && (int) $ref['zone_id'] > 0) {
                $upstream = in_array($ref['upstream'] ?? 'auto', self::UPSTREAMS, true) ? $ref['upstream'] : 'auto';
                $names = array_values(array_unique(array_filter(array_map(fn ($n) => mb_strtolower(trim((string) $n)), (array) ($ref['names'] ?? [])), fn ($n) => $n !== '')));
                $out[(int) $ref['zone_id']] = ['zone_id' => (int) $ref['zone_id'], 'upstream' => $upstream] + ($names !== [] ? ['names' => $names] : []);
            }
        }

        return array_values($out);
    }

    /** Every host name of a service that a mirror could cover, lower case. @return list<string> */
    public static function namesOf(Service $service): array
    {
        return array_values(array_unique(array_filter([mb_strtolower((string) $service->domain), mb_strtolower((string) $service->public_domain)])));
    }

    /**
     * The zones whose domains the INSIDE resolver answers for $name, by longest suffix (as the DNS plan claims them): a
     * zone's local domains, plus its public ones when it answers them inside too (split-horizon). A tie goes to the
     * owner picked on the DNS plan, if any.
     *
     * @return list<Zone>
     */
    public static function insideOwners(string $name, ?Collection $zones = null): array
    {
        $name = mb_strtolower(rtrim(trim($name), '.'));
        $best = -1;
        $bestDomain = null;
        $found = [];

        foreach ($zones ?? Zone::query()->get() as $zone) {
            $d = $zone->domains();
            $domains = array_merge($d['local'], $zone->dnsSettings()['split_horizon'] ? $d['public'] : []);
            foreach ($domains as $domain) {
                $domain = mb_strtolower($domain);
                if ($name === $domain || str_ends_with($name, '.'.$domain)) {
                    if (strlen($domain) > $best) {
                        [$best, $bestDomain, $found] = [strlen($domain), $domain, []];
                    }
                    if (strlen($domain) === $best && ! in_array($zone, $found, true)) {
                        $found[] = $zone;
                    }
                }
            }
        }

        if (count($found) > 1 && $bestDomain !== null && ($pick = \App\Dns\DnsOwners::domainOwner($bestDomain)) !== null) {
            $picked = array_values(array_filter($found, fn ($z) => $z->id === $pick));
            if ($picked !== []) {
                return $picked;
            }
        }

        return $found;
    }

    /** Does the inside resolver answer $name with $target's own domains? */
    public static function answeredBy(Zone $target, string $name, ?Collection $zones = null): bool
    {
        foreach (self::insideOwners($name, $zones) as $owner) {
            if ($owner->id === $target->id) {
                return true;
            }
        }

        return false;
    }

    /**
     * The names of $service that a mirror in $target covers unless the service says otherwise: those under the mirroring
     * zone's own domains. Names another zone owns (and answers itself) are left alone.
     *
     * @return list<string>
     */
    public static function defaultNames(Service $service, Zone $target, ?Collection $zones = null): array
    {
        return array_values(array_filter(self::namesOf($service), fn ($n) => self::answeredBy($target, $n, $zones)));
    }

    /** The zones $target mirrors, as source zone id => upstream. @return array<int, string> */
    public static function sourcesOf(Zone $target): array
    {
        return array_column(self::refs($target->mirror_sources), 'upstream', 'zone_id');
    }

    /**
     * The mirrors a zone's proxy should hold: one per host name of each service that belongs to a mirrored zone or is
     * published here by its own setting.
     *
     * @param  list<int>|null  $only  limit to these services
     * @param  Collection<int, Zone>|null  $zones  all zones, by id (saves queries)
     * @return array<string, array{service: Service, source: Zone, domain: string, upstream: string}> keyed by domain
     */
    public static function wanted(Zone $target, ?array $only = null, ?Collection $zones = null): array
    {
        $zones ??= Zone::query()->get();
        $zones = $zones->keyBy('id');
        $default = $zones->firstWhere('is_default', true) ?? $zones->first();
        $rules = self::sourcesOf($target);
        $out = [];

        $services = Service::query()->with('server')->where(fn ($q) => $q->whereNotNull('domain')->orWhereNotNull('public_domain'))
            ->when($only !== null, fn ($q) => $q->whereIn('id', $only))->orderBy('name')->get();

        foreach ($services as $service) {
            $source = $zones->get($service->zone_id ?: ($service->server?->zone_id ?? 0)) ?? $default;
            if ($source === null || $source->id === $target->id) {
                continue;
            }

            $ref = array_column(self::refs($service->publish_zones), null, 'zone_id')[$target->id] ?? null;
            $upstream = $ref['upstream'] ?? $rules[$source->id] ?? null;
            if ($upstream === null) {
                continue;
            }

            // Only the names the mirroring zone answers, unless the service picked its names for this zone.
            $chosen = $ref !== null && ! empty($ref['names'])
                ? array_values(array_intersect(self::namesOf($service), $ref['names']))
                : self::defaultNames($service, $target, $zones);

            foreach ($chosen as $domain) {
                $out[$domain] ??= ['service' => $service, 'source' => $source, 'domain' => $domain, 'upstream' => $upstream];
            }
        }

        return $out;
    }

    /** The zones a service is mirrored into (for queueing automatic updates). @return list<Zone> */
    public static function targetsFor(Service $service): array
    {
        $hit = [];
        foreach (Zone::query()->get() as $zone) {
            if (self::wanted($zone, [$service->id]) !== []) {
                $hit[] = $zone;
            }
        }

        return $hit;
    }

    /**
     * Mirrors LabMan made in a zone's proxy that nothing asks for any more (the service is gone or renamed, or the
     * rule was switched off). LabMan never deletes in NPM, so these are listed for removal by hand.
     *
     * @param  array<string, mixed>  $wanted  the result of wanted()
     * @return Collection<int, Mirror>
     */
    public static function orphans(Zone $target, array $wanted): Collection
    {
        return Mirror::query()->where('zone_id', $target->id)->orderBy('domain')->get()->reject(fn (Mirror $m) => isset($wanted[$m->domain]))->values();
    }

    /** The nginx lines that keep the Host header, SNI and certificate checking right for one kind of hop. */
    public static function advancedConfig(string $upstream, string $sourceName, ?string $backendScheme = null): string
    {
        $lines = [self::MARKER.' '.$sourceName, 'proxy_set_header Host $host;'];

        if ($upstream === 'direct') {
            // Straight to the service: its own certificate is rarely one a public CA bundle verifies.
            if ($backendScheme === 'https') {
                array_push($lines, 'proxy_ssl_server_name on;', 'proxy_ssl_name $host;', 'proxy_ssl_verify off;');
            }

            return implode("\n", $lines);
        }

        if ($upstream !== 'http') {
            $lines[] = 'proxy_ssl_server_name on;';
            $lines[] = 'proxy_ssl_name $host;';
            if ($upstream === 'https') {
                $lines[] = 'proxy_ssl_verify on;';
                $lines[] = 'proxy_ssl_trusted_certificate /etc/ssl/certs/ca-certificates.crt;';
            } else {
                $lines[] = 'proxy_ssl_verify off;';
            }
        }

        return implode("\n", $lines);
    }

    /**
     * The hop to use when the setting says "automatic": plain HTTP when the target host has no certificate, HTTPS
     * with verification when its certificate is one browsers trust, HTTPS without verification when it is an origin
     * certificate (a public CA bundle cannot verify those, but the hop is still encrypted).
     *
     * @param  array<string, mixed>|null  $sourceHost  the source zone's proxy host for the name, when it could be read
     * @param  array<string, mixed>|null  $sourceCert  that host's certificate, when known
     * @return array{upstream: string, why: string}
     */
    public static function chooseUpstream(string $setting, Zone $source, ?array $sourceHost, ?array $sourceCert): array
    {
        if ($setting === 'direct') {
            return ['upstream' => 'direct', 'why' => 'direct to the service itself, not through the '.$source->name.' proxy'];
        }
        if ($setting !== 'auto') {
            return ['upstream' => $setting, 'why' => 'as set: '.self::UPSTREAM_LABELS[$setting]];
        }

        $policy = $source->certificatePolicy();

        if ($sourceHost !== null) {
            if ((int) ($sourceHost['certificate_id'] ?? 0) === 0) {
                return ['upstream' => 'http', 'why' => "the {$source->name} proxy serves this name without a certificate"];
            }
            if ($policy->edgeManaged || ($sourceCert !== null && in_array($sourceCert['id'], $policy->originIds, true))) {
                return ['upstream' => 'https_insecure', 'why' => "the {$source->name} proxy presents an origin certificate, which a public CA bundle cannot verify"];
            }

            return ['upstream' => 'https', 'why' => "the {$source->name} proxy presents a certificate browsers trust"];
        }

        // The source proxy could not be read: decide from its policy.
        return match (true) {
            $policy->edgeManaged => ['upstream' => 'https_insecure', 'why' => "the {$source->name} zone sits behind a CDN, so its certificates are origin certificates"],
            $policy->strategy === 'none' && $policy->pins === [] => ['upstream' => 'http', 'why' => "the {$source->name} zone serves plain HTTP by policy"],
            default => ['upstream' => 'https', 'why' => "the {$source->name} zone's policy uses certificates"],
        };
    }
}
