<?php

namespace App\Mcp\Tools;

use App\Dns\DnsExport;
use App\Dns\DnsOwners;
use App\Dns\DnsPlan;
use App\Domain\ZoneInput;
use App\Mcp\McpContext;
use App\Mcp\Tool;
use App\Mcp\ToolException;
use App\Models\Mirror;
use App\Models\ProxyLog;
use App\Models\Server;
use App\Models\Service;
use App\Models\Zone;
use App\Npm\CertificateChooser;
use App\Npm\Certificates;
use App\Npm\Connection;
use App\Npm\Mirrors;
use App\Npm\NpmClient;
use App\Npm\NpmException;
use App\Npm\NpmSync;
use App\Npm\PlanText;
use App\Support\Settings;
use App\Support\ZoneStatus;
use App\Support\Zones;
use Closure;

/**
 * Zones over MCP: the zones themselves, who is in them, each zone's proxy (the NPM tools take a `zone` too), the
 * grouped preview, certificates, mirrors and the DNS plan. Everything here is admin-only, goes through the same
 * validation as the screens, and never returns a password or token. Writes to a proxy are always a preview and then an
 * apply of the items the user approved; LabMan never deletes anything in NPM.
 */
final class ZoneTools
{
    /** The `zone` argument the NPM tools share. @return array<string, mixed> */
    public static function zoneProperty(): array
    {
        return ['zone' => Tool::str('The zone (name or id) whose proxy this is about. Leave out for the default zone.')];
    }

    /**
     * The zone an NPM or zone tool is about: the one named, else the default zone.
     *
     * @param  array<string, mixed>  $a
     *
     * @throws ToolException
     */
    public static function zone(array $a, string $key = 'zone'): Zone
    {
        if (($a[$key] ?? null) === null || $a[$key] === '') {
            return Zones::default() ?? throw new ToolException('There are no zones.');
        }

        return ZoneInput::find($a[$key]) ?? throw new ToolException("There is no zone '{$a[$key]}'. Zones: ".ZoneInput::names().'. Use list_zones.');
    }

    /**
     * Said whenever a tool acted on the default zone because none was named, but there are several: so a client never
     * thinks it handled everything.
     *
     * @param  array<string, mixed>  $a
     * @return array{hint?: string}
     */
    public static function hint(array $a, Zone $zone): array
    {
        if (($a['zone'] ?? null) !== null && $a['zone'] !== '' || ! Zones::multiple()) {
            return [];
        }

        return ['hint' => 'This install has '.Zone::query()->count()." zones; no zone was given, so this acted on the default zone ({$zone->name}). Pass zone to choose another (".ZoneInput::names().'), or use npm_preview_all to see every proxy.'];
    }

