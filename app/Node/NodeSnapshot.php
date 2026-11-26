<?php

namespace App\Node;

use App\Models\Link;
use App\Models\Server;
use App\Models\Service;
use App\Models\Zone;
use App\Support\Settings;
use App\Support\Zones;
use Illuminate\Support\Collection;

/**
 * What the main LabMan sends to a node: the items of one zone (or everything), with their current health, and the
 * few settings the dashboard needs. Nothing secret is in it (no connections, tokens or users), and the same column
 * lists decide what a node accepts, so a node never stores more than it is meant to show.
 */
final class NodeSnapshot
{
    public const SETTINGS = ['title', 'ssh_user', 'ssh_target', 'check_interval', 'icons'];

    public const ZONE = ['id', 'name', 'level', 'color', 'is_default', 'cidrs', 'dashboard_indicator'];

    public const SERVER = ['id', 'name', 'address', 'domain', 'group', 'ports', 'ssh_user', 'ssh_port', 'notes', 'monitor', 'check_ssh', 'icon', 'extra_domains', 'zone_id', 'on_dashboard', 'alternates', 'state', 'checks', 'checked_at'];

    public const SERVICE = ['id', 'name', 'domain', 'server_id', 'address', 'port', 'scheme', 'path', 'group', 'expect', 'verify_tls', 'public_check', 'monitor', 'check_tcp', 'icon', 'public_domain', 'extra_domains', 'zone_id', 'on_dashboard', 'alternates', 'family', 'health_path', 'notes', 'state', 'checks', 'checked_at'];

    public const LINK = ['id', 'name', 'url', 'group', 'notes', 'icon', 'on_dashboard'];

    /** @return array<string, mixed> */
    public static function build(?Zone $zone = null): array
    {
        $servers = $zone ? Server::query()->where('zone_id', $zone->id)->get() : Server::query()->get();
        $services = $zone ? Zones::servicesIn($zone)->with('server')->get() : Service::query()->with('server')->get();

        // A service on a server outside the zone still needs that server's address; it just is not shown itself.
        $extra = Server::query()->whereIn('id', $services->pluck('server_id')->filter()->diff($servers->pluck('id')))->get();

        return [
            'version' => 1,
            'sent_at' => now()->toIso8601String(),
            'zone' => $zone?->name,
            'settings' => array_intersect_key(Settings::all(), array_flip(self::SETTINGS)),
            'zones' => self::rows(Zone::query()->get(), self::ZONE),
            'servers' => array_merge(self::rows($servers, self::SERVER), array_map(fn ($r) => ['on_dashboard' => false] + $r, self::rows($extra, self::SERVER))),
            'services' => self::rows($services, self::SERVICE),
            'links' => self::rows(Link::query()->get(), self::LINK),
        ];
    }

    /** @param list<string> $columns @return list<array<string, mixed>> */
    private static function rows(Collection $models, array $columns): array
    {
        return $models->map(function ($model) use ($columns) {
            $row = [];
            foreach ($columns as $column) {
                $value = $model->{$column};
                $row[$column] = $value instanceof \DateTimeInterface ? $value->format(DATE_ATOM) : $value;
            }

            return $row;
        })->values()->all();
    }
}
