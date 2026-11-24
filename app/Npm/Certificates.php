<?php

namespace App\Npm;

use Carbon\CarbonImmutable;

/** Pure helpers on NPM certificates: which names a certificate covers, and how long it has left. */
final class Certificates
{
    /** A certificate with fewer days left than this is flagged. */
    public const EXPIRY_WARNING_DAYS = 21;

    /**
     * Does a certificate name cover a host name? An exact name does. A wildcard covers exactly one more label:
     * `*.sub.example.dev` covers `a.sub.example.dev` but neither `a.b.sub.example.dev` nor `sub.example.dev` itself.
     */
    public static function covers(string $pattern, string $host): bool
    {
        $pattern = mb_strtolower(rtrim(trim($pattern), '.'));
        $host = mb_strtolower(rtrim(trim($host), '.'));

        if ($pattern === '' || $host === '') {
            return false;
        }
        if ($pattern === $host) {
            return true;
        }
        if (! str_starts_with($pattern, '*.')) {
            return false;
        }

        $suffix = substr($pattern, 1); // ".sub.example.dev"
        if (! str_ends_with($host, $suffix)) {
            return false;
        }
        $label = substr($host, 0, -strlen($suffix));

        return $label !== '' && ! str_contains($label, '.');
    }

    /** Does any name on the certificate cover the host? @param array{domains: list<string>} $cert */
    public static function coversHost(array $cert, string $host): bool
    {
        foreach ($cert['domains'] as $name) {
            if (self::covers($name, $host)) {
                return true;
            }
        }

        return false;
    }

    /** Whole days until expiry (negative when expired); null when NPM did not say. @param array{expires_on?: ?string} $cert */
    public static function daysLeft(array $cert, ?CarbonImmutable $now = null): ?int
    {
        $expires = $cert['expires_on'] ?? null;
        if (! is_string($expires) || $expires === '') {
            return null;
        }

        try {
            $at = CarbonImmutable::parse($expires, 'UTC');
        } catch (\Throwable) {
            return null;
        }

        return (int) floor(($now ?? CarbonImmutable::now('UTC'))->diffInSeconds($at, false) / 86400);
    }

    public static function expired(array $cert, ?CarbonImmutable $now = null): bool
    {
        $left = self::daysLeft($cert, $now);

        return $left !== null && $left < 0;
    }

    public static function expiring(array $cert, ?CarbonImmutable $now = null): bool
    {
        $left = self::daysLeft($cert, $now);

        return $left !== null && $left >= 0 && $left < self::EXPIRY_WARNING_DAYS;
    }

    /** "#12 Wildcard (*.example.dev)" for messages. @param array{id: int, name: string, domains: list<string>} $cert */
    public static function label(array $cert): string
    {
        return '#'.$cert['id'].' '.$cert['name'];
    }
}
