<?php

namespace App\Monitoring;

use App\Models\Service;
use App\Support\ExpectRange;
use App\Support\Hosts;
use GuzzleHttp\TransferStats;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * "Why is this red, and what would make it green?" Probes one service several ways at once (port, http and
 * https, with and without the domain as Host header, a handful of likely health paths, and the other common
 * web ports on the same address) and recommends the setting that works. Admin-triggered, one service at a time;
 * it connects only to the address stored for that service.
 */
final class Diagnoser
{
    /** Web ports that are worth mentioning when the configured one does not answer. */
    public const OTHER_PORTS = [80, 443, 8080, 8443, 8000, 8008, 8081, 8888, 3000, 3030, 4408, 5000, 9000, 9090, 631];

    private const MAX_PATHS = 8;

    public function __construct(private readonly HealthChecker $checker = new HealthChecker) {}

    /**
     * @return array<string, mixed>
     */
    public function run(Service $service, float $timeout): array
    {
        $address = $service->backendAddress();

        if ($address === null) {
            return ['error' => 'This service has no address (no server and no address of its own), so there is nothing to probe.'];
        }

        $port = $service->effectivePort();
        $hint = HealthHints::find($service->name, $service->domain);
        $range = $service->expectRange();

        // 1. Ports: the configured one, then the other common web ports on the same address.
        $targets = ['main' => [$address, $port, "tcp :{$port}"]];
        foreach (self::OTHER_PORTS as $other) {
            if ($other !== $port) {
                $targets["p{$other}"] = [$address, $other, "tcp :{$other}"];
            }
        }
        $ports = TcpProbe::probe($targets, $timeout);
        $main = $ports['main'];
        $openOthers = array_values(array_map(fn ($k) => (int) substr($k, 1), array_keys(array_filter($ports, fn ($r, $k) => $k !== 'main' && $r->ok, ARRAY_FILTER_USE_BOTH))));

        // 2. Web requests: only worth sending to ports that accepted a connection.
        $attempts = [];
        $jobs = [];
        $n = 0;
        $paths = $this->paths($service, $hint);
        $schemes = array_values(array_unique([$service->scheme, $service->scheme === 'https' ? 'http' : 'https']));
        $hostHeaders = $service->domain ? [null, $service->domain] : [null];

        if ($main->ok) {
            foreach ($schemes as $scheme) {
                foreach ($hostHeaders as $host) {
                    foreach ($paths as $path) {
                        $jobs["a{$n}"] = ['port' => $port, 'scheme' => $scheme, 'host' => $host, 'path' => $path];
                        $n++;
                    }
                }
            }
        }

        // Other open ports: a single look at "/" over both schemes, to say what lives there.
        foreach ($openOthers as $other) {
            foreach (['http', 'https'] as $scheme) {
                $jobs["a{$n}"] = ['port' => $other, 'scheme' => $scheme, 'host' => null, 'path' => '/'];
                $n++;
            }
        }

        $times = [];
        $responses = $jobs === [] ? [] : Http::pool(function (Pool $pool) use ($jobs, $address, $timeout, &$times) {
            $calls = [];
            foreach ($jobs as $key => $job) {
                $calls[] = $pool->as($key)
                    ->withUserAgent('LabMan/1.0 (diagnose)')
                    ->withHeaders($job['host'] ? ['Host' => $job['host']] : [])
                    ->timeout($timeout)->connectTimeout($timeout)->withoutRedirecting()
                    ->withOptions(['verify' => false, 'curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4], 'on_stats' => function (TransferStats $s) use (&$times, $key) {
                        $times[$key] = $s->getTransferTime();
                    }])
                    ->get($job['scheme'].'://'.Hosts::forUrl($address).':'.$job['port'].$job['path']);
            }

            return $calls;
        });

        foreach ($jobs as $key => $job) {
            $response = $responses[$key] ?? null;
            $status = $response instanceof Response ? $response->status() : null;
            $attempts[] = $job + [
                'status' => $status,
                'ms' => isset($times[$key]) ? (int) round($times[$key] * 1000) : null,
                'detail' => $this->checker->interpret('x', $response, $times[$key] ?? null, $range)->detail,
                'score' => self::score($status),
            ];
        }

        $mine = array_values(array_filter($attempts, fn ($a) => $a['port'] === $port));
        $current = null;
        foreach ($mine as $a) {
            if ($a['scheme'] === $service->scheme && $a['host'] === null && $a['path'] === $service->healthPath()) {
                $current = $a;
            }
        }

        $otherPorts = $this->describeOthers($openOthers, $attempts);

