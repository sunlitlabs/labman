<?php

namespace App\Support;

/**
 * The domains an install uses are configuration, never code: a list of "local" domains (names that resolve
 * on the home network) and a list of "public" ones (names reachable from the internet). A service may have a
 * name under each, and NPM proxy hosts are classed as local or public by which list their name falls under.
 * Both are settings (comma separated, e.g. "home.example.com, lab.example.net"); nothing is assumed when empty.
 */
final class Domains
{
    /** @return list<string> clean, lower-case, unique domain names, without leading "*." or "." */
    public static function parse(string|array|null $value): array
    {
        $parts = is_array($value) ? $value : preg_split('/[\s,;]+/', (string) $value);
        $out = [];

        foreach ($parts as $part) {
            $part = ltrim(mb_strtolower(trim((string) $part)), '*.');
            if ($part !== '') {
                $out[$part] = $part;
            }
        }

        return array_values($out);
    }

    /**
     * The lists a form posts (the first of each is the primary name) as the stored columns.
     *
     * @param  string|array<int, string>|null  $local
     * @param  string|array<int, string>|null  $public
     * @return array{domain: ?string, public_domain: ?string, extra_domains: array{local: list<string>, public: list<string>}}
     */
    public static function fromLists(string|array|null $local, string|array|null $public): array
    {
        $l = self::parse($local);
        $p = self::parse($public);

        return ['domain' => array_shift($l), 'public_domain' => array_shift($p), 'extra_domains' => ['local' => $l, 'public' => $p]];
    }

    /**
     * Extra names as {local: [], public: []}. A plain list (YAML, MCP) is sorted by the configured domains, local when unknown.
     *
     * @return array{local: list<string>, public: list<string>}
     */
    public static function extras(mixed $value): array
    {
        $out = ['local' => [], 'public' => []];

        if (! is_array($value)) {
            return $out;
        }
        if (array_is_list($value)) {
            foreach (self::parse($value) as $name) {
                $out[self::scope($name) ?? 'local'][] = $name;
            }

            return $out;
        }
        foreach (['local', 'public'] as $scope) {
            $out[$scope] = self::parse(is_array($value[$scope] ?? null) ? $value[$scope] : []);
        }

        return $out;
    }

    /** Every name of a service or server, primary first: local names, then public ones. @return array{local: list<string>, public: list<string>} */
    public static function namesOf($item): array
    {
        $extra = self::extras($item->extra_domains);

        return [
            'local' => array_values(array_filter([$item->domain, ...$extra['local']])),
            'public' => array_values(array_filter([$item->public_domain ?? null, ...$extra['public']])),
        ];
    }

    /** Extras without duplicates or the primary names; null when there are none. @param list<?string> $primary */
    public static function cleanExtras(mixed $value, array $primary): ?array
    {
        $primary = array_map(fn ($p) => mb_strtolower((string) $p), array_filter($primary));
        $extra = array_map(fn ($list) => array_values(array_diff($list, $primary)), self::extras($value));

        return $extra['local'] || $extra['public'] ? $extra : null;
    }

    /** A list with the chosen default first (the first entry is what everything else treats as the default). @return list<string> */
    public static function compose(string|array|null $value, ?string $default): array
    {
        $list = self::parse($value);
        $default = $default !== null ? ltrim(mb_strtolower(trim($default)), '*.') : null;

        if ($default !== null && in_array($default, $list, true)) {
            $list = array_values(array_unique(array_merge([$default], $list)));
        }

        return $list;
    }

    /** The default local / public domain (the first of each list), or null. */
    public static function defaultFor(string $scope): ?string
    {
        return ($scope === 'public' ? self::public() : self::local())[0] ?? null;
    }

    /** @param list<string> $domains the first invalid entry, or null */
    public static function firstInvalid(array $domains): ?string
    {
        foreach ($domains as $domain) {
            if (! Hosts::isHostname($domain)) {
                return $domain;
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function local(): array
    {
        return self::parse(Settings::get('local_domains'));
    }

    /** @return list<string> */
    public static function public(): array
    {
        return self::parse(Settings::get('public_domains'));
    }

    /** 'local' or 'public' if the host sits under a configured domain, else null. Public wins a tie (the more specific list). */
    public static function scope(string $host): ?string
    {
        $host = mb_strtolower($host);

        foreach (['public' => self::public(), 'local' => self::local()] as $scope => $domains) {
            foreach ($domains as $domain) {
                if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                    return $scope;
                }
            }
        }

        return null;
    }

    /** "wiki" from wiki.example.com. */
    public static function label(string $host): string
    {
        return explode('.', mb_strtolower($host))[0];
    }

    /** wiki + local -> wiki.<first local domain>, or null when none is configured. */
    public static function suggest(string $label, string $scope): ?string
    {
        $domains = $scope === 'public' ? self::public() : self::local();
        $label = trim(preg_replace('/[^a-z0-9-]+/', '-', mb_strtolower($label)), '-');

        return $domains !== [] && $label !== '' ? $label.'.'.$domains[0] : null;
    }
}