    /** @return list<Tool> */
    public static function all(): array
    {
        $zone = self::zoneProperty();
        $settings = [
            'name' => Tool::str('Unique zone name, max 60 characters.'),
            'level' => Tool::int('0 to 100; higher is more trusted (for example management 90, internal 70, LAN 40, external 10). Advisory: LabMan only warns on it. Default 50.'),
            'color' => Tool::str('Optional colour as #rrggbb.'),
            'icon' => Tool::str('Optional icon name.'),
            'cidrs' => Tool::strs('Network blocks that suggest this zone for a server from its address, e.g. 192.0.2.0/24.'),
            'local_domains' => Tool::strs('Domains that resolve on your network, e.g. lab.example.com. (The default zone keeps its domains in the general settings; this changes those.)'),
            'public_domains' => Tool::strs('Domains reachable from the internet, e.g. example.dev.'),
            'proxy_address' => Tool::str('The address other zones'."'".' proxies forward to for this zone'."'".'s hosts (an IP or a stable internal name).'),
            'notes' => Tool::str('Free text, max 500 characters.'),
            'certificates' => ['type' => 'object', 'description' => 'Certificate policy; give only what changes. strategy: existing | letsencrypt_http | letsencrypt_dns | none (what a host with no certificate gets; a covering certificate is always used first). edge_managed: true when a CDN or tunnel terminates TLS publicly (never requests Let'."'".'s Encrypt here). pins: [{domain, certificate_id}] always use that certificate under that domain. origin_certificates: [ids] browsers do not trust. letsencrypt_email, dns_template (id of a DNS-challenge certificate whose provider is reused; LabMan never stores the token), wildcard (request one wildcard for the domain).', 'additionalProperties' => true],
            'dns' => ['type' => 'object', 'description' => 'DNS plan settings: {split_horizon: bool, public_address: string}.', 'additionalProperties' => true],
            'mirrors' => ['type' => 'array', 'description' => 'The zones whose hosts this zone mirrors (off when empty): [{zone: name or id, upstream: auto | http | https | https_insecure}].', 'items' => ['type' => 'object', 'additionalProperties' => true]],
            'is_default' => Tool::bool('Make this the default zone (where servers go when no zone is chosen).'),
        ];
        $selection = ['type' => 'array', 'description' => 'The preview items to apply, copied exactly from the preview: {zone, key, fingerprint}. Anything that changed since the preview is refused.', 'items' => ['type' => 'object', 'properties' => ['zone' => ['type' => 'string'], 'key' => ['type' => 'string'], 'fingerprint' => ['type' => 'string']], 'required' => ['zone', 'key', 'fingerprint'], 'additionalProperties' => false]];

        return [
            new Tool('list_zones', 'List zones', 'Read-only. Every zone, most trusted first: level, default, network blocks, domains, proxy address, DNS and certificate settings, which zones it mirrors, server and service counts, and whether it has a proxy (with its last checked status). Never returns a password or token.',
                [], [], 'admin', true, false, function () {
                    $zones = Zones::all();

                    return ['zones' => $zones->map(fn (Zone $z) => self::present($z))->all(), 'next' => $zones->count() < 2
                        ? 'There is one zone, so nothing is separated yet. create_zone adds another; assign_server_zone places servers in it.'
                        : 'update_zone changes a zone; assign_server_zone moves a server; npm_set_connection (with zone) connects a zone\'s proxy; npm_preview_all previews every proxy.'];
                }),

            new Tool('create_zone', 'Create a zone', 'Add a trust zone. Only name is required. Nothing is written to a proxy or to DNS.',
                $settings, ['name'], 'admin', false, false,
                fn (array $a) => ['zone' => self::present(ZoneInput::apply($a)), 'next' => 'assign_server_zone places servers in it; npm_set_connection (with zone) gives it a proxy.']),

            new Tool('update_zone', 'Change a zone', 'Change a zone. Give zone (name or id) and only the settings that change; everything else is kept. Certificates, dns and mirrors are merged / replaced as described on each argument. Making another zone the default swaps the two sets of domains instead of losing them.',
                ['zone' => Tool::str('The zone to change (name or id).')] + $settings, ['zone'], 'admin', false, false,
                function (array $a) {
                    $target = self::zone($a);
                    unset($a['zone']);

                    return ['zone' => self::present(ZoneInput::apply($a, $target))];
                }),

            new Tool('delete_zone', 'Delete a zone', 'Delete a zone that has no servers. The default zone and a zone with servers are refused, with the reason. Services that named the zone themselves go back to following their server. Nothing in its proxy is touched.',
                ['zone' => Tool::str('The zone to delete (name or id).')], ['zone'], 'admin', false, true,
                function (array $a) {
                    $target = self::zone($a);
                    try {
                        ZoneInput::delete($target);
                    } catch (\RuntimeException $e) {
                        throw new ToolException($e->getMessage());
                    }

                    return ['deleted' => $target->name];
                }),

            new Tool('assign_server_zone', 'Put a server in a zone', 'Move a server into a zone (a service follows its server unless it names a zone itself). zone_for_address says which zone a server\'s address fits.',
                ['server' => Tool::str('Server name or id.'), 'zone' => Tool::str('The zone (name or id).')], ['server', 'zone'], 'admin', false, false,
                function (array $a) {
                    $server = ctype_digit((string) $a['server']) ? Server::find((int) $a['server']) : Server::query()->where('name', $a['server'])->first();
                    $server ?? throw new ToolException("There is no server '{$a['server']}'. Use list_servers.");
                    $zone = self::zone(['zone' => $a['zone']]);
                    ZoneInput::assign($server, $zone);

                    return ['server' => $server->name, 'zone' => $zone->name, 'next' => 'Its services follow it. npm_push_preview (with zone) shows what changes in the proxies.'];
                }),

            new Tool('set_service_zone', 'Set a service\'s zone options', 'A service normally follows its server\'s zone. Here: the zone it names itself (empty to follow the server again), its own certificate choice (auto, none, letsencrypt_http, letsencrypt_dns or existing:<NPM certificate id>), and the zones it should also be published in (mirrors).',
                ['service' => Tool::str('Service name or id.'), 'zone' => Tool::str('Zone name or id; empty string to follow the server.'), 'certificate' => Tool::str('auto, none, letsencrypt_http, letsencrypt_dns or existing:<id>.'),
                    'publish_in' => ['type' => 'array', 'description' => 'Zones to also publish in: [{zone, upstream: auto | http | https | https_insecure}]. An empty list clears it.', 'items' => ['type' => 'object', 'additionalProperties' => true]]],
                ['service'], 'admin', false, false,
                function (array $a) {
                    $service = ctype_digit((string) $a['service']) ? Service::find((int) $a['service']) : Service::query()->where('name', $a['service'])->first();
                    $service ?? throw new ToolException("There is no service '{$a['service']}'. Use list_services.");
                    $changes = [];

                    if (array_key_exists('zone', $a)) {
                        $changes['zone_id'] = ($a['zone'] === null || $a['zone'] === '') ? null : self::zone(['zone' => $a['zone']])->id;
                    }
                    if (array_key_exists('certificate', $a)) {
                        $mode = $a['certificate'] === 'auto' || $a['certificate'] === '' ? null : (string) $a['certificate'];
                        if ($mode !== null && ! preg_match('/^(none|letsencrypt_http|letsencrypt_dns|existing:[1-9][0-9]*)$/', $mode)) {
                            throw new ToolException('certificate must be auto, none, letsencrypt_http, letsencrypt_dns or existing:<NPM certificate id>.');
                        }
                        $changes['cert_mode'] = $mode;
                    }
                    if (array_key_exists('publish_in', $a)) {
                        $refs = [];
                        foreach ((array) $a['publish_in'] as $ref) {
                            $z = self::zone(['zone' => is_array($ref) ? ($ref['zone'] ?? null) : $ref]);
                            $up = is_array($ref) ? ($ref['upstream'] ?? 'auto') : 'auto';
                            if (! in_array($up, Mirrors::UPSTREAMS, true)) {
                                throw new ToolException('upstream must be one of: '.implode(', ', Mirrors::UPSTREAMS).'.');
                            }
                            $refs[] = ['zone_id' => $z->id, 'upstream' => $up];
                        }
                        $changes['publish_zones'] = Mirrors::refs($refs) ?: null;
                    }
                    $service->update($changes);
                    $service = $service->fresh();

                    return ['service' => $service->name, 'zone' => Zones::of($service)?->name, 'zone_set_on_service' => $service->zone_id !== null, 'certificate' => $service->cert_mode ?? 'auto',
                        'publish_in' => array_map(fn ($r) => ['zone' => Zone::find($r['zone_id'])?->name, 'upstream' => $r['upstream']], Mirrors::refs($service->publish_zones)),
                        'next' => 'Nothing changes in a proxy until npm_push_preview and npm_push_apply.'];
                }),

            new Tool('zone_for_address', 'Which zone fits an address', 'Read-only. The zone whose network blocks contain an address (the narrowest wins), or none. Use it to place new servers.',
                ['address' => Tool::str('An IPv4 or IPv6 address.')], ['address'], 'admin', true, false,
                function (array $a) {
                    $zone = Zones::suggestFor((string) $a['address']);

                    return ['address' => $a['address'], 'zone' => $zone?->name, 'next' => $zone ? 'assign_server_zone puts a server there.' : 'No zone has a network block for it; set cidrs on a zone with update_zone.'];
                }),

            new Tool('npm_preview_all', 'Preview every proxy', 'Read-only. One grouped preview: a section per zone that has a proxy, each with its items (key, fingerprint, a plain-language summary, warnings, errors) and its own error if that proxy cannot be reached (the others still work). mode is push (LabMan -> NPM, the default) or import (NPM -> LabMan). Show the user the sections, then call npm_apply_all with what they approve.',
                ['mode' => Tool::str('push (default) or import.', ['push', 'import'])], [], 'admin', true, false,
                function (array $a) {
                    $mode = ($a['mode'] ?? 'push') === 'import' ? 'import' : 'push';
                    $sections = [];
                    foreach (Connection::connectedZones() as $z) {
                        $section = ['zone' => $z->name, 'items' => []];
                        try {
                            $sync = new NpmSync(NpmClient::fromStored($z), $z);
                            $section['items'] = NpmTools::present($mode === 'import' ? $sync->importPlan() : $sync->pushPlan(), $z->name, $mode === 'import');
                        } catch (NpmException $e) {
                            $section['error'] = $e->getMessage();
                        }
                        $sections[] = $section;
                    }
                    if ($sections === []) {
                        throw new ToolException('No zone has a Nginx Proxy Manager connection yet. npm_set_connection (with zone) connects one.');
                    }

                    return ['mode' => $mode, 'sections' => $sections, 'next' => 'Show the user each section; then npm_apply_all with the items they approve ({zone, key, fingerprint}).'];
                }),

            new Tool('npm_apply_all', 'Apply previewed changes to several proxies', 'WRITES: applies the approved items of a grouped preview, each to its own zone\'s proxy (push writes to NPM and never deletes; import writes to LabMan only). One proxy failing does not stop the others, and an item that changed since the preview is refused for that zone only. Items with an error cannot be applied.',
                ['mode' => Tool::str('push (default) or import; the same as the preview.', ['push', 'import']), 'items' => $selection], ['items'], 'admin', false, true,
                function (array $a, McpContext $ctx) {
                    $mode = ($a['mode'] ?? 'push') === 'import' ? 'import' : 'push';
                    $byZone = [];
                    foreach ((array) $a['items'] as $item) {
                        if (! is_array($item) || ! isset($item['zone'], $item['key'], $item['fingerprint'])) {
                            throw new ToolException('Each item needs a zone, a key and a fingerprint, exactly as the preview returned them.');
                        }
                        $byZone[self::zone(['zone' => $item['zone']])->id][(string) $item['key']] = (string) $item['fingerprint'];
                    }
                    if ($byZone === []) {
                        throw new ToolException('Select at least one previewed item to apply.');
                    }

                    $out = [];
                    foreach ($byZone as $zoneId => $selected) {
                        $z = Zone::findOrFail($zoneId);
                        try {
                            $sync = new NpmSync(NpmClient::fromStored($z), $z);
                            $result = $mode === 'import' ? $sync->applyImport($selected) : $sync->applyPush($selected);
                            ProxyLog::record($z, $mode === 'import' ? 'import' : 'preview', $result['results'], 'MCP ('.$ctx->token->name.')');
                            ZoneStatus::forget($z);
                            $out[] = ['zone' => $z->name] + $result;
                        } catch (NpmException $e) {
                            $out[] = ['zone' => $z->name, 'applied' => 0, 'results' => [], 'error' => $e->getMessage()];
                        }
                    }

                    return ['zones' => $out, 'next' => 'Tell the user what was applied and what failed; npm_preview_all again shows what is left.'];
                }),

            new Tool('npm_list_certificates', 'List a proxy\'s certificates', 'Read-only. The certificates in a zone\'s NPM (id, name, covered names, days left, provider, whether the zone marks it as an origin certificate) and where every host name of the zone stands: ok, expiring, expired, uncovered, untrusted, missing, none, unknown or no_host. A certificate that already covers a name is always used before a new one is requested.',
                $zone, [], 'admin', true, false,
                function (array $a) {
                    $z = self::zone($a);

                    return self::npm(function () use ($a, $z) {
                        $client = NpmClient::fromStored($z);
                        $policy = $z->certificatePolicy();
                        $certs = array_map(fn ($c) => ['id' => $c['id'], 'name' => $c['name'], 'domains' => $c['domains'], 'days_left' => Certificates::daysLeft($c), 'expires_on' => $c['expires_on'], 'provider' => $c['provider'], 'origin' => in_array($c['id'], $policy->originIds, true)], $client->certificates());
                        $report = (new NpmSync($client, $z))->certificateReport();

                        return ['zone' => $z->name, 'certificates' => $certs, 'hosts' => $report['rows'], 'counts' => $report['counts'], 'policy' => $policy->toArray() + ['strategy' => $policy->strategy],
                            'next' => 'npm_match_certificate shows which certificate a name would get; npm_push_preview (with zone) proposes the fixes.'] + self::hint($a, $z);
                    });
                }),

            new Tool('npm_match_certificate', 'Which certificate would a name get', 'Read-only. For a host name, the certificate the zone\'s proxy would use and why: one that already covers it (exact or a one-label wildcard) first, then the zone\'s pins and strategy; or what is missing. Nothing is requested or changed.',
                $zone + ['host' => Tool::str('The host name, e.g. wiki.example.dev.')], ['host'], 'admin', true, false,
                function (array $a) {
                    $z = self::zone($a);
                    $host = mb_strtolower(trim((string) $a['host']));

                    return self::npm(function () use ($a, $z, $host) {
                        $certs = NpmClient::fromStored($z)->certificates();
                        $legacy = $z->is_default ? (int) Settings::get('npm_cert_'.($z->scopeOf($host) ?? 'local')) : 0;
                        $d = CertificateChooser::choose($host, $z->certificatePolicy(), $certs, null, $legacy, false, $z->domainNames());

                        return ['zone' => $z->name, 'host' => $host, 'mode' => $d['mode'], 'certificate_id' => $d['certificate_id'], 'why' => $d['why'], 'would_request' => $d['mode'] === 'request' ? ['method' => $d['method'], 'domains' => $d['domains']] : null, 'warnings' => $d['warnings']] + self::hint($a, $z);
                    });
                }),

            new Tool('list_mirrors', 'List mirrors', 'Read-only. For each zone that mirrors another zone\'s hosts (a zone rule or a service\'s publish_in): the mirrors it should hold, whether LabMan has created each (and the NPM host id), and orphaned ones that nothing asks for any more (to remove in NPM by hand, then forget_mirror).',
                [], [], 'admin', true, false,
                function () {
                    $all = Zone::query()->get();
                    $out = [];
                    foreach (Zones::all() as $z) {
                        $wanted = Mirrors::wanted($z, null, $all);
                        $records = Mirror::query()->where('zone_id', $z->id)->get()->keyBy('domain');
                        if ($wanted === [] && $records->isEmpty()) {
                            continue;
                        }
                        $out[] = [
                            'zone' => $z->name, 'connected' => Connection::exists($z),
                            'mirrors' => array_values(array_map(fn ($w) => ['domain' => $w['domain'], 'service' => $w['service']->name, 'from_zone' => $w['source']->name, 'upstream' => $w['upstream'], 'created' => $records->has($w['domain']), 'npm_host_id' => $records->get($w['domain'])?->npm_host_id], $wanted)),
                            'orphans' => Mirrors::orphans($z, $wanted)->map(fn (Mirror $m) => ['id' => $m->id, 'domain' => $m->domain, 'was_mirror_of' => $m->service_name, 'npm_host_id' => $m->npm_host_id])->all(),
                        ];
                    }

                    return ['zones' => $out, 'next' => $out === [] ? 'No mirrors are set up. update_zone (mirrors) or set_service_zone (publish_in) turns them on.' : 'mirror_preview shows what would be created or updated in a zone\'s proxy; npm_push_apply applies it.'];
                }),

            new Tool('mirror_preview', 'Preview a zone\'s mirrors', 'Read-only. The mirrors a zone\'s proxy would create, update or leave alone, each with a key and fingerprint (apply with npm_push_apply, with the same zone), plus orphaned mirrors. A mirror forwards to the source zone\'s proxy address, never the public name, keeping the Host header and SNI, and needs a browser-trusted certificate on this proxy.',
                $zone, [], 'admin', true, false,
                function (array $a) {
                    $z = self::zone($a);

                    return self::npm(function () use ($a, $z) {
                        $items = array_values(array_filter((new NpmSync(NpmClient::fromStored($z), $z))->pushPlan(), fn ($i) => str_starts_with($i['key'], 'mirror:') || str_starts_with($i['key'], 'mirror-orphan:')));

                        return ['zone' => $z->name, 'items' => NpmTools::present($items, $z->name, false), 'next' => 'Show the user; npm_push_apply (with zone) applies the mirror items they approve.'] + self::hint($a, $z);
                    });
                }),

            new Tool('forget_mirror', 'Forget a mirror', 'Stop tracking a mirror in LabMan (after the proxy host was removed in NPM by hand). Nothing is changed in NPM.',
                ['id' => Tool::int('The mirror id from list_mirrors (orphans).')], ['id'], 'admin', false, false,
                function (array $a) {
                    $m = Mirror::find((int) $a['id']) ?? throw new ToolException('There is no mirror with that id. Use list_mirrors.');
                    $m->delete();

                    return ['forgotten' => $m->domain, 'note' => 'Nothing was changed in NPM.'];
                }),

            new Tool('dns_plan', 'The DNS plan', 'Read-only; LabMan never writes DNS. The records the zones need, per resolver (inside = your network, public = your provider): a wildcard per zone domain pointing at the zone\'s proxy (or its public address), a record per server at its own address, explicit records for service names a wildcard does not cover, and each mirror\'s name answered inside by the mirroring zone\'s proxy. Conflicts are listed, never guessed: collisions need an owner (dns_set_owner). format json (default) | text | hosts | adguard.',
                ['format' => Tool::str('json (default), text, hosts or adguard.', ['json', 'text', 'hosts', 'adguard'])], [], 'admin', true, false,
                function (array $a) {
                    $plan = DnsPlan::build();
                    $format = $a['format'] ?? 'json';
                    $open = DnsPlan::open($plan['conflicts']);
                    $next = $open !== [] ? 'Collisions need an owner before their records appear: dns_set_owner (kind domain or name, key, zone).' : 'Create these records in your resolvers; LabMan does not write DNS.';

                    if ($format !== 'json') {
                        return ['format' => $format, 'text' => DnsExport::render($plan, $format), 'open_conflicts' => count($open), 'next' => $next];
                    }

                    return ['records' => array_map(fn ($r) => array_intersect_key($r, array_flip(['name', 'type', 'value', 'kind', 'zone', 'resolver', 'note'])), $plan['records']), 'conflicts' => $plan['conflicts'], 'open_conflicts' => count($open), 'next' => $next];
                }),

            new Tool('dns_set_owner', 'Settle a name or domain collision', 'When two zones list the same domain, or use the same host name, the DNS plan makes no record for it until an owner is picked. This picks the zone that owns it. (A more specific sub-domain zone wins by the longest suffix without this.)',
                ['kind' => Tool::str('domain or name.', ['domain', 'name']), 'key' => Tool::str('The domain or host name from the conflict.'), 'zone' => Tool::str('The owning zone (name or id).')], ['kind', 'key', 'zone'], 'admin', false, false,
                function (array $a) {
                    $z = self::zone(['zone' => $a['zone']]);
                    DnsOwners::set($a['kind'] === 'domain' ? 'domain' : 'name', (string) $a['key'], $z->id);

                    return ['owner' => $z->name, 'key' => mb_strtolower((string) $a['key']), 'next' => 'dns_plan shows the result; the other zone can mirror the owner (update_zone mirrors).'];
                }),
        ];
    }

