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
