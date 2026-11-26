<?php

namespace App\Domain;

use App\Models\Service;
use App\Rules\Check;
use App\Support\Domains;
use App\Support\ExpectRange;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** The single definition of what a valid service looks like (admin form, MCP tools). */
final class ServiceInput
{
    private const FLAGS = ['verify_tls' => true, 'public_check' => true, 'websockets' => false];

    /** Newer switches: an absent value means "leave as is" (forms always send them), never "off". */
    private const OPTIONAL_FLAGS = ['monitor' => true, 'check_tcp' => true, 'on_dashboard' => true];

    /**
     * Normalise raw input. With $form, an absent checkbox means "off"; otherwise
     * absent flags are left for the caller's defaults.
     */
    public static function prepare(array $in, bool $form = false): array
    {
        // The form posts lists of names; the first of each kind is the primary one, the rest are kept as extras.
        if (is_array($in['local_domains'] ?? null) || is_array($in['public_domains'] ?? null)) {
            $in = array_merge($in, Domains::fromLists((array) ($in['local_domains'] ?? []), (array) ($in['public_domains'] ?? [])));
        }
        unset($in['local_domains'], $in['public_domains']);
        if (array_key_exists('alternates', $in)) {
            $in['alternates'] = \App\Support\Alternates::parse($in['alternates']);
        }
        if (array_key_exists('extra_domains', $in)) {
            $in['extra_domains'] = Domains::extras($in['extra_domains']);
        }

        // The form posts one choice, "project:template"; blank clears it.
        if (array_key_exists('update_workbook', $in)) {
            [$project, $template] = array_pad(array_map('intval', explode(':', (string) $in['update_workbook'])), 2, 0);
            $in['update_project'] = $project > 0 && $template > 0 ? $project : null;
            $in['update_template'] = $project > 0 && $template > 0 ? $template : null;
            unset($in['update_workbook']);
        }

        // The form offers a strategy and, separately, a specific NPM certificate id; the id wins when given.
        if (array_key_exists('cert_existing', $in)) {
            if (filled($in['cert_existing'])) {
                $in['cert_mode'] = 'existing:'.(int) $in['cert_existing'];
            }
            unset($in['cert_existing']);
        }
        // The form posts the zones to also publish in (checkboxes) and a hop setting for each; a marker lets "none ticked" clear it.
        if (array_key_exists('publish_form', $in)) {
            $refs = [];
            foreach ((array) ($in['publish_in'] ?? []) as $id) {
                $refs[] = ['zone_id' => (int) $id, 'upstream' => ((array) ($in['publish_upstream'] ?? []))[$id] ?? 'auto'];
            }
            $in['publish_zones'] = $refs;
            unset($in['publish_form'], $in['publish_in'], $in['publish_upstream']);
        }
        if (array_key_exists('cert_mode', $in) && $in['cert_mode'] === 'auto') {
            $in['cert_mode'] = null;
        }

        if (! isset($in['path']) || $in['path'] === '') {
            $in['path'] = '/';
        }
        if (! isset($in['expect']) || $in['expect'] === '') {
            $in['expect'] = ExpectRange::DEFAULT;
        }

        foreach (self::FLAGS as $flag => $default) {
            if (array_key_exists($flag, $in)) {
                $in[$flag] = filter_var($in[$flag], FILTER_VALIDATE_BOOLEAN);
            } elseif ($form) {
                $in[$flag] = false;
            }
        }

        foreach (array_keys(self::OPTIONAL_FLAGS) as $flag) {
            if (array_key_exists($flag, $in)) {
                $in[$flag] = filter_var($in[$flag], FILTER_VALIDATE_BOOLEAN);
            }
        }

        return $in;
    }

    /** @return array<string, array<int, mixed>> */
    public static function rules(Service|int|null $ignore = null): array
    {
        return \App\Support\Alternates::rules() + [
            'family' => ['nullable', 'string', 'max:60'],
            'name' => ['required', 'string', 'max:100', Rule::unique('services', 'name')->ignore($ignore)],
            'domain' => ['nullable', 'string', 'max:253', Check::hostname()],
            'public_domain' => ['nullable', 'string', 'max:253', Check::hostname(), 'different:domain'],
            'extra_domains' => ['nullable', 'array'],
            'extra_domains.local' => ['array', 'max:20'],
            'extra_domains.local.*' => ['string', 'max:253', Check::hostname()],
            'extra_domains.public' => ['array', 'max:20'],
            'extra_domains.public.*' => ['string', 'max:253', Check::hostname()],
            'server_id' => ['nullable', 'integer', 'exists:servers,id'],
            'address' => ['nullable', 'string', 'max:253', Check::hostOrIp()],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'scheme' => ['required', Rule::in(['http', 'https'])],
            'path' => ['required', 'string', 'max:500', Check::path()],
            'group' => ['nullable', 'string', 'max:60'],
            'expect' => ['required', Check::expect()],
            'verify_tls' => ['boolean'],
            'public_check' => ['boolean'],
            'websockets' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
            'health_path' => ['nullable', 'string', 'max:500', Check::path()],
            'monitor' => ['boolean'],
            'on_dashboard' => ['boolean'],
            'check_tcp' => ['boolean'],
            'icon' => ['nullable', 'string', 'max:80', Check::icon()],
            'update_project' => ['nullable', 'integer', 'min:1'],
            'update_template' => ['nullable', 'integer', 'min:1', 'required_with:update_project'],
            // Only to override the zone of the service's server; empty means "same as the server".
            'zone_id' => ['nullable', 'integer', 'exists:zones,id'],
            // The service's own certificate choice; empty follows the zone's policy.
            'publish_zones' => ['nullable', 'array', 'max:20'],
            'publish_zones.*.zone_id' => ['required', 'integer', 'exists:zones,id'],
            'publish_zones.*.upstream' => ['required', Rule::in(\App\Npm\Mirrors::UPSTREAMS)],
            'cert_mode' => ['nullable', 'string', 'regex:/^(none|letsencrypt_http|letsencrypt_dns|existing:[1-9][0-9]*)$/'],
        ];
    }

