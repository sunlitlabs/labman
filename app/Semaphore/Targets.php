<?php

namespace App\Semaphore;

use App\Inventory\Slug;
use App\Models\Server;

/**
 * What a run can be limited to, built from LabMan's server list: groups and individual machines. When the host
 * list of the Semaphore inventory is known, a machine is called by the name Semaphore knows it by, and one that
 * Semaphore does not have is flagged (a limit naming it would match nothing there).
 */
final class Targets
{
    /** @return array{groups: list<array{name: string, count: int}>, hosts: list<array{name: string, label: string, group: ?string, missing: bool}>, known: bool} */
    public static function all(): array
    {
        $servers = Server::query()->orderBy('group')->orderBy('name')->get();
        $paired = HostSync::pairings($servers);
        $known = HostSync::known();

        $groups = [];
        foreach ($servers as $s) {
            if ($s->group) {
                $g = Slug::group($s->group);
                $groups[$g] = ($groups[$g] ?? 0) + 1;
            }
        }
        // Groups that only exist in Semaphore are valid limits too.
        foreach ($known['hosts'] ?? [] as $info) {
            foreach ($info['groups'] as $g) {
                $groups[$g] ??= 0;
            }
        }
        ksort($groups);

        $hosts = $servers->map(fn (Server $s) => [
            'name' => $paired[$s->id] ?? ($s->inventory_host ?: Slug::host($s->name)),
            'label' => $s->name,
            'group' => $s->group ? Slug::group($s->group) : null,
            'missing' => $paired !== null && ! isset($paired[$s->id]),
        ])->all();

        return [
            'groups' => array_map(fn ($name, $count) => ['name' => (string) $name, 'count' => $count], array_keys($groups), array_values($groups)),
            'hosts' => $hosts,
            'known' => $paired !== null,
        ];
    }
}
