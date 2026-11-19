<?php

namespace App\Inventory;

use App\Models\Server;

/**
 * Pairs LabMan servers with the hosts of an inventory (a git file or a Semaphore inventory): the saved link first,
 * then the same name (exact or Ansible-safe), then the same address. One host is used at most once.
 */
final class HostMatcher
{
    /**
     * @param  iterable<Server>  $servers
     * @param  array<string, array<string, mixed>>  $hosts  as Inventory::hosts() (only "vars" is read)
     * @return list<array{server: Server, host: string}> in the servers' own order
     */
    public static function pair(iterable $servers, array $hosts): array
    {
        $lower = [];
        foreach (array_keys($hosts) as $name) {
            $lower[mb_strtolower((string) $name)] = (string) $name;
        }

        $byAddress = [];
        foreach ($hosts as $name => $info) {
            $address = self::address((string) $name, $info['vars'] ?? []);
            if ($address !== null) {
                $byAddress[mb_strtolower($address)][] = (string) $name;
            }
        }

        $used = [];
        $paired = [];

        // Pass 1: by the saved link or by name, for every server, so a name match always beats an address match.
        foreach ($servers as $server) {
            foreach ([$server->inventory_host, $server->name, Slug::host($server->name)] as $candidate) {
                $name = $candidate !== null ? ($lower[mb_strtolower($candidate)] ?? null) : null;
                if ($name !== null && ! isset($used[$name])) {
                    $used[$name] = true;
                    $paired[$server->id] = ['server' => $server, 'host' => $name];
                    break;
                }
            }
        }

        // Pass 2: by address, only among what is left, and only when exactly one host qualifies.
        foreach ($servers as $server) {
            if (isset($paired[$server->id])) {
                continue;
            }

            $same = array_values(array_filter($byAddress[mb_strtolower($server->address)] ?? [], fn ($n) => ! isset($used[$n])));
            if (count($same) === 1) {
                $used[$same[0]] = true;
                $paired[$server->id] = ['server' => $server, 'host' => $same[0]];
            }
        }

        $pairs = [];
        foreach ($servers as $server) {
            if (isset($paired[$server->id])) {
                $pairs[] = $paired[$server->id];
            }
        }

        return $pairs;
    }

    /** The address Ansible connects to, or null when it is a template we cannot evaluate. @param array<string, mixed> $vars */
    public static function address(string $host, array $vars): ?string
    {
        $value = (string) ($vars['ansible_host'] ?? $vars['ansible_ssh_host'] ?? $host);

        return str_contains($value, '{{') ? null : $value;
    }
}
