<?php

namespace App\Support;

use InvalidArgumentException;
use Stringable;

/**
 * The HTTP statuses that count as "the service answered properly": "200", "401", "200-399", or a list such as
 * "200-399,401,403". The default is 200-499: anything below a server error means the app is up and talking
 * (login-protected apps answer 401/403, API-only apps answer 404 at "/").
 */
final class ExpectRange implements Stringable
{
    public const DEFAULT = '200-499';

    /** @param list<array{0: int, 1: int}> $ranges */
    public function __construct(public readonly array $ranges) {}

    /** @throws InvalidArgumentException with a human readable reason */
    public static function parse(mixed $value): self
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        $shown = is_scalar($value) ? (string) $value : gettype($value);

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("'expect' must look like 200 or 200-399, not '{$shown}'");
        }

        $ranges = [];

        foreach (explode(',', $value) as $part) {
            if (! preg_match('/^\s*(\d{3})(?:\s*-\s*(\d{3}))?\s*$/', $part, $m)) {
                throw new InvalidArgumentException("'expect' must look like 200 or 200-399, not '{$shown}'");
            }

            $min = (int) $m[1];
            $max = isset($m[2]) ? (int) $m[2] : $min;

            if ($min > $max) {
                throw new InvalidArgumentException("'expect' range {$min}-{$max} is backwards");
            }

            if ($min < 100 || $max > 599) {
                throw new InvalidArgumentException("'expect' statuses must be between 100 and 599");
            }

            $ranges[] = [$min, $max];
        }

        return new self($ranges);
    }

    public function contains(int $status): bool
    {
        foreach ($this->ranges as [$min, $max]) {
            if ($status >= $min && $status <= $max) {
                return true;
            }
        }

        return false;
    }

    public function __toString(): string
    {
        return implode(',', array_map(fn ($r) => $r[0] === $r[1] ? (string) $r[0] : "{$r[0]}-{$r[1]}", $this->ranges));
    }
}
