<?php

namespace App\Monitoring;

/**
 * The small status dots next to a tile's names and address: which of them is the one with the problem.
 * The same rules live in public/js/labman.js (dotsOf) so a live update lands on the same colours.
 *
 * Check labels: "backend" and "tcp :N" are the address; "local", "local 2"... and "public"... are names over HTTPS
 * (a lone name is labelled "public" whatever it is, so it counts as the primary name).
 */
final class Dots
{
    /**
     * @param  list<array<string, mixed>>  $checks  as StatusView returns them
     * @return array{backend: array{state: string, title: string}, name: array{state: string, title: string}, public: array{state: string, title: string}}
     */
    public static function service(array $checks, string $state): array
    {
        $hasLocal = false;
        foreach ($checks as $c) {
            $hasLocal = $hasLocal || str_starts_with((string) $c['label'], 'local');
        }

        $groups = ['backend' => [], 'name' => [], 'public' => []];
        foreach ($checks as $c) {
            $label = (string) $c['label'];
            if ($label === 'backend' || str_starts_with($label, 'tcp')) {
                $groups['backend'][] = $c;
            } elseif (str_starts_with($label, 'local') || ($label === 'public' && ! $hasLocal)) {
                $groups['name'][] = $c;
            } elseif (str_starts_with($label, 'public')) {
                $groups['public'][] = $c;
            }
        }

        $out = [];
        foreach ($groups as $key => $list) {
            // The address is up if anything reaches it; every name has to answer.
            $out[$key] = self::dot($list, $state, $key === 'backend' ? 'any' : 'all');
        }

        return $out;
    }

    /** @param list<array<string, mixed>> $checks */
    public static function server(array $checks, string $state): array
    {
        return self::dot($checks, $state, 'all');
    }

    /** @param list<array<string, mixed>> $list */
    private static function dot(array $list, string $state, string $rule): array
    {
        if ($list === [] || $state === 'off') {
            return ['state' => 'unknown', 'title' => $state === 'off' ? 'Not monitored' : 'Not checked'];
        }

        $ok = array_map(fn ($c) => (bool) $c['ok'], $list);
        $up = $rule === 'any' ? in_array(true, $ok, true) : ! in_array(false, $ok, true);
        $title = implode(', ', array_map(fn ($c) => $c['label'].': '.$c['detail'], $list));

        return ['state' => $up ? 'up' : 'down', 'title' => $title];
    }
}
