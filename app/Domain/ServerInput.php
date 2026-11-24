<?php

namespace App\Domain;

use App\Models\Server;
use App\Rules\Check;
use App\Support\Domains;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * The single definition of what a valid server looks like. The admin form, the MCP
 * tools and (through Hosts/Check) the YAML importer all go through the same rules.
 */
final class ServerInput
{
    /** Switches where an absent value means "leave as is" (forms always send them), never "off". */
    private const FLAGS = ['monitor' => true, 'check_ssh' => true];

    /** Normalise raw input: "445, 8080" becomes a list, a missing SSH port becomes 22. */
    public static function prepare(array $in): array
    {
        // The form posts a list of names; the first is the primary domain, the rest are kept as extras.
        if (is_array($in['local_domains'] ?? null)) {
            $in = array_merge($in, Domains::fromLists($in['local_domains'], []));
            unset($in['public_domain']);
            $in['extra_domains']['public'] = [];
        }
        unset($in['local_domains']);
        if (array_key_exists('extra_domains', $in)) {
            $in['extra_domains'] = Domains::extras($in['extra_domains']);
        }

        if (isset($in['ports']) && is_string($in['ports'])) {
            $parts = array_filter(preg_split('/[\s,]+/', trim($in['ports'])), fn ($p) => $p !== '');
            $in['ports'] = array_values($parts);
        }

        if (! isset($in['ssh_port']) || $in['ssh_port'] === '') {
            $in['ssh_port'] = 22;
        }

        foreach (array_keys(self::FLAGS) as $flag) {
            if (array_key_exists($flag, $in)) {
                $in[$flag] = filter_var($in[$flag], FILTER_VALIDATE_BOOLEAN);
            }
        }

        return $in;
    }

    /** @return array<string, array<int, mixed>> */
    public static function rules(Server|int|null $ignore = null): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('servers', 'name')->ignore($ignore)],
            'address' => ['required', 'string', 'max:253', Check::hostOrIp()],
            'domain' => ['nullable', 'string', 'max:253', Check::hostname()],
            'extra_domains' => ['nullable', 'array'],
            'extra_domains.local' => ['array', 'max:20'],
            'extra_domains.local.*' => ['string', 'max:253', Check::hostname()],
            'extra_domains.public' => ['array', 'max:20'],
            'extra_domains.public.*' => ['string', 'max:253', Check::hostname()],
            'group' => ['nullable', 'string', 'max:60'],
            'ports' => ['nullable', 'array', 'max:50'],
            'ports.*' => ['integer', 'between:1,65535'],
            'ssh_user' => ['nullable', 'string', 'max:32', Check::sshUser()],
            'ssh_port' => ['required', 'integer', 'between:1,65535'],
            'notes' => ['nullable', 'string', 'max:500'],
            'monitor' => ['boolean'],
            'check_ssh' => ['boolean'],
            'icon' => ['nullable', 'string', 'max:80', Check::icon()],
            'zone_id' => ['nullable', 'integer', 'exists:zones,id'],
        ];
    }

    /** Validated data to what gets stored. @return array<string, mixed> */
    public static function payload(array $validated): array
    {
        $validated['ports'] = array_values(array_unique(array_map('intval', $validated['ports'] ?? [])));
        $validated['icon'] = filled($validated['icon'] ?? null) ? strtolower($validated['icon']) : null;
        $validated['extra_domains'] = Domains::cleanExtras($validated['extra_domains'] ?? null, [$validated['domain'] ?? null]);
        // A server always has a zone: blank keeps the current one (or the default for a new server).
        if (blank($validated['zone_id'] ?? null)) {
            unset($validated['zone_id']);
        }

        return $validated;
    }

    /**
     * Validate $input (merged over the existing server when updating) and return the payload.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public static function validate(array $input, ?Server $existing = null): array
    {
        $base = $existing ? self::current($existing) : self::FLAGS;
        $data = self::prepare(array_merge($base, $input));

        return self::payload(Validator::make($data, self::rules($existing))->validate());
    }

    /** @return array<string, mixed> */
    public static function current(Server $server): array
    {
        return [
            'name' => $server->name,
            'address' => $server->address,
            'domain' => $server->domain,
            'extra_domains' => $server->extra_domains,
            'group' => $server->group,
            'ports' => $server->ports ?? [],
            'ssh_user' => $server->ssh_user,
            'ssh_port' => $server->ssh_port,
            'notes' => $server->notes,
            'monitor' => $server->monitor,
            'check_ssh' => $server->check_ssh,
            'icon' => $server->icon,
            'zone_id' => $server->zone_id,
        ];
    }
}
