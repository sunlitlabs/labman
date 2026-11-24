<?php

namespace App\Config;

use App\Models\Server;
use App\Models\Service;
use App\Models\Zone;
use App\Support\DomainSuggestions;
use App\Support\Domains;
use App\Support\ExpectRange;
use App\Support\Hosts;
use App\Support\Settings;
use App\Support\Zones;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Parse, validate, import and export the LabMan YAML config (see labman.example.yml).
 * Entries are numbered from 1 in messages: "servers[2]: 'name' is required".
 */
final class YamlConfig
{
    private const SETTINGS_KEYS = ['title', 'check_interval', 'timeout', 'ssh_user', 'ssh_target', 'local_domains', 'public_domains', 'npm_cert_local', 'npm_cert_public'];

    /**
     * @return array{settings: array<string, mixed>, servers: list<array<string, mixed>>, services: list<array<string, mixed>>}
     *
     * @throws ConfigException
     */
    public static function parse(string $yaml): array
    {
        try {
            $data = Yaml::parse($yaml);
        } catch (ParseException $e) {
            throw new ConfigException(['YAML syntax error: '.$e->getMessage()]);
        }

        if (! is_array($data) || array_is_list($data) || ! array_key_exists('servers', $data) || ! array_key_exists('services', $data)) {
            throw new ConfigException(["the file must contain a mapping with 'servers' and 'services'"]);
        }

        $errors = [];

        $unknown = array_values(array_diff(array_keys($data), ['settings', 'zones', 'servers', 'services']));
        if ($unknown) {
            $errors[] = 'unknown top-level key(s): '.implode(', ', array_map('strval', $unknown));
        }

        $settings = self::settings($data['settings'] ?? null, $errors);
        $zones = self::zones($data['zones'] ?? null, $errors);
        $servers = self::servers($data['servers'], $errors);
        $services = self::services($data['services'], array_column($servers, 'name'), $errors);

        // A zone named on a server or service must be defined in the file or already exist.
        $known = array_map('mb_strtolower', [...array_column($zones, 'name'), ...Zone::query()->pluck('name')->all()]);
        foreach (['servers' => $servers, 'services' => $services] as $kind => $rows) {
            foreach ($rows as $row) {
                if (isset($row['zone']) && ! in_array(mb_strtolower($row['zone']), $known, true)) {
                    $errors[] = rtrim($kind, 's')." '{$row['name']}': zone '{$row['zone']}' is not defined under 'zones' and does not exist yet";
                }
            }
        }

        foreach ($zones as $z) {
            foreach ($z['mirrors'] ?? [] as $ref) {
                if (! in_array(mb_strtolower($ref['zone']), $known, true)) {
                    $errors[] = "zone '{$z['name']}': mirrors: zone '{$ref['zone']}' is not defined under 'zones' and does not exist yet";
                }
            }
        }
        foreach ($services as $s) {
            foreach ($s['publish_in'] ?? [] as $ref) {
                if (! in_array(mb_strtolower($ref['zone']), $known, true)) {
                    $errors[] = "service '{$s['name']}': publish_in: zone '{$ref['zone']}' is not defined under 'zones' and does not exist yet";
                }
            }
        }

        if ($errors) {
            throw new ConfigException($errors);
        }

        return ['settings' => $settings, 'zones' => $zones, 'servers' => $servers, 'services' => $services];
    }

