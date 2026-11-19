<?php

namespace App\Config;

use RuntimeException;

/** Thrown when a YAML config does not validate; carries every problem found. */
final class ConfigException extends RuntimeException
{
    /** @param list<string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode('; ', $errors));
    }
}
