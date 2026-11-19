<?php

namespace App\Npm;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * The saved Nginx Proxy Manager login. The e-mail and password are encrypted with
 * the application key (which lives in the data volume); they are never shown again,
 * never exported to YAML and never returned by an MCP tool.
 */
final class Connection
{
    private const KEYS = ['npm.url', 'npm.email', 'npm.password', 'npm.verify_tls'];

    public static function exists(): bool
    {
        return self::get() !== null;
    }

    /** @return array{url: string, email: string, password: string, verify_tls: bool}|null */
    public static function get(): ?array
    {
        $rows = Setting::query()->whereIn('key', self::KEYS)->pluck('value', 'key');

        if (! isset($rows['npm.url'], $rows['npm.email'], $rows['npm.password'])) {
            return null;
        }

        try {
            return [
                'url' => $rows['npm.url'],
                'email' => Crypt::decryptString($rows['npm.email']),
                'password' => Crypt::decryptString($rows['npm.password']),
                'verify_tls' => ($rows['npm.verify_tls'] ?? '1') === '1',
            ];
        } catch (DecryptException) {
            return null; // the application key changed: the saved login is unreadable
        }
    }

    /** What is safe to display: no password. @return array{url: string, email: string, verify_tls: bool}|null */
    public static function summary(): ?array
    {
        $c = self::get();

        return $c ? ['url' => $c['url'], 'email' => $c['email'], 'verify_tls' => $c['verify_tls']] : null;
    }

    public static function save(string $url, string $email, string $password, bool $verifyTls): void
    {
        $values = [
            'npm.url' => $url,
            'npm.email' => Crypt::encryptString($email),
            'npm.password' => Crypt::encryptString($password),
            'npm.verify_tls' => $verifyTls ? '1' : '0',
        ];

        foreach ($values as $key => $value) {
            Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
    }

    public static function forget(): void
    {
        Setting::query()->whereIn('key', self::KEYS)->delete();
    }

    /**
     * Normalise and validate the base URL an admin typed: http(s), a valid host or IP,
     * optional port, no credentials, no path. Returns the cleaned URL.
     *
     * @throws NpmException
     */
    public static function cleanUrl(string $url): string
    {
        try {
            return \App\Support\BaseUrl::clean($url, 'http://192.0.2.5:81');
        } catch (\InvalidArgumentException $e) {
            throw new NpmException($e->getMessage());
        }
    }
}
