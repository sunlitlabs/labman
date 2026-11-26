<?php

namespace App\Support;

use App\Rules\Check;

/**
 * Further addresses of one server or service: [{label, address, domain}]. The main address stays on the
 * record itself; these are for a machine reachable another way (a Wi-Fi address next to the LAN one).
 */
final class Alternates
{
    public const MAX = 10;

    /** Rows from a form or a client, without the empty ones. @return list<array{label: string, address: string, domain: string}> */
    public static function parse(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $row) {
            if (! is_array($row)) {
                continue;
            }
            $address = trim((string) ($row['address'] ?? ''));
            $domain = strtolower(trim((string) ($row['domain'] ?? '')));
            $label = trim((string) ($row['label'] ?? ''));
            if ($address === '' && $domain === '' && $label === '') {
                continue; // an untouched blank row of the form
            }
            $out[] = ['label' => $label, 'address' => $address, 'domain' => $domain];
        }

        return $out;
    }

    /** @return array<string, array<int, mixed>> */
    public static function rules(): array
    {
        return [
            'alternates' => ['nullable', 'array', 'max:'.self::MAX],
            'alternates.*.label' => ['nullable', 'string', 'max:30'],
            'alternates.*.address' => ['required', 'string', 'max:253', Check::hostOrIp()],
            'alternates.*.domain' => ['nullable', 'string', 'max:253', Check::hostname()],
        ];
    }

    /** What gets stored: an empty list is null. */
    public static function store(mixed $value): ?array
    {
        $rows = self::parse($value);

        return $rows === [] ? null : array_map(fn ($r) => [
            'label' => $r['label'] !== '' ? $r['label'] : 'Alternate',
            'address' => $r['address'],
            'domain' => $r['domain'] !== '' ? $r['domain'] : null,
        ], $rows);
    }

    /** @return list<array{label: string, address: string, domain: ?string}> */
    public static function of(object $model): array
    {
        return array_values($model->alternates ?? []);
    }
}