    /**
     * Upsert by name inside one transaction; with $replace, anything not in the file is removed.
     *
     * @param  array{settings: array<string, mixed>, servers: list<array<string, mixed>>, services: list<array<string, mixed>>}  $config
     * @return array{servers: int, services: int, removed: int}
     */
    public static function import(array $config, bool $replace = false): array
    {
        $removed = 0;

        DB::transaction(function () use ($config, $replace, &$removed) {
            if ($config['settings']) {
                Settings::set($config['settings']);
            }

            // A file that sets the domain lists in its settings means them for the default zone, whichever zone that is.
            $settingsHaveDomains = isset($config['settings']['local_domains']) || isset($config['settings']['public_domains']);
            foreach ($config['zones'] ?? [] as $row) {
                $mirrors = $row['mirrors'] ?? null;
                unset($row['mirrors']);
                self::importZone($row, $settingsHaveDomains);
                $row['mirrors'] = $mirrors;
            }
            $zoneIds = Zone::query()->pluck('id', 'name')->mapWithKeys(fn ($id, $name) => [mb_strtolower($name) => $id])->all();
            $toRefs = fn (array $refs) => array_map(fn ($r) => ['zone_id' => $zoneIds[mb_strtolower($r['zone'])], 'upstream' => $r['upstream']], $refs);
            foreach ($config['zones'] ?? [] as $row) {
                if (isset($row['mirrors'])) {
                    Zone::query()->whereRaw('lower(name) = ?', [mb_strtolower($row['name'])])->first()?->update(['mirror_sources' => $toRefs($row['mirrors']) ?: null]);
                }
            }

            foreach ($config['servers'] as $row) {
                $zone = $row['zone'] ?? null;
                unset($row['zone']);
                $server = Server::query()->where('name', $row['name'])->first() ?? new Server;
                $server->fill($row);
                if ($zone !== null) {
                    $server->zone_id = $zoneIds[mb_strtolower($zone)];
                }
                $server->save();
            }

            $ids = Server::query()->pluck('id', 'name');

            foreach ($config['services'] as $row) {
                $serverName = $row['server'];
                unset($row['server']);
                $row['server_id'] = $serverName !== null ? ($ids[$serverName] ?? null) : null;
                $zone = $row['zone'] ?? null;
                $publish = $row['publish_in'] ?? null;
                unset($row['zone'], $row['publish_in']);

                $service = Service::query()->where('name', $row['name'])->first() ?? new Service;
                $service->fill($row);
                if ($zone !== null) {
                    $service->zone_id = $zoneIds[mb_strtolower($zone)];
                }
                if ($publish !== null) {
                    $service->publish_zones = $toRefs($publish) ?: null;
                }
                $service->save();
            }

            if ($replace) {
                $removed += Service::query()->whereNotIn('name', array_column($config['services'], 'name'))->delete();
                $removed += Server::query()->whereNotIn('name', array_column($config['servers'], 'name'))->delete();
            }
        });

        // "zones" is only reported when the file had some, so the result for a file without zones is unchanged.
        $zones = count($config['zones'] ?? []);

        return ['servers' => count($config['servers']), 'services' => count($config['services']), 'removed' => $removed] + ($zones ? ['zones' => $zones] : []);
    }

    /** Create or update a zone by name. The default zone's domains stay in the settings, so they are not touched here. */
    private static function importZone(array $row, bool $settingsHaveDomains = false): void
    {
        $zone = Zone::query()->whereRaw('lower(name) = ?', [mb_strtolower($row['name'])])->first() ?? new Zone;
        $wantsDefault = $row['default'] ?? false;
        unset($row['default']);

        if ($zone->is_default) {
            unset($row['domains']);
        }

        $zone->fill($row + ['level' => $zone->level ?? 50]);
        $zone->save();

        if ($wantsDefault && ! $zone->is_default) {
            $old = Zones::default();
            if ($old && $old->id !== $zone->id) {
                // The settings hold the default zone's domains. Domains set by this very file are the new default's;
                // otherwise they go to the old default, and the new default's own become the settings.
                $old->forceFill(['is_default' => false, 'domains' => $settingsHaveDomains ? null : (array_filter($old->domains()) ?: null)])->save();
                $own = $zone->domains();
                if (! $settingsHaveDomains || $own['local'] || $own['public']) {
                    Settings::set(['local_domains' => implode(', ', $own['local']), 'public_domains' => implode(', ', $own['public'])]);
                }
                $zone->forceFill(['domains' => null]);
            }
            $zone->forceFill(['is_default' => true])->save();
        }
    }

