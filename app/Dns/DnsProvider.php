<?php

namespace App\Dns;

/**
 * The seam for pushing a DNS plan to a provider (a router's resolver, AdGuard Home, Cloudflare, ...). Nothing
 * implements it yet: LabMan only produces the plan and exports it. A provider would receive the records of the
 * resolver it is authoritative for ('inside' or 'public'), exactly as DnsPlan::build() lists them, and report
 * what it created or changed. It must never delete records it did not create.
 */
interface DnsProvider
{
    /** Which resolver of the plan this provider answers for: 'inside' or 'public'. */
    public function resolver(): string;

    /**
     * @param  list<array{name: string, type: string, value: ?string, kind: string, zone: string, resolver: string, note: string}>  $records
     * @return list<array{name: string, outcome: 'created'|'updated'|'unchanged'|'failed', message: string}>
     */
    public function push(array $records): array;
}
