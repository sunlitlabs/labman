<?php

namespace App\Semaphore;

use RuntimeException;

/** A failure talking to Semaphore (or a rejected run request), worded for the admin. */
final class SemaphoreException extends RuntimeException {}
