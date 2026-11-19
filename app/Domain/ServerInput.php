<?php

namespace App\Domain;

use App\Models\Server;
use App\Rules\Check;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * The single definition of what a valid server looks like. The admin form, the MCP
 * tools and (through Hosts/Check) the YAML importer all go through the same rules.
 */
final class ServerInput
{
    /** Normalise raw input: "445, 8080" becomes a list, a missing SSH port becomes 22. */
    public static function prepare(array $in): array
    {
        if (isset($in['ports']) && is_string($in['ports'])) {
            $parts = array_filter(preg_split('/[\s,]+/', trim($in['ports'])), fn ($p) => $p !== '');
            $in['ports'] = array_values($parts);
        }

        if (! isset($in['ssh_port']) || $in['ssh_port'] === '') {
            $in['ssh_port'] = 22;
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
            'group' => ['nullable', 'string', 'max:60'],
            'ports' => ['nullable', 'array', 'max:50'],
            'ports.*' => ['integer', 'between:1,65535'],
            'ssh_user' => ['nullable', 'string', 'max:32', Check::sshUser()],
            'ssh_port' => ['required', 'integer', 'between:1,65535'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** Validated data to what gets stored. @return array<string, mixed> */
    public static function payload(array $validated): array
    {
        $validated['ports'] = array_values(array_unique(array_map('intval', $validated['ports'] ?? [])));

        return $validated;
    }

    /**
     * Validate $input (merged over the existing server when updating) and return the payload.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public static function validate(array $input, ?Server $existing = null): array
    {
        $data = self::prepare($existing ? array_merge(self::current($existing), $input) : $input);

        return self::payload(Validator::make($data, self::rules($existing))->validate());
    }

    /** @return array<string, mixed> */
    public static function current(Server $server): array
    {
        return [
            'name' => $server->name,
            'address' => $server->address,
            'domain' => $server->domain,
            'group' => $server->group,
            'ports' => $server->ports ?? [],
            'ssh_user' => $server->ssh_user,
            'ssh_port' => $server->ssh_port,
            'notes' => $server->notes,
        ];
    }
}