    public static function export(bool $suggestLocalDomains = false): string
    {
        $suggested = $suggestLocalDomains ? DomainSuggestions::all() : ['services' => [], 'servers' => []];

        Settings::flush();

        $settings = array_filter([
            'title' => Settings::get('title'),
            'check_interval' => Settings::get('check_interval'),
            'timeout' => Settings::get('timeout'),
            'ssh_user' => Settings::get('ssh_user') ?: null,
            'ssh_target' => Settings::get('ssh_target'),
            'local_domains' => Settings::get('local_domains') ?: null,
            'public_domains' => Settings::get('public_domains') ?: null,
            'npm_cert_local' => Settings::get('npm_cert_local') !== '' && Settings::get('npm_cert_local') !== null ? (int) Settings::get('npm_cert_local') : null,
            'npm_cert_public' => Settings::get('npm_cert_public') !== '' && Settings::get('npm_cert_public') !== null ? (int) Settings::get('npm_cert_public') : null,
        ], fn ($v) => $v !== null);

        $servers = Server::query()->orderBy('group')->orderBy('name')->get()->map(fn (Server $s) => array_filter([
            'name' => $s->name,
            'address' => $s->address,
            'domain' => $s->domain ?: ($suggested['servers'][$s->id] ?? null),
            'extra_domains' => self::extraList($s),
            'group' => $s->group,
            'ports' => $s->ports ?: null,
            'ssh_user' => $s->ssh_user,
            'ssh_port' => (int) $s->ssh_port !== 22 ? (int) $s->ssh_port : null,
            'notes' => $s->notes,
            'monitor' => $s->monitor ? null : false,
            'check_ssh' => $s->check_ssh ? null : false,
            'icon' => $s->icon,
        ], fn ($v) => $v !== null && $v !== ''))->all();

        $services = Service::query()->with('server')->orderBy('group')->orderBy('name')->get()->map(fn (Service $s) => array_filter([
            'name' => $s->name,
            'domain' => $s->domain ?: ($suggested['services'][$s->id] ?? null),
            'public_domain' => $s->public_domain,
            'extra_domains' => self::extraList($s),
            'server' => $s->server?->name,
            'address' => $s->server ? null : $s->address,
            'port' => $s->port,
            'scheme' => $s->scheme,
            'path' => $s->path,
            'group' => $s->group,
            'expect' => $s->expect,
            'verify_tls' => $s->verify_tls,
            'public_check' => $s->public_check,
            'websockets' => $s->websockets,
            'notes' => $s->notes,
            'health_path' => $s->health_path,
            'monitor' => $s->monitor ? null : false,
            'check_tcp' => $s->check_tcp ? null : false,
            'icon' => $s->icon,
            'certificate' => $s->cert_mode,
        ], fn ($v) => $v !== null && $v !== ''))->all();

        $document = ['settings' => $settings];

        // With a single zone the file stays exactly as it was before zones existed.
        if (Zones::multiple()) {
            $document['zones'] = Zones::all()->map(fn (Zone $z) => array_filter([
                'name' => $z->name,
                'level' => $z->level,
                'default' => $z->is_default ?: null,
                'color' => $z->color,
                'icon' => $z->icon,
                'cidrs' => $z->cidrs ?: null,
                // The default zone's domains are already settings.local_domains / public_domains.
                'domains' => $z->is_default ? null : (array_filter($z->domains()) ?: null),
                'proxy_address' => $z->proxy_address,
                'certificates' => $z->certificate_policy ?: null,
                'dns' => $z->dns ?: null,
                'mirrors' => \App\Npm\Mirrors::refs($z->mirror_sources) ? array_map(fn ($r) => ['zone' => Zone::query()->find($r['zone_id'])?->name, 'upstream' => $r['upstream']], \App\Npm\Mirrors::refs($z->mirror_sources)) : null,
                'notes' => $z->notes,
            ], fn ($v) => $v !== null && $v !== ''))->values()->all();

            $zoneNames = Zone::query()->pluck('name', 'id');
            $servers = array_map(fn ($row, $server) => $row + ['zone' => $zoneNames[$server->zone_id] ?? null], $servers, Server::query()->orderBy('group')->orderBy('name')->get()->all());
            $servers = array_map(fn ($row) => array_filter($row, fn ($v) => $v !== null), $servers);
            $services = array_map(fn ($row, $service) => $row + ($service->zone_id ? ['zone' => $zoneNames[$service->zone_id] ?? null] : []) + (\App\Npm\Mirrors::refs($service->publish_zones) ? ['publish_in' => array_map(fn ($r) => ['zone' => $zoneNames[$r['zone_id']] ?? null, 'upstream' => $r['upstream']], \App\Npm\Mirrors::refs($service->publish_zones))] : []), $services, Service::query()->orderBy('group')->orderBy('name')->get()->all());
            $services = array_map(fn ($row) => array_filter($row, fn ($v) => $v !== null), $services);
        }

        return "# LabMan config. Import with: php artisan labman:import <file>\n"
            .Yaml::dump($document + ['servers' => $servers, 'services' => $services], 4, 2);
    }

    // ---------------------------------------------------------------- settings

