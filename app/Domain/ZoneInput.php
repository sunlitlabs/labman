<?php

namespace App\Domain;

use App\Models\Server;
use App\Models\Service;
use App\Models\Zone;
use App\Npm\CertificatePolicy;
use App\Npm\Mirrors;
use App\Rules\Check;
use App\Support\Domains;
use App\Support\Settings;
use App\Support\Zones;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Creating, changing and deleting a zone from structured input (the MCP tools). Only the keys given are changed on
 * an update; everything is validated with the same rules the screens use, and nothing partial is ever written.
 */
final class ZoneInput
{
    /** @param array<string, mixed> $in @throws ValidationException */
    public static function apply(array $in, ?Zone $zone = null): Zone
    {
        $v = Validator::make($in, [
            'name' => [$zone ? 'sometimes' : 'required', 'string', 'max:60', function (string $attribute, mixed $value, \Closure $fail) use ($zone) {
                if (is_string($value) && \App\Models\Zone::query()->whereRaw('lower(name) = ?', [mb_strtolower(trim($value))])->when($zone, fn ($q) => $q->where('id', '!=', $zone->id))->exists()) {
                    $fail('The name has already been taken (zone names are not case-sensitive).');
                }
            }],
            'level' => ['sometimes', 'integer', 'between:0,100'],
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'icon' => ['nullable', 'string', 'max:80', Check::icon()],
            'cidrs' => ['nullable', 'array', 'max:50'],
            'cidrs.*' => ['string'],
            'local_domains' => ['nullable', Check::domainList()],
            'public_domains' => ['nullable', Check::domainList()],
            'proxy_address' => ['nullable', 'string', 'max:253', Check::hostOrIp()],
            'notes' => ['nullable', 'string', 'max:500'],
            'certificates' => ['nullable', 'array'],
            'dns' => ['nullable', 'array'],
            'mirrors' => ['nullable', 'array', 'max:50'],
            'is_default' => ['sometimes', 'boolean'],
        ]);
        $v->validate();

        $data = [];
        foreach (['name', 'level', 'notes'] as $key) {
            if (array_key_exists($key, $in)) {
                $data[$key] = $key === 'level' ? (int) $in[$key] : (is_string($in[$key]) ? trim($in[$key]) : $in[$key]);
            }
        }
        if (array_key_exists('color', $in)) {
            $data['color'] = filled($in['color']) ? strtolower($in['color']) : null;
        }
        if (array_key_exists('icon', $in)) {
            $data['icon'] = filled($in['icon']) ? strtolower($in['icon']) : null;
        }
        if (array_key_exists('proxy_address', $in)) {
            $data['proxy_address'] = filled($in['proxy_address']) ? trim($in['proxy_address']) : null;
        }
        if (array_key_exists('cidrs', $in)) {
            $cidrs = Zones::parseCidrs($in['cidrs'] ?? []);
            foreach ($cidrs as $cidr) {
                if (! Zones::validCidr($cidr)) {
                    throw ValidationException::withMessages(['cidrs' => "'{$cidr}' is not a network block like 192.0.2.0/24."]);
                }
            }
            $data['cidrs'] = $cidrs ?: null;
        }

        if (array_key_exists('certificates', $in)) {
            $given = (array) $in['certificates'];
            $unknown = array_diff(array_keys($given), ['strategy', 'edge_managed', 'pins', 'origin_certificates', 'letsencrypt_email', 'dns_template', 'wildcard']);
            if ($unknown) {
                throw ValidationException::withMessages(['certificates' => 'Unknown certificate setting(s): '.implode(', ', array_map('strval', $unknown)).'.']);
            }
            [$policy, $problems] = CertificatePolicy::validate(array_merge(($zone?->certificatePolicy() ?? new CertificatePolicy)->toArray(), $given));
            if ($problems) {
                throw ValidationException::withMessages(['certificates' => array_map('ucfirst', $problems)]);
            }
            $data['certificate_policy'] = $policy ?: null;
        }

        if (array_key_exists('dns', $in)) {
            $given = (array) $in['dns'];
            if ($bad = array_diff(array_keys($given), ['split_horizon', 'public_address'])) {
                throw ValidationException::withMessages(['dns' => 'Unknown DNS setting(s): '.implode(', ', array_map('strval', $bad)).'.']);
            }
            $dns = array_merge($zone?->dnsSettings() ?? ['split_horizon' => false, 'public_address' => null], $given);
            if (filled($dns['public_address'] ?? null) && ! \App\Support\Hosts::isHostOrIp((string) $dns['public_address'])) {
                throw ValidationException::withMessages(['dns' => 'public_address must be an IP address or host name.']);
            }
            $data['dns'] = array_filter(['split_horizon' => filter_var($dns['split_horizon'] ?? false, FILTER_VALIDATE_BOOLEAN) ?: null, 'public_address' => filled($dns['public_address'] ?? null) ? trim((string) $dns['public_address']) : null], fn ($x) => $x !== null) ?: null;
        }

        if (array_key_exists('mirrors', $in)) {
            $refs = [];
            foreach ((array) $in['mirrors'] as $ref) {
                $name = is_array($ref) ? ($ref['zone'] ?? null) : $ref;
                $other = self::find($name);
                $upstream = is_array($ref) ? ($ref['upstream'] ?? 'auto') : 'auto';
                if ($other === null) {
                    throw ValidationException::withMessages(['mirrors' => "There is no zone '{$name}'. Zones: ".self::names().'.']);
                }
                if (! in_array($upstream, Mirrors::UPSTREAMS, true)) {
                    throw ValidationException::withMessages(['mirrors' => 'upstream must be one of: '.implode(', ', Mirrors::UPSTREAMS).'.']);
                }
                if ($zone === null || $other->id !== $zone->id) {
                    $refs[] = ['zone_id' => $other->id, 'upstream' => $upstream];
                }
            }
            $data['mirror_sources'] = Mirrors::refs($refs) ?: null;
        }

        $domains = [];
        foreach (['local', 'public'] as $scope) {
            if (array_key_exists($scope.'_domains', $in)) {
                $domains[$scope] = Domains::parse($in[$scope.'_domains'] ?? []);
            }
        }

        return DB::transaction(function () use ($zone, $data, $domains, $in) {
            if ($zone === null) {
                $zone = Zone::create($data + ['name' => $data['name'], 'level' => $data['level'] ?? 50, 'is_default' => false]);
            } else {
                $zone->update($data);
            }

            if ($domains !== []) {
                if ($zone->is_default) {
                    Settings::set(array_map(fn ($list) => implode(', ', $list), array_combine(array_map(fn ($s) => $s.'_domains', array_keys($domains)), $domains)));
                } else {
                    $merged = array_merge(['local' => $zone->domains()['local'], 'public' => $zone->domains()['public']], $domains);
                    $zone->update(['domains' => $merged['local'] || $merged['public'] ? $merged : null]);
                }
            }

            if (filter_var($in['is_default'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                Zones::makeDefault($zone);
            }

            return $zone->fresh();
        });
    }

    /**
     * Delete a zone. The default zone and a zone that still has servers are refused, with the reason.
     *
     * @throws \RuntimeException
     */
    public static function delete(Zone $zone): void
    {
        if ($zone->is_default) {
            throw new \RuntimeException('The default zone cannot be deleted. Make another zone the default first.');
        }
        if (($n = $zone->servers()->count()) > 0) {
            throw new \RuntimeException("{$zone->name} still has {$n} server(s). Move them to another zone first (assign_server_zone).");
        }

        Service::query()->where('zone_id', $zone->id)->update(['zone_id' => null]);
        $zone->delete();
    }

    /** A zone by name (any case) or id; null when none matches. */
    public static function find(mixed $ref): ?Zone
    {
        if ($ref === null || $ref === '') {
            return null;
        }
        if (is_int($ref) || (is_string($ref) && ctype_digit($ref))) {
            return Zone::query()->find((int) $ref);
        }

        return Zone::query()->whereRaw('lower(name) = ?', [mb_strtolower((string) $ref)])->first();
    }

    public static function names(): string
    {
        return Zone::query()->orderByDesc('level')->pluck('name')->implode(', ');
    }

    /** Put a server in a zone. */
    public static function assign(Server $server, Zone $zone): Server
    {
        $server->update(['zone_id' => $zone->id]);

        return $server->fresh();
    }
}
