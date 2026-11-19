<?php

namespace App\Monitoring;

/** Pure state logic: turns probe results into up / degraded / down / unknown. */
final class Verdict
{
    public const UP = 'up';

    public const DEGRADED = 'degraded';

    public const DOWN = 'down';

    public const UNKNOWN = 'unknown';

    /** Switched off by the admin: never checked, never shown as up or down. */
    public const OFF = 'off';

    /** Server: all ports pass = up, some = degraded, none = down, no results = unknown. @param list<CheckResult> $results */
    public static function server(array $results): string
    {
        if ($results === []) {
            return self::UNKNOWN;
        }

        $ok = count(array_filter($results, fn (CheckResult $r) => $r->ok));

        return match (true) {
            $ok === count($results) => self::UP,
            $ok === 0 => self::DOWN,
            default => self::DEGRADED,
        };
    }

    /** Service: backend failure = down; backend ok but public failing = degraded; else up. */
    public static function service(?CheckResult $backend, ?CheckResult $public): string
    {
        if ($backend === null || ! $backend->ok) {
            return self::DOWN;
        }

        return ($public !== null && ! $public->ok) ? self::DEGRADED : self::UP;
    }
}
