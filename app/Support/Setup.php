<?php

namespace App\Support;

use App\Models\User;
use Throwable;

/** First-run state: LabMan is "set up" once an admin exists, permanently. */
final class Setup
{
    private static bool $complete = false;

    public static function isComplete(): bool
    {
        if (\App\Node\Mode::isNode()) {
            return true; // a node has no accounts and no setup
        }

        if (self::$complete) {
            return true; // monotonic: once true it never goes back
        }

        try {
            return self::$complete = User::query()->where('role', User::ROLE_ADMIN)->exists();
        } catch (Throwable) {
            return false; // tables not migrated yet
        }
    }

    /** For tests. */
    public static function reset(): void
    {
        self::$complete = false;
    }
}
