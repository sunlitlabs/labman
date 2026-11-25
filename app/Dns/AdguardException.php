<?php

namespace App\Dns;

use RuntimeException;

/** A failure talking to AdGuard Home, worded for the admin who has to fix it. */
final class AdguardException extends RuntimeException {}
