<?php

namespace App\Support;

use App\Models\Server;
use App\Models\Service;

/**
 * A best guess at a local domain name for every service and server that has none: its name turned into a host
 * label, under the default local domain from Settings (wiki -> wiki.<default local domain>). Only ever a suggestion:
 * callers offer it behind an opt-in switch (off by default) and nothing is saved without it being chosen.
 */
final class DomainSuggestions
{
    /** True when there is a local domain to build names under. */
    public static function available(): bool
    {
        return Domains::defaultFor('local') !== null;
    }

    /**
     * @return array{services: array<int, string>, servers: array<int, string>} id => suggested name
     */
    public static function all(): array
    {
        $out = ['services' => [], 'servers' => []];

        if (! self::available()) {
            return $out;
        }

        $services = Service::query()->orderBy('name')->get();
        $servers = Server::query()->orderBy('name')->get();

        // Never suggest a name something already has, or suggest the same one twice.
        $taken = [];
        foreach ([...$services, ...$servers] as $item) {
            foreach (Domains::namesOf($item) as $list) {
                foreach ($list as $name) {
                    $taken[$name] = true;
                }
            }
        }

        foreach ([['services', $services], ['servers', $servers]] as [$key, $items]) {
            foreach ($items as $item) {
                if (Domains::namesOf($item)['local'] !== []) {
                    continue;
                }
                $name = Domains::suggest($item->name, 'local');
                if ($name !== null && ! isset($taken[$name])) {
                    $taken[$name] = true;
                    $out[$key][$item->id] = $name;
                }
            }
        }

        return $out;
    }
}