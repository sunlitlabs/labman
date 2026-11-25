<?php

namespace App\Monitoring;

use App\Support\Hosts;

/**
 * Concurrent TCP connect checks using non-blocking sockets and stream_select().
 * All targets are started at once and polled together, so one dead or slow host
 * costs at most `$timeout` seconds for the whole batch, never per host.
 *
 * Limitation: when a target is a host *name* the resolver lookup happens inside
 * stream_socket_client() and is bounded by the system resolver, not by $timeout.
 */
final class TcpProbe
{
    private const ERRORS = [
        111 => 'connection refused',
        113 => 'no route to host',
        101 => 'network is unreachable',
        110 => 'timed out',
        104 => 'connection reset',
        112 => 'host is down',
    ];

    /** The IPv4 address to connect to: the host itself when it is an address (v6 literals are kept as typed), else its first A record. */
    public static function ipv4(string $host): ?string
    {
        $bare = trim($host, '[]');
        if (filter_var($bare, FILTER_VALIDATE_IP) !== false) {
            return $bare;
        }
        $all = @gethostbynamel($bare);

        return $all ? $all[0] : null;
    }

    /**
     * @param  array<string|int, array{0: string, 1: int, 2: string}>  $targets  key => [host, port, label]
     * @return array<string|int, CheckResult>
     */
    public static function probe(array $targets, float $timeout): array
    {
        $results = [];
        /** @var array<int, array{key: string|int, sock: resource, start: float, label: string}> $pending */
        $pending = [];

        foreach ($targets as $key => [$host, $port, $label]) {
            $start = microtime(true);
            $errno = 0;
            $errstr = '';
            // IPv4 only: a name that also has an AAAA record must not be tried over a broken IPv6 route.
            $ip = self::ipv4($host);
            if ($ip === null) {
                $results[$key] = new CheckResult($label, false, self::elapsed($start), 'could not resolve host');

                continue;
            }

            $sock = @stream_socket_client(
                'tcp://'.Hosts::forUrl($ip).':'.$port,
                $errno,
                $errstr,
                $timeout,
                STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
            );

            if ($sock === false) {
                $results[$key] = new CheckResult($label, false, self::elapsed($start), self::describe($errno, $errstr));

                continue;
            }

            stream_set_blocking($sock, false);
            $pending[(int) $sock] = ['key' => $key, 'sock' => $sock, 'start' => $start, 'label' => $label];
        }

        while ($pending) {
            $now = microtime(true);

            foreach ($pending as $id => $p) {
                if ($now >= $p['start'] + $timeout) {
                    $results[$p['key']] = new CheckResult($p['label'], false, self::elapsed($p['start']), 'timed out');
                    fclose($p['sock']);
                    unset($pending[$id]);
                }
            }

            if (! $pending) {
                break;
            }

            $nextDeadline = min(array_map(fn ($p) => $p['start'] + $timeout, $pending));
            $wait = max(0.005, min(0.25, $nextDeadline - microtime(true)));

            $write = array_map(fn ($p) => $p['sock'], $pending);
            $read = null;
            $except = null;

            $ready = @stream_select($read, $write, $except, 0, (int) ($wait * 1_000_000));

            if ($ready === false) {
                foreach ($pending as $p) {
                    $results[$p['key']] = new CheckResult($p['label'], false, self::elapsed($p['start']), 'socket error');
                    fclose($p['sock']);
                }
                break;
            }

            foreach ($write as $sock) {
                $id = (int) $sock;
                $p = $pending[$id];
                $errno = self::socketError($sock);
                $ok = $errno === null ? @stream_socket_get_name($sock, true) !== false : $errno === 0;

                $results[$p['key']] = new CheckResult(
                    $p['label'],
                    $ok,
                    self::elapsed($p['start']),
                    $ok ? 'connected' : self::describe($errno ?? 111, ''),
                );
                fclose($sock);
                unset($pending[$id]);
            }
        }

        return $results;
    }

    /** SO_ERROR of a connecting socket (0 = connected), or null when ext-sockets is missing. @param resource $stream */
    private static function socketError($stream): ?int
    {
        if (! function_exists('socket_import_stream')) {
            return null;
        }

        $socket = @socket_import_stream($stream);
        if (! $socket) {
            return null;
        }

        $error = socket_get_option($socket, SOL_SOCKET, SO_ERROR);
        unset($socket);

        return is_int($error) ? $error : null;
    }

    private static function describe(int $errno, string $errstr): string
    {
        if (isset(self::ERRORS[$errno])) {
            return self::ERRORS[$errno];
        }

        if (stripos($errstr, 'getaddrinfo') !== false || stripos($errstr, 'name or service') !== false) {
            return 'could not resolve host';
        }

        if ($errno > 0 && function_exists('socket_strerror')) {
            return strtolower(socket_strerror($errno));
        }

        return $errstr !== '' ? strtolower($errstr) : 'connection failed';
    }

    private static function elapsed(float $start): int
    {
        return (int) round((microtime(true) - $start) * 1000);
    }
}
