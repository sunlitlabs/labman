<?php

namespace App\Dns;

use App\Support\Secrets;

/**
 * Who owns a name two zones both want. A domain two zones list (the same wildcard) and a host name two zones both
 * use are never guessed: LabMan shows the collision and the admin picks the owning zone, which is remembered here.
 * (A more specific sub-domain zone always beats its parent by the longest-suffix rule; this is for exact ties.)
 */
final class DnsOwners
{
    private const KEY = 'dns.owners';

    /** @return array{domains: array<string, int>, names: array<string, int>} */
    public static function all(): array
    {
        $data = json_decode((string) Secrets::plain(self::KEY), true);

        return [
            'domains' => is_array($data['domains'] ?? null) ? array_map('intval', $data['domains']) : [],
            'names' => is_array($data['names'] ?? null) ? array_map('intval', $data['names']) : [],
        ];
    }

    public static function domainOwner(string $domain): ?int
    {
        return self::all()['domains'][mb_strtolower($domain)] ?? null;
    }

    public static function nameOwner(string $name): ?int
    {
        return self::all()['names'][mb_strtolower($name)] ?? null;
    }

    /** @param 'domain'|'name' $kind */
    public static function set(string $kind, string $key, int $zoneId): void
    {
        $all = self::all();
        $all[$kind === 'domain' ? 'domains' : 'names'][mb_strtolower($key)] = $zoneId;
        Secrets::putPlain(self::KEY, json_encode($all));
    }

    /** @param 'domain'|'name' $kind */
    public static function clear(string $kind, string $key): void
    {
        $all = self::all();
        unset($all[$kind === 'domain' ? 'domains' : 'names'][mb_strtolower($key)]);
        Secrets::putPlain(self::KEY, json_encode($all));
    }
}
