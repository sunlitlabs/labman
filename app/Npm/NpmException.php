<?php

namespace App\Npm;

use RuntimeException;

/** A failure talking to Nginx Proxy Manager, worded for the admin who has to fix it. */
final class NpmException extends RuntimeException {}
