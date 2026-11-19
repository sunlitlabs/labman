<?php

namespace App\Rules;

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
