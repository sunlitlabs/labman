<?php

namespace App\Support;

use Sunlit\Foundation\Setup\SetupState;

/**
 * First-run state: LabMan is "set up" once an admin exists, permanently.
 *
 * The monotonic state and the /setup redirect come from sunlitlabs/foundation; the rule
 * ("an admin exists", and a node has no setup at all) is registered in AppServiceProvider.
 */
final class Setup
{
    public static function isComplete(): bool
    {
        return app(SetupState::class)->isComplete();
    }

    /** Forget the remembered state (after the wizard, and between tests). */
    public static function reset(): void
    {
        app(SetupState::class)->reset();
    }
}