    /** A service runs on a server OR has its own address: exactly one. */
    public static function exactlyOne(Validator $validator, array $data): void
    {
        $hasServer = filled($data['server_id'] ?? null);
        $hasAddress = filled($data['address'] ?? null);

        if ($hasServer === $hasAddress) {
            $validator->errors()->add('server_id', 'Choose a server or enter an address — exactly one of the two.');
        }
    }

    /** @return array<string, mixed> */
    public static function payload(array $validated): array
    {
        $validated['expect'] = (string) ExpectRange::parse($validated['expect']);
        $validated['server_id'] = filled($validated['server_id'] ?? null) ? (int) $validated['server_id'] : null;
        $validated['address'] = $validated['server_id'] ? null : ($validated['address'] ?? null);
        $validated['health_path'] = filled($validated['health_path'] ?? null) ? $validated['health_path'] : null;
        $validated['icon'] = filled($validated['icon'] ?? null) ? strtolower($validated['icon']) : null;
        $validated['alternates'] = \App\Support\Alternates::store($validated['alternates'] ?? null);
        $validated['family'] = filled($validated['family'] ?? null) ? trim($validated['family']) : null;
        $validated['public_domain'] = filled($validated['public_domain'] ?? null) ? strtolower($validated['public_domain']) : null;
        if (array_key_exists('publish_zones', $validated)) {
            $validated['publish_zones'] = \App\Npm\Mirrors::refs($validated['publish_zones']) ?: null;
        }
        if (array_key_exists('cert_mode', $validated)) {
            $validated['cert_mode'] = filled($validated['cert_mode']) ? $validated['cert_mode'] : null;
        }
        if (array_key_exists('zone_id', $validated)) {
            $validated['zone_id'] = filled($validated['zone_id']) ? (int) $validated['zone_id'] : null;
        }
        $validated['extra_domains'] = Domains::cleanExtras($validated['extra_domains'] ?? null, [$validated['domain'] ?? null, $validated['public_domain']]);

        return $validated;
    }

    /**
     * Validate $input (merged over the existing service when updating) and return the payload.
     * Giving a server clears the own address and vice versa, so a partial update can switch.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public static function validate(array $input, ?Service $existing = null): array
    {
        $base = $existing ? self::current($existing) : ['scheme' => 'http'] + self::FLAGS + self::OPTIONAL_FLAGS;

        if (filled($input['server_id'] ?? null) && ! array_key_exists('address', $input)) {
            $base['address'] = null;
        }
        if (filled($input['address'] ?? null) && ! array_key_exists('server_id', $input)) {
            $base['server_id'] = null;
        }

        $data = self::prepare(array_merge($base, $input));

        $validator = ValidatorFacade::make($data, self::rules($existing));
        $validator->after(fn (Validator $v) => self::exactlyOne($v, $data));

        return self::payload($validator->validate());
    }

    /** @return array<string, mixed> */
    public static function current(Service $service): array
    {
        return [
            'name' => $service->name,
            'domain' => $service->domain,
            'public_domain' => $service->public_domain,
            'extra_domains' => $service->extra_domains,
            'alternates' => $service->alternates ?? [],
            'family' => $service->family,
            'server_id' => $service->server_id,
            'address' => $service->address,
            'port' => $service->port,
            'scheme' => $service->scheme,
            'path' => $service->path,
            'group' => $service->group,
            'expect' => $service->expect,
            'verify_tls' => $service->verify_tls,
            'public_check' => $service->public_check,
            'websockets' => $service->websockets,
            'notes' => $service->notes,
            'health_path' => $service->health_path,
            'monitor' => $service->monitor,
            'on_dashboard' => $service->on_dashboard,
            'check_tcp' => $service->check_tcp,
            'icon' => $service->icon,
            'update_project' => $service->update_project,
            'update_template' => $service->update_template,
            'zone_id' => $service->zone_id,
            'cert_mode' => $service->cert_mode,
            'publish_zones' => $service->publish_zones,
        ];
    }
}
