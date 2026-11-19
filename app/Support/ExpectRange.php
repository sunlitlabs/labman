<?php

namespace App\Support;

use InvalidArgumentException;
use Stringable;

/** The healthy HTTP status range of a service: "200", "401" or "200-399". */
final class ExpectRange implements Stringable
{
    public const DEFAULT = '200-399';

    public function __construct(public readonly int $min, public readonly int $max) {}

    /** @throws InvalidArgumentException with a human readable reason */
    public static function parse(mixed $value): self
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value) || ! preg_match('/^\s*(\d{3})(?:\s*-\s*(\d{3}))?\s*$/', $value, $m)) {
            $shown = is_scalar($value) ? (string) $value : gettype($value);
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

        return new self($min, $max);
    }

    public function contains(int $status): bool
    {
        return $status >= $this->min && $status <= $this->max;
    }

    public function __toString(): string
    {
        return $this->min === $this->max ? (string) $this->min : "{$this->min}-{$this->max}";
    }
}
