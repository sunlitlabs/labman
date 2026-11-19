<?php

namespace App\Support;

use InvalidArgumentException;

/** Validation of the "base address" an admin types for an external service (NPM, Semaphore, a git host). */
final class BaseUrl
{
    /**
     * Like clean(), but forgiving: whatever page of the service was pasted (http://host:81/nginx/proxy), only
     * the address up to the port is kept. Credentials in the URL are still refused.
     *
     * @throws InvalidArgumentException
     */
    public static function origin(string $url, string $example = 'http://192.0.2.5:81'): string
    {
        $parts = parse_url(trim($url));

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return self::clean($url, $example); // let clean() explain what is wrong
        }

        $auth = isset($parts['user']) ? $parts['user'].(isset($parts['pass']) ? ':'.$parts['pass'] : '').'@' : '';

        return self::clean($parts['scheme'].'://'.$auth.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : ''), $example);
    }

    /**
     * Normalise `http(s)://host[:port]`: a valid host name or IP, optional port, no credentials,
     * no query or fragment, and (unless $allowPath) no path. Returns the cleaned URL without a trailing slash.
     *
     * @throws InvalidArgumentException with a message fit to show the admin
     */
    public static function clean(string $url, string $example = 'http://192.0.2.5:81', bool $allowPath = false): string
    {
        $parts = parse_url(trim($url));

        if ($parts === false || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || (! $allowPath && isset($parts['path']) && trim($parts['path'], '/') !== '')) {
            throw new InvalidArgumentException("Enter the address as http(s)://host[:port]".($allowPath ? '/path' : '').", for example {$example}.");
        }

        $host = trim($parts['host'], '[]');
        if (! Hosts::isHostOrIp($host)) {
            throw new InvalidArgumentException("'{$host}' is not a valid host name or IP address.");
        }

        $path = $allowPath ? rtrim($parts['path'] ?? '', '/') : '';
        if ($path !== '' && ! preg_match('#^/[A-Za-z0-9._~%/\-]*$#', $path)) {
            throw new InvalidArgumentException('The address path contains characters that are not allowed.');
        }

        return $parts['scheme'].'://'.Hosts::forUrl($host).(isset($parts['port']) ? ':'.$parts['port'] : '').$path;
    }
}
