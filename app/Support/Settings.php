<?php

namespace App\Support;

use App\Models\Setting;
use Throwable;

/**
 * Runtime settings stored in the `settings` table, merged over the defaults in
 * config/labman.php. Values are cached per process; the monitor loop calls
 * flush() every round so changes made in the UI are picked up without a restart.
 */
final class Settings
{
    /** @var array<string, mixed>|null */
    private static ?array $cache = null;

    /** @return array<string, mixed> */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $values = config('labman.defaults');

        try {
            foreach (Setting::query()->pluck('value', 'key') as $key => $value) {
                if (array_key_exists($key, $values) && $value !== null) {
                    $values[$key] = $value;
                }
            }
        } catch (Throwable) {
            // Database not migrated yet (e.g. during image build): defaults are fine.
        }

        foreach (['check_interval', 'timeout'] as $numeric) {
            $values[$numeric] = is_numeric($values[$numeric]) ? $values[$numeric] + 0 : config('labman.defaults.'.$numeric);
        }

        return self::$cache = $values;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::all()[$key] ?? $default;
    }

    /** @param array<string, mixed> $values */
    public static function set(array $values): void
    {
        $allowed = array_keys(config('labman.defaults'));

        foreach ($values as $key => $value) {
            if (in_array($key, $allowed, true)) {
                Setting::query()->updateOrCreate(['key' => $key], ['value' => (string) $value]);
            }
        }

        self::flush();
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    public static function title(): string
    {
        return (string) self::get('title', 'LabMan');
    }

    /** Whether service/server icons are shown (they are fetched once from a public icon library and cached). */
    public static function icons(): bool
    {
        return self::get('icons') !== '0';
    }

    public static function interval(): float|int
    {
        return max((float) config('labman.limits.check_interval_min'), (float) self::get('check_interval')) + 0;
    }

    public static function timeout(): float
    {
        return (float) min(
            config('labman.limits.timeout_max'),
            max(config('labman.limits.timeout_min'), (float) self::get('timeout')),
        );
    }

    /** @return 'everyone'|'users'|'admins' */
    public static function visibility(string $screen): string
    {
        $level = self::get('visibility.'.$screen);

        return in_array($level, config('labman.visibility_levels'), true)
            ? $level
            : config('labman.defaults.visibility.'.$screen, 'admins');
    }

    public static function registrationMode(): string
    {
        $mode = self::get('registration_mode');

        return in_array($mode, config('labman.registration_modes'), true) ? $mode : 'approval';
    }
}