    /** @param list<string> $errors */
    private static function settings(mixed $raw, array &$errors): array
    {
        if ($raw === null) {
            return [];
        }

        if (! is_array($raw) || (! empty($raw) && array_is_list($raw))) {
            $errors[] = 'settings: must be a mapping';

            return [];
        }

        $out = [];

        $unknown = array_values(array_diff(array_keys($raw), self::SETTINGS_KEYS));
        if ($unknown) {
            $errors[] = 'settings: unknown key(s): '.implode(', ', array_map('strval', $unknown));
        }

        if (array_key_exists('ssh_target', $raw)) {
            if (in_array($raw['ssh_target'], ['address', 'domain'], true)) {
                $out['ssh_target'] = $raw['ssh_target'];
            } else {
                $errors[] = "settings: ssh_target must be 'address' or 'domain'";
            }
        }

        $numbers = [];
        foreach (['check_interval', 'timeout'] as $key) {
            if (array_key_exists($key, $raw)) {
                $numbers[$key] = $raw[$key];
            }
        }

        $badNumber = false;
        foreach ($numbers as $value) {
            if (! (is_int($value) || is_float($value))) {
                $badNumber = true;
            }
        }
        if ($badNumber) {
            $errors[] = 'settings: check_interval and timeout must be numbers';
        } else {
            if (isset($numbers['check_interval'])) {
                if ($numbers['check_interval'] < config('labman.limits.check_interval_min')) {
                    $errors[] = 'settings: check_interval must be at least '.config('labman.limits.check_interval_min').' seconds';
                } else {
                    $out['check_interval'] = $numbers['check_interval'];
                }
            }
            if (isset($numbers['timeout'])) {
                $min = config('labman.limits.timeout_min');
                $max = config('labman.limits.timeout_max');
                if ($numbers['timeout'] < $min || $numbers['timeout'] > $max) {
                    $errors[] = "settings: timeout must be between {$min} and {$max} seconds";
                } else {
                    $out['timeout'] = $numbers['timeout'];
                }
            }
        }

        foreach (['local_domains', 'public_domains'] as $key) {
            if (array_key_exists($key, $raw)) {
                $list = \App\Support\Domains::parse(is_array($raw[$key]) ? $raw[$key] : (is_scalar($raw[$key]) ? (string) $raw[$key] : ''));
                $bad = \App\Support\Domains::firstInvalid($list);
                if ($bad !== null) {
                    $errors[] = "settings: {$key} has an entry that is not a domain name: '{$bad}'";
                } else {
                    $out[$key] = implode(', ', $list);
                }
            }
        }

        foreach (['npm_cert_local', 'npm_cert_public'] as $key) {
            if (array_key_exists($key, $raw)) {
                if ($raw[$key] === null || $raw[$key] === '' || (is_int($raw[$key]) && $raw[$key] >= 0)) {
                    $out[$key] = (string) ($raw[$key] ?? '');
                } else {
                    $errors[] = "settings: {$key} must be an NPM certificate id (a whole number), or empty";
                }
            }
        }

        if (array_key_exists('title', $raw)) {
            $title = is_scalar($raw['title']) ? trim((string) $raw['title']) : '';
            if ($title === '' || mb_strlen($title) > 60) {
                $errors[] = 'settings: title must be 1-60 characters';
            } else {
                $out['title'] = $title;
            }
        }

        if (array_key_exists('ssh_user', $raw)) {
            $user = is_scalar($raw['ssh_user']) ? trim((string) $raw['ssh_user']) : null;
            if ($user === null || ($user !== '' && ! Hosts::isSafeUser($user))) {
                $errors[] = "settings: ssh_user may only contain letters, digits, '_', '.' and '-' and must not start with '-'";
            } else {
                $out['ssh_user'] = $user;
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------- zones

    /**
     * @param  list<string>  $errors
     * @return list<array<string, mixed>>
     */
    private static function zones(mixed $raw, array &$errors): array
    {
        if ($raw === null) {
            return [];
        }

        if (! is_array($raw) || (! empty($raw) && ! array_is_list($raw))) {
            $errors[] = "'zones' must be a list";

            return [];
        }

        $out = [];
        $seen = [];
        $defaults = 0;

        foreach ($raw as $i => $item) {
            $n = $i + 1;

            if (! is_array($item) || array_is_list($item)) {
                $errors[] = "zones[{$n}]: each zone must be a mapping";

                continue;
            }

            $name = self::text($item['name'] ?? null);
            if ($name === null || $name === '') {
                $errors[] = "zones[{$n}]: 'name' is required";

                continue;
            }

            $who = "zone '{$name}'";
            $before = count($errors);

            if (isset($seen[mb_strtolower($name)])) {
                $errors[] = "{$who}: duplicate zone name";
            }
            $seen[mb_strtolower($name)] = true;
            if (mb_strlen($name) > 60) {
                $errors[] = "{$who}: 'name' is too long (max 60)";
            }

            $row = ['name' => $name];

            if (array_key_exists('level', $item) && $item['level'] !== null) {
                if (is_int($item['level']) && $item['level'] >= 0 && $item['level'] <= 100) {
                    $row['level'] = $item['level'];
                } else {
                    $errors[] = "{$who}: 'level' must be a whole number from 0 to 100";
                }
            }

            $color = self::text($item['color'] ?? null);
            if ($color !== null && $color !== '') {
                if (preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                    $row['color'] = strtolower($color);
                } else {
                    $errors[] = "{$who}: 'color' must look like #2557d6";
                }
            }

            $row['icon'] = self::icon($who, $item, $errors);

            if (array_key_exists('default', $item)) {
                if (is_bool($item['default'])) {
                    $row['default'] = $item['default'];
                    $defaults += $item['default'] ? 1 : 0;
                } else {
                    $errors[] = "{$who}: 'default' must be true or false";
                }
            }

            if (array_key_exists('cidrs', $item) && $item['cidrs'] !== null) {
                $list = is_array($item['cidrs']) ? $item['cidrs'] : [];
                if (! is_array($item['cidrs']) || ! array_is_list($item['cidrs'])) {
                    $errors[] = "{$who}: 'cidrs' must be a list like [192.0.2.0/24]";
                } else {
                    foreach ($list as $cidr) {
                        if (! is_string($cidr) || ! Zones::validCidr($cidr)) {
                            $errors[] = "{$who}: '".(is_scalar($cidr) ? $cidr : '?')."' is not a network block like 192.0.2.0/24";
                        }
                    }
                    $row['cidrs'] = array_values(array_map('strval', $list));
                }
            }

            if (array_key_exists('domains', $item) && $item['domains'] !== null) {
                $domains = ['local' => [], 'public' => []];
                if (! is_array($item['domains']) || array_is_list($item['domains'])) {
                    $errors[] = "{$who}: 'domains' must be a mapping with 'local' and/or 'public' lists";
                } else {
                    foreach (['local', 'public'] as $scope) {
                        $names = Domains::parse(is_array($item['domains'][$scope] ?? null) ? $item['domains'][$scope] : (is_scalar($item['domains'][$scope] ?? null) ? (string) $item['domains'][$scope] : ''));
                        $bad = Domains::firstInvalid($names);
                        if ($bad !== null) {
                            $errors[] = "{$who}: domains.{$scope} has an entry that is not a domain name: '{$bad}'";
                        }
                        $domains[$scope] = $names;
                    }
                    $row['domains'] = $domains['local'] || $domains['public'] ? $domains : null;
                }
            }

            if (array_key_exists('certificates', $item) && $item['certificates'] !== null) {
                if (! is_array($item['certificates']) || ($item['certificates'] !== [] && array_is_list($item['certificates']))) {
                    $errors[] = "{$who}: 'certificates' must be a mapping";
                } else {
                    [$policy, $problems] = \App\Npm\CertificatePolicy::validate($item['certificates']);
                    foreach ($problems as $problem) {
                        $errors[] = "{$who}: certificates: {$problem}";
                    }
                    $unknownKeys = array_diff(array_keys($item['certificates']), ['strategy', 'edge_managed', 'pins', 'origin_certificates', 'letsencrypt_email', 'dns_template', 'wildcard']);
                    if ($unknownKeys) {
                        $errors[] = "{$who}: certificates: unknown key(s): ".implode(', ', array_map('strval', $unknownKeys));
                    }
                    $row['certificate_policy'] = $policy ?: null;
                }
            }

            if (array_key_exists('dns', $item) && $item['dns'] !== null) {
                $dns = $item['dns'];
                if (! is_array($dns) || ($dns !== [] && array_is_list($dns)) || array_diff(array_keys($dns), ['split_horizon', 'public_address'])) {
                    $errors[] = "{$who}: 'dns' must be a mapping with split_horizon and/or public_address";
                } elseif (isset($dns['split_horizon']) && ! is_bool($dns['split_horizon'])) {
                    $errors[] = "{$who}: dns.split_horizon must be true or false";
                } elseif (isset($dns['public_address']) && (! is_string($dns['public_address']) || ! Hosts::isHostOrIp($dns['public_address']))) {
                    $errors[] = "{$who}: dns.public_address must be an IP address or host name";
                } else {
                    $row['dns'] = array_filter(['split_horizon' => ($dns['split_horizon'] ?? false) ?: null, 'public_address' => $dns['public_address'] ?? null], fn ($x) => $x !== null) ?: null;
                }
            }

            if (array_key_exists('mirrors', $item) && $item['mirrors'] !== null) {
                $row['mirrors'] = self::zoneRefs($who.': mirrors', $item['mirrors'], $errors);
            }

            $proxy = self::text($item['proxy_address'] ?? null);
            if ($proxy !== null && $proxy !== '') {
                if (Hosts::isHostOrIp($proxy)) {
                    $row['proxy_address'] = $proxy;
                } else {
                    $errors[] = "{$who}: 'proxy_address' must be an IP address or host name, not '{$proxy}'";
                }
            }

            $notes = self::text($item['notes'] ?? null);
            if ($notes !== null && mb_strlen($notes) > 500) {
                $errors[] = "{$who}: 'notes' is too long (max 500)";
            } elseif ($notes !== null && $notes !== '') {
                $row['notes'] = $notes;
            }

            if (count($errors) === $before) {
                $out[] = $row;
            }
        }

        if ($defaults > 1) {
            $errors[] = 'zones: only one zone can be the default';
        }

        return $out;
    }

    // ----------------------------------------------------------------- servers

    /** @param list<string> $errors */
    private static function servers(mixed $raw, array &$errors): array
    {
        if ($raw === null) {
            return [];
        }

        if (! is_array($raw) || (! empty($raw) && ! array_is_list($raw))) {
            $errors[] = "'servers' must be a list";

            return [];
        }

        $out = [];
        $seen = [];

        foreach ($raw as $i => $item) {
            $n = $i + 1;

            if (! is_array($item) || array_is_list($item)) {
                $errors[] = "servers[{$n}]: each server must be a mapping";

                continue;
            }

            $name = self::text($item['name'] ?? null);
            if ($name === null || $name === '') {
                $errors[] = "servers[{$n}]: 'name' is required";

                continue;
            }

            $who = "server '{$name}'";
            $before = count($errors);

            if (isset($seen[mb_strtolower($name)])) {
                $errors[] = "{$who}: duplicate server name";
            }
            $seen[mb_strtolower($name)] = true;

            if (mb_strlen($name) > 100) {
                $errors[] = "{$who}: 'name' is too long (max 100)";
            }

            $address = self::text($item['address'] ?? null);
            if ($address === null || $address === '') {
                $errors[] = "{$who}: 'address' (IP or hostname) is required";
            } elseif (! Hosts::isHostOrIp($address)) {
                $errors[] = "{$who}: 'address' must be an IP address or host name, not '{$address}'";
            }

            $domain = self::text($item['domain'] ?? null);
            if ($domain !== null && $domain !== '' && ! Hosts::isHostname($domain)) {
                $errors[] = "{$who}: 'domain' must be a host name, not '{$domain}'";
            }

            $ports = [];
            if (array_key_exists('ports', $item) && $item['ports'] !== null) {
                if (! is_array($item['ports']) || ! array_is_list($item['ports'])) {
                    $errors[] = "{$who}: 'ports' must be a list";
                } else {
                    foreach ($item['ports'] as $port) {
                        $problem = self::portProblem($port);
                        if ($problem) {
                            $errors[] = "{$who}: {$problem}";
                        } else {
                            $ports[] = (int) $port;
                        }
                    }
                }
            }

            $sshPort = 22;
            if (array_key_exists('ssh_port', $item) && $item['ssh_port'] !== null) {
                $problem = self::portProblem($item['ssh_port'], 'ssh_port');
                if ($problem) {
                    $errors[] = "{$who}: {$problem}";
                } else {
                    $sshPort = (int) $item['ssh_port'];
                }
            }

            $sshUser = self::text($item['ssh_user'] ?? null);
            if ($sshUser !== null && $sshUser !== '' && ! Hosts::isSafeUser($sshUser)) {
                $errors[] = "{$who}: 'ssh_user' may only contain letters, digits, '_', '.' and '-' and must not start with '-'";
            }

            $extraDomains = self::extraDomains($who, $item, $errors);
            $flags = self::flags($who, $item, ['monitor' => true, 'check_ssh' => true], $errors);
            $icon = self::icon($who, $item, $errors);

            $group = self::text($item['group'] ?? null);
            $notes = self::text($item['notes'] ?? null);
            self::lengths($who, $group, $notes, $errors);

            if (count($errors) === $before) {
                $out[] = [
                    'name' => $name,
                    'address' => $address,
                    'domain' => $domain ?: null,
                    'group' => $group ?: null,
                    'ports' => array_values(array_unique($ports)),
                    'ssh_user' => $sshUser ?: null,
                    'ssh_port' => $sshPort,
                    'notes' => $notes ?: null,
                    'monitor' => $flags['monitor'],
                    'check_ssh' => $flags['check_ssh'],
                    'extra_domains' => $extraDomains,
                    'icon' => $icon,
                ] + self::zoneRef($who, $item, $errors);
            }
        }

        return $out;
    }

    /**
     * A list of zones with an optional hop setting: `[Name, {zone: Other, upstream: https}]`.
     *
     * @param  list<string>  $errors
     * @return list<array{zone: string, upstream: string}>
     */
    private static function zoneRefs(string $who, mixed $raw, array &$errors): array
    {
        if (! is_array($raw) || ($raw !== [] && ! array_is_list($raw))) {
            $errors[] = "{$who} must be a list of zone names";

            return [];
        }

        $out = [];
        foreach ($raw as $ref) {
            $name = is_array($ref) ? self::text($ref['zone'] ?? null) : self::text($ref);
            $upstream = is_array($ref) ? (string) ($ref['upstream'] ?? 'auto') : 'auto';
            if ($name === null || $name === '') {
                $errors[] = "{$who}: each entry needs a zone name";
            } elseif (! in_array($upstream, \App\Npm\Mirrors::UPSTREAMS, true)) {
                $errors[] = "{$who}: upstream must be one of ".implode(', ', \App\Npm\Mirrors::UPSTREAMS);
            } else {
                $out[] = ['zone' => $name, 'upstream' => $upstream];
            }
        }

        return $out;
    }

    /** The optional `certificate:` of a service: none | letsencrypt_http | letsencrypt_dns | existing:<id>. @param list<string> $errors @return array{cert_mode?: string} */
    private static function certRef(string $who, array $item, array &$errors): array
    {
        $mode = self::text($item['certificate'] ?? null);
        if ($mode === null || $mode === '' || $mode === 'auto') {
            return [];
        }
        if (! preg_match('/^(none|letsencrypt_http|letsencrypt_dns|existing:[1-9][0-9]*)$/', $mode)) {
            $errors[] = "{$who}: 'certificate' must be none, letsencrypt_http, letsencrypt_dns or existing:<NPM certificate id>";

            return [];
        }

        return ['cert_mode' => $mode];
    }

    /** The optional `zone: <name>` of a server or service. @param list<string> $errors @return array{zone?: string} */
    private static function zoneRef(string $who, array $item, array &$errors): array
    {
        $zone = self::text($item['zone'] ?? null);
        if ($zone === null || $zone === '') {
            return [];
        }
        if (mb_strlen($zone) > 60) {
            $errors[] = "{$who}: 'zone' is too long (max 60)";

            return [];
        }

        return ['zone' => $zone];
    }

    // ---------------------------------------------------------------- services

    /**
     * @param  list<string>  $serverNames
     * @param  list<string>  $errors
     */
    private static function services(mixed $raw, array $serverNames, array &$errors): array
    {
        if ($raw === null) {
            return [];
        }

        if (! is_array($raw) || (! empty($raw) && ! array_is_list($raw))) {
            $errors[] = "'services' must be a list";

            return [];
        }

        $out = [];
        $seen = [];

        foreach ($raw as $i => $item) {
            $n = $i + 1;

            if (! is_array($item) || array_is_list($item)) {
                $errors[] = "services[{$n}]: each service must be a mapping";

                continue;
            }

            $name = self::text($item['name'] ?? null);
            if ($name === null || $name === '') {
                $errors[] = "services[{$n}]: 'name' is required";

                continue;
            }

            $who = "service '{$name}'";
            $before = count($errors);

            if (isset($seen[mb_strtolower($name)])) {
                $errors[] = "{$who}: duplicate service name";
            }
            $seen[mb_strtolower($name)] = true;

            if (mb_strlen($name) > 100) {
                $errors[] = "{$who}: 'name' is too long (max 100)";
            }

            $server = self::text($item['server'] ?? null);
            $address = self::text($item['address'] ?? null);
            $hasServer = $server !== null && $server !== '';
            $hasAddress = $address !== null && $address !== '';

            if ($hasServer === $hasAddress) {
                $errors[] = "{$who}: set exactly one of 'server' (a server name) or 'address'";
            } elseif ($hasServer && ! in_array($server, $serverNames, true)) {
                $errors[] = "{$who}: server '{$server}' is not in the servers list";
            } elseif ($hasAddress && ! Hosts::isHostOrIp($address)) {
                $errors[] = "{$who}: 'address' must be an IP address or host name, not '{$address}'";
            }

            $domain = self::text($item['domain'] ?? null);
            if ($domain !== null && $domain !== '' && ! Hosts::isHostname($domain)) {
                $errors[] = "{$who}: 'domain' must be a host name, not '{$domain}'";
            }

            $extraDomains = self::extraDomains($who, $item, $errors);
            $publicDomain = self::text($item['public_domain'] ?? null);
            if ($publicDomain !== null && $publicDomain !== '') {
                $publicDomain = mb_strtolower($publicDomain);
                if (! Hosts::isHostname($publicDomain)) {
                    $errors[] = "{$who}: 'public_domain' must be a host name, not '{$publicDomain}'";
                } elseif ($publicDomain === mb_strtolower((string) $domain)) {
                    $errors[] = "{$who}: 'public_domain' must differ from 'domain'";
                }
            }

            $scheme = 'http';
            if (array_key_exists('scheme', $item)) {
                if (in_array($item['scheme'], ['http', 'https'], true)) {
                    $scheme = $item['scheme'];
                } else {
                    $errors[] = "{$who}: 'scheme' must be http or https";
                }
            }

            $port = null;
            if (array_key_exists('port', $item) && $item['port'] !== null) {
                $problem = self::portProblem($item['port']);
                if ($problem) {
                    $errors[] = "{$who}: {$problem}";
                } else {
                    $port = (int) $item['port'];
                }
            }

            $path = '/';
            if (array_key_exists('path', $item) && $item['path'] !== null) {
                $path = is_scalar($item['path']) ? (string) $item['path'] : '';
                if (! str_starts_with($path, '/')) {
                    $errors[] = "{$who}: 'path' must start with /";
                } elseif (! Hosts::isValidPath($path)) {
                    $errors[] = "{$who}: 'path' contains characters that are not allowed in a URL path";
                }
            }

            $expect = ExpectRange::DEFAULT;
            if (array_key_exists('expect', $item) && $item['expect'] !== null) {
                try {
                    $expect = (string) ExpectRange::parse($item['expect']);
                } catch (InvalidArgumentException $e) {
                    $errors[] = "{$who}: ".$e->getMessage();
                }
            }

            $flags = self::flags($who, $item, ['verify_tls' => true, 'public_check' => true, 'websockets' => false, 'monitor' => true, 'check_tcp' => true], $errors);
            $icon = self::icon($who, $item, $errors);

            $healthPath = null;
            if (array_key_exists('health_path', $item) && $item['health_path'] !== null && $item['health_path'] !== '') {
                $healthPath = is_scalar($item['health_path']) ? (string) $item['health_path'] : '';
                if (! str_starts_with($healthPath, '/')) {
                    $errors[] = "{$who}: 'health_path' must start with /";
                } elseif (! Hosts::isValidPath($healthPath)) {
                    $errors[] = "{$who}: 'health_path' contains characters that are not allowed in a URL path";
                }
            }

            $group = self::text($item['group'] ?? null);
            $notes = self::text($item['notes'] ?? null);
            self::lengths($who, $group, $notes, $errors);

            if (count($errors) === $before) {
                $out[] = [
                    'name' => $name,
                    'domain' => $domain ?: null,
                    'public_domain' => $publicDomain ?: null,
                    'extra_domains' => $extraDomains,
                    'server' => $hasServer ? $server : null,
                    'address' => $hasAddress ? $address : null,
                    'port' => $port,
                    'scheme' => $scheme,
                    'path' => $path,
                    'group' => $group ?: null,
                    'expect' => $expect,
                    'verify_tls' => $flags['verify_tls'],
                    'public_check' => $flags['public_check'],
                    'websockets' => $flags['websockets'],
                    'notes' => $notes ?: null,
                    'health_path' => $healthPath,
                    'monitor' => $flags['monitor'],
                    'check_tcp' => $flags['check_tcp'],
                    'icon' => $icon,
                ] + self::zoneRef($who, $item, $errors) + self::certRef($who, $item, $errors)
                    + (array_key_exists('publish_in', $item) && $item['publish_in'] !== null ? ['publish_in' => self::zoneRefs($who.': publish_in', $item['publish_in'], $errors)] : []);
            }
        }

        return $out;
    }

    // ----------------------------------------------------------------- helpers

    /**
     * Read true/false switches from an entry, keeping the defaults for absent ones.
     *
     * @param  array<string, bool>  $defaults
     * @param  list<string>  $errors
     * @return array<string, bool>
     */
    private static function flags(string $who, array $item, array $defaults, array &$errors): array
    {
        foreach ($defaults as $flag => $default) {
            if (array_key_exists($flag, $item)) {
                if (is_bool($item[$flag])) {
                    $defaults[$flag] = $item[$flag];
                } else {
                    $errors[] = "{$who}: '{$flag}' must be true or false";
                }
            }
        }

        return $defaults;
    }

    /** @param list<string> $errors */
    private static function icon(string $who, array $item, array &$errors): ?string
    {
        $icon = self::text($item['icon'] ?? null);

        if ($icon === null || $icon === '') {
            return null;
        }

        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,79}$/', $icon)) {
            $errors[] = "{$who}: 'icon' must be an icon name such as jellyfin or home-assistant (or none)";

            return null;
        }

        return strtolower($icon);
    }

    /** @return list<string>|null */
    private static function extraList($item): ?array
    {
        $extra = Domains::extras($item->extra_domains);

        return ($extra['local'] || $extra['public']) ? array_merge($extra['local'], $extra['public']) : null;
    }

    /**
     * Read `extra_domains` (a list of host names) from an entry; returns the stored shape.
     *
     * @param  list<string>  $errors
     * @return array{local: list<string>, public: list<string>}|null
     */
    private static function extraDomains(string $who, array $item, array &$errors): ?array
    {
        if (! array_key_exists('extra_domains', $item) || $item['extra_domains'] === null) {
            return null;
        }
        if (! is_array($item['extra_domains']) || ! array_is_list($item['extra_domains'])) {
            $errors[] = "{$who}: 'extra_domains' must be a list of host names";

            return null;
        }
        $names = Domains::parse($item['extra_domains']);
        if (($bad = Domains::firstInvalid($names)) !== null) {
            $errors[] = "{$who}: 'extra_domains' has an entry that is not a host name: '{$bad}'";

            return null;
        }

        return Domains::cleanExtras($names, [$item['domain'] ?? null, $item['public_domain'] ?? null]);
    }

    private static function text(mixed $value): ?string
    {
        return is_scalar($value) && ! is_bool($value) ? trim((string) $value) : null;
    }

    private static function portProblem(mixed $port, string $what = 'port'): ?string
    {
        if (is_int($port) || (is_string($port) && ctype_digit($port))) {
            return Hosts::isPort($port) ? null : "{$what} {$port} is outside 1-65535";
        }

        $shown = is_scalar($port) ? (string) $port : gettype($port);

        return "{$what} '{$shown}' is not a number";
    }

    /** @param list<string> $errors */
    private static function lengths(string $who, ?string $group, ?string $notes, array &$errors): void
    {
        if ($group !== null && mb_strlen($group) > 60) {
            $errors[] = "{$who}: 'group' is too long (max 60)";
        }
        if ($notes !== null && mb_strlen($notes) > 500) {
            $errors[] = "{$who}: 'notes' is too long (max 500)";
        }
    }
}
