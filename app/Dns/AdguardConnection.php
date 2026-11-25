<?php

namespace App\Dns;

use App\Models\Setting;
use App\Support\BaseUrl;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * The saved AdGuard Home login (one resolver: the "inside" one of the DNS plan). The user name and password are
 * encrypted with the application key; they are never shown again, never exported and never returned by an MCP tool.
 * AdGuard Home can run without a login, so both may be empty.
 */
final class AdguardConnection
{
    private const KEYS = ['adguard.url', 'adguard.username', 'adguard.password', 'adguard.verify_tls'];

    public static function exists(): bool
    {
        return self::get() !== null;
    }

    /** @return array{url: string, username: string, password: string, verify_tls: bool}|null */
    public static function get(): ?array
    {
        $rows = Setting::query()->whereIn('key', self::KEYS)->pluck('value', 'key');

        if (! isset($rows['adguard.url'], $rows['adguard.username'], $rows['adguard.password'])) {
            return null;
        }

        try {
            return [
                'url' => $rows['adguard.url'],
                'username' => Crypt::decryptString($rows['adguard.username']),
                'password' => Crypt::decryptString($rows['adguard.password']),
                'verify_tls' => ($rows['adguard.verify_tls'] ?? '1') === '1',
            ];
        } catch (DecryptException) {
            return null; // the application key changed: the saved login is unreadable
        }
    }

    /** What is safe to display: no password. @return array{url: string, username: string, verify_tls: bool}|null */
    public static function summary(): ?array
    {
        $c = self::get();

        return $c ? ['url' => $c['url'], 'username' => $c['username'], 'verify_tls' => $c['verify_tls']] : null;
    }

    public static function save(string $url, string $username, string $password, bool $verifyTls): void
    {
        $values = [
            'adguard.url' => $url,
            'adguard.username' => Crypt::encryptString($username),
            'adguard.password' => Crypt::encryptString($password),
            'adguard.verify_tls' => $verifyTls ? '1' : '0',
        ];

        foreach ($values as $key => $value) {
            Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
    }

    public static function forget(): void
    {
        Setting::query()->whereIn('key', self::KEYS)->delete();
    }

    /** @throws AdguardException */
    public static function cleanUrl(string $url): string
    {
        try {
            return BaseUrl::origin($url, 'http://192.0.2.53:3000');
        } catch (\InvalidArgumentException $e) {
            throw new AdguardException($e->getMessage());
        }
    }
}