    /** One zone for a tool's output. No secrets: a proxy shows only whether it is connected and the last checked status. @return array<string, mixed> */
    public static function present(Zone $z): array
    {
        $status = ZoneStatus::cached($z);
        $policy = $z->certificatePolicy();

        return [
            'id' => $z->id, 'name' => $z->name, 'level' => $z->level, 'default' => (bool) $z->is_default, 'color' => $z->color, 'icon' => $z->icon,
            'cidrs' => $z->cidrs ?? [], 'domains' => $z->domains(), 'proxy_address' => $z->proxy_address, 'notes' => $z->notes,
            'dns' => $z->dnsSettings(), 'certificates' => $policy->toArray() + ['strategy' => $policy->strategy],
            'mirrors' => array_map(fn ($r) => ['zone' => Zone::find($r['zone_id'])?->name, 'upstream' => $r['upstream']], Mirrors::refs($z->mirror_sources)),
            'servers' => Zones::serversIn($z)->count(), 'services' => Zones::servicesIn($z)->count(),
            'proxy' => ['connected' => Connection::exists($z), 'last_status' => $status ? ['state' => $status['state'], 'checked_at' => $status['checked_at'], 'hosts' => $status['hosts'] ?? null] : null],
        ];
    }

    /** Run an NPM operation and turn its failures into tool errors. */
    public static function npm(Closure $run): array
    {
        try {
            return $run();
        } catch (NpmException $e) {
            throw new ToolException($e->getMessage());
        }
    }
}
