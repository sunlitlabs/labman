<?php

namespace App\Config;

use App\Models\Server;
use App\Models\Service;
use App\Support\DomainSuggestions;
use App\Support\Domains;
use App\Support\ExpectRange;
use App\Support\Hosts;
use App\Support\Settings;
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

        $unknown = array_values(array_diff(array_keys($data), ['settings', 'servers', 'services']));
        if ($unknown) {
            $errors[] = 'unknown top-level key(s): '.implode(', ', array_map('strval', $unknown));
        }

        $settings = self::settings($data['settings'] ?? null, $errors);
        $servers = self::servers($data['servers'], $errors);
        $services = self::services($data['services'], array_column($servers, 'name'), $errors);

        if ($errors) {
            throw new ConfigException($errors);
        }

        return ['settings' => $settings, 'servers' => $servers, 'services' => $services];
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

            foreach ($config['servers'] as $row) {
                $server = Server::query()->where('name', $row['name'])->first() ?? new Server;
                $server->fill($row)->save();
            }

            $ids = Server::query()->pluck('id', 'name');

            foreach ($config['services'] as $row) {
                $serverName = $row['server'];
                unset($row['server']);
                $row['server_id'] = $serverName !== null ? ($ids[$serverName] ?? null) : null;

                $service = Service::query()->where('name', $row['name'])->first() ?? new Service;
                $service->fill($row)->save();
            }

            if ($replace) {
                $removed += Service::query()->whereNotIn('name', array_column($config['services'], 'name'))->delete();
                $removed += Server::query()->whereNotIn('name', array_column($config['servers'], 'name'))->delete();
            }
        });

        return ['servers' => count($config['servers']), 'services' => count($config['services']), 'removed' => $removed];
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
        ], fn ($v) => $v !== null && $v !== ''))->all();

        return "# LabMan config. Import with: php artisan labman:import <file>\n"
            .Yaml::dump(['settings' => $settings, 'servers' => $servers, 'services' => $services], 4, 2);
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
                ];
            }
        }

        return $out;
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
                ];
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
