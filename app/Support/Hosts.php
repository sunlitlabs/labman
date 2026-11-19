<?php

namespace App\Support;

/**
 * Validation and formatting helpers for the host names / addresses admins type.
 * Everything stored here is later used in URLs, TCP connects and a copyable SSH
 * command, so the rules are deliberately strict.
 */
final class Hosts
{
    public static function isIp(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_IP) !== false;
    }

    /** A DNS-style host name (RFC 1123 labels). Dotted-numeric strings must be real IPv4 addresses. */
    public static function isHostname(string $value): bool
    {
        if ($value === '' || strlen($value) > 253) {
            return false;
        }

        if (preg_match('/^[0-9.]+$/', $value)) {
            return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        }

        return (bool) preg_match(
            '/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/i',
            $value,
        );
    }

    public static function isHostOrIp(string $value): bool
    {
        return self::isIp($value) || self::isHostname($value);
    }

    /** Characters allowed in an SSH user name; never starts with "-". */
    public static function isSafeUser(string $value): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.-]{0,31}$/', $value);
    }

    public static function isPort(mixed $value): bool
    {
        return is_int($value) ? ($value >= 1 && $value <= 65535)
            : (is_string($value) && ctype_digit($value) && (int) $value >= 1 && (int) $value <= 65535);
    }

    /** Wrap IPv6 literals in brackets for use inside URLs / socket targets. */
    public static function forUrl(string $host): string
    {
        return str_contains($host, ':') && ! str_starts_with($host, '[') ? '['.$host.']' : $host;
    }

    public static function isValidPath(string $value): bool
    {
        return (bool) preg_match('#^/[A-Za-z0-9\-._~!$&\'()*+,;=:@%/?]*$#', $value);
    }
}
