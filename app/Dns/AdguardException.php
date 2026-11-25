<?php

namespace App\Dns;

use RuntimeException;

/** A failure talking to AdGuard Home, worded for the admin who has to fix it. `status` is the HTTP status, when there was one. */
final class AdguardException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }
}