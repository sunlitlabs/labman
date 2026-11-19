<?php

namespace App\Semaphore;

use App\Support\BaseUrl;
use App\Support\Secrets;

/** The saved Semaphore address and API token. The token is encrypted and never shown, exported or sent to an MCP client. */
final class Connection
{
    private const URL = 'semaphore.url';

    private const TOKEN = 'semaphore.token';

    private const VERIFY = 'semaphore.verify_tls';

    public static function exists(): bool
    {
        return self::get() !== null;
    }

    /** @return array{url: string, token: string, verify_tls: bool}|null */
    public static function get(): ?array
    {
        $url = Secrets::plain(self::URL);
        $token = Secrets::get(self::TOKEN);

        if ($url === null || $token === null) {
            return null;
        }

        return ['url' => $url, 'token' => $token, 'verify_tls' => (Secrets::plain(self::VERIFY) ?? '1') === '1'];
    }

    /** @return array{url: string, verify_tls: bool}|null */
    public static function summary(): ?array
    {
        $c = self::get();

        return $c ? ['url' => $c['url'], 'verify_tls' => $c['verify_tls']] : null;
    }

    public static function save(string $url, string $token, bool $verifyTls): void
    {
        Secrets::putPlain(self::URL, $url);
        Secrets::put(self::TOKEN, $token);
        Secrets::putPlain(self::VERIFY, $verifyTls ? '1' : '0');
    }

    public static function forget(): void
    {
        Secrets::forget([self::URL, self::TOKEN, self::VERIFY]);
    }

    /**
     * Validate, test and save a connection (shared by the web form and the MCP tool). A saved token is
     * only reused for the same address: it is never handed to a different server.
     *
     * @return array{user: string, version: string|null}
     *
     * @throws SemaphoreException
     */
    public static function connect(string $url, ?string $token, bool $verifyTls): array
    {
        $url = self::cleanUrl($url);
        $existing = self::get();
        $token = filled($token) ? $token : (($existing && $existing['url'] === $url) ? $existing['token'] : null);

        if ($token === null) {
            throw new SemaphoreException('Enter the Semaphore API token.');
        }

        $info = (new SemaphoreClient(['url' => $url, 'token' => $token, 'verify_tls' => $verifyTls]))->test();
        self::save($url, $token, $verifyTls);

        return $info;
    }

    /** @throws SemaphoreException */
    public static function cleanUrl(string $url): string
    {
        try {
            return BaseUrl::clean($url, 'http://192.0.2.7:3000', allowPath: true);
        } catch (\InvalidArgumentException $e) {
            throw new SemaphoreException($e->getMessage());
        }
    }
}
