<?php

namespace App\Support;

use App\Models\Server;
use App\Models\Service;
use App\Models\Zone;
use Illuminate\Support\Collection;

/**
 * Zone lookups: the default zone, whether the zone UI is needed at all, which zone owns a host name, and which
 * zone a new server probably belongs to. Everything here is advisory; nothing blocks on a zone rule.
 */
final class Zones
{
    /** Zones from the most trusted down. @return Collection<int, Zone> */
    public static function all(): Collection
    {
        return Zone::query()->orderByDesc('level')->orderBy('name')->get();
    }

    /** The zone that holds anything not placed elsewhere (always one; the first is used if the flag was lost). */
    public static function default(): ?Zone
    {
        return Zone::query()->where('is_default', true)->first() ?? Zone::query()->orderBy('id')->first();
    }

    public static function defaultId(): ?int
    {
        return self::default()?->id;
    }

    /** Progressive disclosure: with one zone the zone screens and form fields stay out of the way. */
    public static function multiple(): bool
    {
        return Zone::query()->count() > 1;
    }

    /** The zone a service belongs to: its own override, else its server's, else the default. */
    public static function of(Service $service): ?Zone
    {
        if ($service->zone_id) {
            return Zone::query()->find($service->zone_id);
        }

        $service->loadMissing('server');

        return ($service->server?->zone_id ? Zone::query()->find($service->server->zone_id) : null) ?? self::default();
    }

    /**
     * The services that belong to a zone: those that name it, and those that follow a server in it. Services with
     * no zone of their own and no server (or a server with no zone) belong to the default zone.
     *
     * @return \Illuminate\Database\Eloquent\Builder<Service>
     */
    public static function servicesIn(Zone $zone)
    {
        return Service::query()->where(function ($q) use ($zone) {
            $q->where('zone_id', $zone->id)
                ->orWhere(fn ($q) => $q->whereNull('zone_id')->whereHas('server', fn ($s) => $s->where('zone_id', $zone->id)));

            if ($zone->is_default) {
                $q->orWhere(fn ($q) => $q->whereNull('zone_id')->whereNull('server_id'))
                    ->orWhere(fn ($q) => $q->whereNull('zone_id')->whereHas('server', fn ($s) => $s->whereNull('zone_id')));
            }
        });
    }

    /** The servers in a zone (a server with no zone counts as default). @return \Illuminate\Database\Eloquent\Builder<Server> */
    public static function serversIn(Zone $zone)
    {
        return Server::query()->where(fn ($q) => $q->where('zone_id', $zone->id)->when($zone->is_default, fn ($q) => $q->orWhereNull('zone_id')));
    }

    /**
     * The zones whose domain is the longest suffix of the host name (a zone for "*.a.example.dev" beats one for
     * "*.example.dev"). More than one means two zones claim the same name: the caller shows it and asks for an owner.
     *
     * @return list<Zone>
     */
    public static function owners(string $host, ?Collection $zones = null): array
    {
        $host = mb_strtolower(rtrim(trim($host), '.'));
        $best = -1;
        $bestDomain = null;
        $found = [];

        foreach ($zones ?? self::all() as $zone) {
            foreach ($zone->domainNames() as $domain) {
                if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                    $length = strlen($domain);
                    if ($length > $best) {
                        $best = $length;
                        $bestDomain = $domain;
                        $found = [];
                    }
                    if ($length === $best && ! in_array($zone, $found, true)) {
                        $found[] = $zone;
                    }
                }
            }
        }

        // Two zones listing the very same domain: the owner the admin picked (see DnsOwners) settles it.
        if (count($found) > 1 && $bestDomain !== null && ($pick = \App\Dns\DnsOwners::domainOwner($bestDomain)) !== null) {
            $picked = array_values(array_filter($found, fn ($z) => $z->id === $pick));
            if ($picked !== []) {
                return $picked;
            }
        }

        return $found;
    }

    /** The single zone that owns the host name, or null when none does or several claim it equally. */
    public static function ownerOf(string $host, ?Collection $zones = null): ?Zone
    {
        $owners = self::owners($host, $zones);

        return count($owners) === 1 ? $owners[0] : null;
    }

    /** The zone whose CIDR list contains the address (the narrowest block wins), or null. */
    public static function suggestFor(string $ip, ?Collection $zones = null): ?Zone
    {
        $best = null;
        $bestBits = -1;

        foreach ($zones ?? self::all() as $zone) {
            foreach ((array) $zone->cidrs as $cidr) {
                $bits = self::cidrBits((string) $cidr, $ip);
                if ($bits !== null && $bits > $bestBits) {
                    $best = $zone;
                    $bestBits = $bits;
                }
            }
        }

        return $best;
    }

    /** The prefix length of $cidr when it contains $ip, else null. */
    public static function cidrBits(string $cidr, string $ip): ?int
    {
        if (! self::validCidr($cidr)) {
            return null;
        }

        [$net, $bits] = explode('/', $cidr);
        $a = @inet_pton($net);
        $b = @inet_pton($ip);
        if ($a === false || $b === false || strlen($a) !== strlen($b)) {
            return null;
        }

        $bits = (int) $bits;
        $whole = intdiv($bits, 8);
        if (substr($a, 0, $whole) !== substr($b, 0, $whole)) {
            return null;
        }
        if ($bits % 8 !== 0) {
            $mask = (0xFF << (8 - $bits % 8)) & 0xFF;
            if ((ord($a[$whole]) & $mask) !== (ord($b[$whole]) & $mask)) {
                return null;
            }
        }

        return $bits;
    }

    public static function validCidr(string $cidr): bool
    {
        if (! preg_match('#^([0-9a-fA-F:.]+)/(\d{1,3})$#', trim($cidr), $m) || ! filter_var($m[1], FILTER_VALIDATE_IP)) {
            return false;
        }

        return (int) $m[2] <= (str_contains($m[1], ':') ? 128 : 32);
    }

    /** "192.0.2.0/24, 198.51.100.0/24" (or a list) as clean entries. @return list<string> */
    public static function parseCidrs(string|array|null $value): array
    {
        $parts = is_array($value) ? $value : preg_split('/[\s,;]+/', (string) $value);

        return array_values(array_unique(array_filter(array_map(fn ($p) => trim((string) $p), $parts), fn ($p) => $p !== '')));
    }

    /** The server's zone name for lists and warnings; null when the lab has one zone. */
    public static function labelFor(Server $server): ?string
    {
        return self::multiple() ? $server->zone?->name : null;
    }
}