        return [
            'address' => $address, 'port' => $port, 'scheme' => $service->scheme, 'path' => $service->healthPath(), 'expect' => (string) $range,
            'port_result' => $main->toArray(), 'attempts' => $mine, 'other_ports' => $otherPorts, 'current' => $current,
            'hint' => $hint,
        ] + $this->recommend($service, $main, $mine, $otherPorts, $range);
    }

    /** How good an answer is as evidence that the app is alive. */
    public static function score(?int $status): int
    {
        return match (true) {
            $status === null => 0,
            $status >= 200 && $status < 300 => 100,
            $status >= 300 && $status < 400 => 90,
            $status === 401 || $status === 403 => 70,
            $status === 404 => 40,
            $status >= 400 && $status < 500 => 30,
            default => 10,
        };
    }

    /** @return list<string> */
    private function paths(Service $service, ?array $hint): array
    {
        $paths = array_filter([$service->health_path, $hint['health_path'] ?? null, $service->path, ...HealthHints::COMMON_PATHS]);

        return array_slice(array_values(array_unique($paths)), 0, self::MAX_PATHS);
    }

    /**
     * @param  list<int>  $open
     * @param  list<array<string, mixed>>  $attempts
     * @return list<array{port: int, scheme: ?string, status: ?int, detail: string}>
     */
    private function describeOthers(array $open, array $attempts): array
    {
        $out = [];

        foreach ($open as $port) {
            $best = null;
            foreach ($attempts as $a) {
                if ($a['port'] === $port && ($best === null || $a['score'] > $best['score'])) {
                    $best = $a;
                }
            }

            $out[] = ['port' => $port, 'scheme' => ($best && $best['score'] > 0) ? $best['scheme'] : null, 'status' => $best['status'] ?? null,
                'detail' => ($best && $best['score'] > 0) ? 'answers HTTP '.$best['status'].' over '.$best['scheme'] : 'open, but no web answer'];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $attempts
     * @param  list<array<string, mixed>>  $otherPorts
     * @return array{kind: string, message: string, changes: array<string, mixed>, notes: list<string>}
     */
    private function recommend(Service $service, CheckResult $main, array $attempts, array $otherPorts, ExpectRange $range): array
    {
        $notes = [];

        if (! $main->ok) {
            if (str_contains($main->detail, 'refused')) {
                $listening = array_values(array_filter($otherPorts, fn ($o) => $o['scheme'] !== null));

                return [
                    'kind' => $listening ? 'other_port' : 'refused',
                    'message' => $listening
                        ? "Nothing is listening on port {$service->effectivePort()}, but the same address answers on: ".implode(', ', array_map(fn ($o) => ":{$o['port']} ({$o['scheme']})", $listening)).'. If one of those is this service, point it there.'
                        : "The address is reachable, but nothing is listening on port {$service->effectivePort()}. The service may be stopped (or the device asleep), or the port is wrong.",
                    'changes' => $listening ? ['port' => $listening[0]['port'], 'scheme' => $listening[0]['scheme']] : [],
                    'notes' => $notes,
                ];
            }

            return [
                'kind' => 'unreachable',
                'message' => "Nothing answers from LabMan's side ({$main->detail}). That is usually a firewall or VLAN rule between LabMan and this address, or the device is switched off. "
                    .($service->domain && $service->public_check ? "This service has a public address, which counts as a check: if it answers, the service shows as up anyway." : 'If you cannot open that path for LabMan, you can switch monitoring off for this service.'),
                'changes' => [],
                'notes' => $notes,
            ];
        }

        // The port is open. Find the answer that says "alive" most convincingly, preferring what is already set.
        $best = null;
        foreach ($attempts as $a) {
            $rank = $a['score']
                + ($a['scheme'] === $service->scheme ? 3 : 0)
                + ($a['host'] === null ? 2 : 0)
                + ($a['path'] === $service->healthPath() ? 2 : 0);

            if ($a['score'] > 0 && ($best === null || $rank > $best['rank'])) {
                $best = $a + ['rank' => $rank];
            }
        }

        if ($best === null || $best['score'] < 70) {
            return [
                'kind' => 'port_only',
                'message' => 'The port is open but web requests do not get a convincing answer. Keeping "an open port counts as alive" on is the right check for this service.',
                'changes' => ['check_tcp' => true],
                'notes' => $notes,
            ];
        }

        $changes = [];
        if ($best['scheme'] !== $service->scheme) {
            $changes['scheme'] = $best['scheme'];
            $notes[] = "It answers over {$best['scheme']}, but the scheme is set to {$service->scheme}.";
        }
        if ($best['path'] !== $service->healthPath()) {
            $changes['health_path'] = $best['path'];
            $notes[] = "{$best['path']} answers HTTP {$best['status']}, which is a better thing to check than {$service->healthPath()}.";
        }
        if ($best['host'] !== null) {
            $notes[] = "It only answers properly when asked for the host name {$best['host']}; LabMan already sends that automatically for services with a domain.";
        }
        if (! $range->contains($best['status'])) {
            $changes['expect'] = $range.','.$best['status'];
            $notes[] = "HTTP {$best['status']} is not in the accepted statuses ({$range}); add it.";
        }

        return [
            'kind' => $changes === [] ? 'ok' : 'tune',
            'message' => $changes === []
                ? "This service is fine as configured: {$best['scheme']}://…:{$service->effectivePort()}{$best['path']} answers HTTP {$best['status']}."
                : 'These settings would make the check pass.',
            'changes' => $changes,
            'notes' => $notes,
        ];
    }
}
