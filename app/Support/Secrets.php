<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Encrypted key/value rows in the `settings` table (tokens, passwords). Values are encrypted
 * with the application key; they never appear in Settings::all(), the YAML export, a page or an MCP tool.
 */
final class Secrets
{
    public static function put(string $key, string $value): void
    {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => Crypt::encryptString($value)]);
    }

    /** The decrypted value, or null if missing or unreadable (the application key changed). */
    public static function get(string $key): ?string
    {
        $raw = Setting::query()->whereKey($key)->value('value');

        if ($raw === null) {
            return null;
        }

        try {
            return Crypt::decryptString($raw);
        } catch (DecryptException) {
            return null;
        }
    }

    /** Plain (non-secret) companion values such as URLs. */
    public static function putPlain(string $key, string $value): void
    {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    public static function plain(string $key): ?string
    {
        return Setting::query()->whereKey($key)->value('value');
    }

    /** @param list<string> $keys */
    public static function forget(array $keys): void
    {
        Setting::query()->whereIn('key', $keys)->delete();
    }
}
