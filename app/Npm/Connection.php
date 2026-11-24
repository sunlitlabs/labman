<?php

namespace App\Npm;

use App\Models\Setting;
use App\Models\Zone;
use App\Support\Zones;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * The saved Nginx Proxy Manager logins, one per zone (the default zone keeps the original, single-proxy keys).
 * The e-mail and password are encrypted with the application key (which lives in the data volume); they are never
 * shown again, never exported to YAML and never returned by an MCP tool.
 *
 * Every method takes an optional zone; none means the default zone, so single-proxy callers are unchanged.
 */
final class Connection
{
    private const FIELDS = ['url', 'email', 'password', 'verify_tls'];

    /** @return list<string> the setting keys of one zone's login */
    private static function keys(?Zone $zone): array
    {
        $zone ??= Zones::default();
        $prefix = $zone === null || $zone->is_default ? 'npm.' : 'npm.z'.$zone->id.'.';

        return array_map(fn ($f) => $prefix.$f, self::FIELDS);
    }

    public static function exists(?Zone $zone = null): bool
    {
        return self::get($zone) !== null;
    }

    /** Does any zone have a login? (What decides whether NPM features are on at all.) */
    public static function anyExists(): bool
    {
        return self::connectedZones() !== [];
    }

    /** The zones that have a readable login, most trusted first. @return list<Zone> */
    public static function connectedZones(): array
    {
        return Zones::all()->filter(fn (Zone $z) => self::exists($z))->values()->all();
    }

    /** @return array{url: string, email: string, password: string, verify_tls: bool}|null */
    public static function get(?Zone $zone = null): ?array
    {
        [$url, $email, $password, $verify] = self::keys($zone);
        $rows = Setting::query()->whereIn('key', [$url, $email, $password, $verify])->pluck('value', 'key');

        if (! isset($rows[$url], $rows[$email], $rows[$password])) {
            return null;
        }

        try {
            return [
                'url' => $rows[$url],
                'email' => Crypt::decryptString($rows[$email]),
                'password' => Crypt::decryptString($rows[$password]),
                'verify_tls' => ($rows[$verify] ?? '1') === '1',
            ];
        } catch (DecryptException) {
            return null; // the application key changed: the saved login is unreadable
        }
    }

    /** What is safe to display: no password. @return array{url: string, email: string, verify_tls: bool}|null */
    public static function summary(?Zone $zone = null): ?array
    {
        $c = self::get($zone);

        return $c ? ['url' => $c['url'], 'email' => $c['email'], 'verify_tls' => $c['verify_tls']] : null;
    }

    public static function save(string $url, string $email, string $password, bool $verifyTls, ?Zone $zone = null): void
    {
        [$kUrl, $kEmail, $kPassword, $kVerify] = self::keys($zone);
        $values = [
            $kUrl => $url,
            $kEmail => Crypt::encryptString($email),
            $kPassword => Crypt::encryptString($password),
            $kVerify => $verifyTls ? '1' : '0',
        ];

        foreach ($values as $key => $value) {
            Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
    }

    public static function forget(?Zone $zone = null): void
    {
        Setting::query()->whereIn('key', self::keys($zone))->delete();

        if (! self::anyExists()) {
            NpmAuto::forget(); // the automatic-update switch and its last result belong to "NPM is connected at all"
        }
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
            return \App\Support\BaseUrl::origin($url, 'http://192.0.2.5:81');
        } catch (\InvalidArgumentException $e) {
            throw new NpmException($e->getMessage());
        }
    }
}
