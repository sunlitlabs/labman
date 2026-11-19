<?php

namespace App\Rules;

use App\Support\Domains;
use App\Support\ExpectRange;
use App\Support\Hosts;
use Closure;
use InvalidArgumentException;

/**
 * Small closure validation rules shared by the admin forms. They use the same
 * Hosts / ExpectRange checks as the YAML importer, so both entry points accept
 * exactly the same input.
 */
final class Check
{
    public static function hostOrIp(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (! is_string($value) || ! Hosts::isHostOrIp($value)) {
                $fail('The :attribute must be an IP address (IPv6 allowed) or a host name.');
            }
        };
    }

    public static function hostname(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (! is_string($value) || ! Hosts::isHostname($value)) {
                $fail('The :attribute must be a host name such as app.example.com.');
            }
        };
    }

    public static function sshUser(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (! is_string($value) || ! Hosts::isSafeUser($value)) {
                $fail("The :attribute may only contain letters, digits, '_', '.' and '-' and must not start with '-'.");
            }
        };
    }

    public static function path(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (! is_string($value) || ! str_starts_with($value, '/')) {
                $fail('The :attribute must start with /.');
            } elseif (! Hosts::isValidPath($value)) {
                $fail('The :attribute contains characters that are not allowed in a URL path.');
            }
        };
    }

    /** An icon name from the icon library (jellyfin, home-assistant), or "none" to switch the icon off. */
    public static function icon(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (! is_string($value) || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,79}$/', $value)) {
                $fail('The :attribute must be an icon name such as jellyfin or home-assistant (letters, digits, - . _), or none.');
            }
        };
    }

    /** A comma separated list of domain names (home.example.com, lab.example.net). */
    public static function domainList(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            $bad = is_string($value) ? Domains::firstInvalid(Domains::parse($value)) : 'value';
            if ($bad !== null) {
                $fail("The :attribute has an entry that is not a domain name: '{$bad}'. Separate several with commas.");
            }
        };
    }

    public static function expect(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            try {
                ExpectRange::parse($value);
            } catch (InvalidArgumentException $e) {
                $fail($e->getMessage().'.');
            }
        };
    }
}
