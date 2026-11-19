<?php

namespace App\Monitoring;

use App\Models\Server;
use App\Models\Service;
use App\Support\ExpectRange;
use App\Support\Hosts;
use App\Support\Settings;
use GuzzleHttp\TransferStats;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs one health-check round: every monitored server (TCP) and service (HTTP and TCP), concurrently, then
 * stores the outcome on the rows. Pages and the JSON endpoint only ever read what is stored here.
 *
 * A service is UP when ANY of its backend checks passes:
 *   - the web check (GET scheme://address:port/health_path-or-path, status within `expect`), also tried with the
 *     domain as the Host header for services behind name-based proxies, or
 *   - the port being open (switchable per service), which covers apps that answer oddly (401, 404, 500, the
 *     other scheme) but are plainly running.
 * It is also UP when only the public https://<domain> check passes (LabMan may simply not reach the backend), DEGRADED
 * when the backend is up but the public check fails, and DOWN only when nothing passes. Switched-off ("not monitored") items are skipped and shown as such.
 */
class HealthChecker
{
    /**
     * @return array{servers: int, services: int, seconds: float}
     */
    public function run(): array
    {
        $started = microtime(true);
        Settings::flush();
        $timeout = Settings::timeout();

        $servers = Server::where('monitor', true)->get();
        $services = Service::with('server')->where('monitor', true)->get();

        try {
            $serverOutcomes = $this->checkServers($servers, $timeout);
        } catch (Throwable $e) {
            Log::error('LabMan server checks failed: '.$e->getMessage());
            $serverOutcomes = [];
        }

        try {
            $serviceOutcomes = $this->checkServices($services, $timeout);
        } catch (Throwable $e) {
            Log::error('LabMan service checks failed: '.$e->getMessage());
            $serviceOutcomes = [];
        }

        $this->store('servers', $serverOutcomes);
        $this->store('services', $serviceOutcomes);

        // Items the admin switched off are shown as "not monitored", whatever they showed before.
        foreach (['servers', 'services'] as $table) {
            DB::table($table)->where('monitor', false)->where('state', '!=', Verdict::OFF)->update(['state' => Verdict::OFF, 'checks' => null]);
        }

        return [
            'servers' => count($serverOutcomes),
            'services' => count($serviceOutcomes),
            'seconds' => round(microtime(true) - $started, 2),
        ];
    }

    /**
     * TCP connect to the SSH port (unless switched off) plus every extra port.
     *
     * @param  iterable<Server>  $servers
     * @return array<int, array{state: string, checks: list<CheckResult>}>  keyed by server id
     */
    public function checkServers(iterable $servers, float $timeout): array
    {
        $targets = [];
        $order = [];

        foreach ($servers as $server) {
            foreach ($server->checkPorts() as $port) {
                $label = $port === (int) $server->ssh_port && $server->check_ssh ? "ssh :{$port}" : "tcp :{$port}";
                $targets["{$server->id}:{$port}"] = [$server->address, $port, $label];
                $order[$server->id][] = "{$server->id}:{$port}";
            }

            // Nothing to check (SSH off, no extra ports): there is nothing to report either.
            if (! isset($order[$server->id])) {
                $order[$server->id] = [];
            }
        }

        $results = TcpProbe::probe($targets, $timeout);

        $outcomes = [];
        foreach ($order as $id => $keys) {
            $checks = array_values(array_filter(array_map(fn ($k) => $results[$k] ?? null, $keys)));
            $outcomes[$id] = $keys === []
                ? ['state' => Verdict::OFF, 'checks' => []]
                : ['state' => Verdict::server($checks), 'checks' => $checks];
        }

        return $outcomes;
    }

    /**
     * @param  iterable<Service>  $services
     * @return array<int, array{state: string, checks: list<CheckResult>}>  keyed by service id
     */
    public function checkServices(iterable $services, float $timeout): array
    {
        $outcomes = [];
        $requests = [];   // key => [url, verify, headers]
        $meta = [];
        $tcp = [];

        foreach ($services as $service) {
            $address = $service->backendAddress();

            if ($address === null) {
                $outcomes[$service->id] = ['state' => Verdict::DOWN, 'checks' => [new CheckResult('backend', false, null, 'no address')]];

                continue;
            }

            $meta[$service->id] = $service;
            $url = $service->scheme.'://'.Hosts::forUrl($address).':'.$service->effectivePort().$service->healthPath();

            $requests["{$service->id}|configured"] = [$url, false, []];
            $hostName = $service->domain ?: $service->public_domain;
            if ($hostName) {
                // Name-based proxies answer the IP:port with their default site; ask for the right one.
                $requests["{$service->id}|host"] = [$url, false, ['Host' => $hostName]];
            }
            if ($service->public_check) {
                // Each name the service has is checked over HTTPS: the primary (usually local) one and the public one.
                foreach ($this->domainChecks($service) as $label => $domainUrl) {
                    $requests["{$service->id}|{$label}"] = [$domainUrl, (bool) $service->verify_tls, []];
                }
            }
            if ($service->check_tcp) {
                $tcp["{$service->id}:tcp"] = [$address, $service->effectivePort(), 'tcp :'.$service->effectivePort()];
            }
        }

        $times = [];
        $responses = $requests === [] ? [] : Http::pool(function (Pool $pool) use ($requests, $timeout, &$times) {
            $calls = [];
            foreach ($requests as $key => [$url, $verify, $headers]) {
                $calls[] = $pool->as($key)
                    ->withUserAgent('LabMan/1.0 (health check)')
                    ->withHeaders($headers)
                    ->timeout($timeout)
                    ->connectTimeout($timeout)
                    ->withoutRedirecting()
                    ->withOptions([
                        'verify' => $verify,
                        'on_stats' => function (TransferStats $stats) use (&$times, $key) {
                            $times[$key] = $stats->getTransferTime();
                        },
                    ])
                    ->get($url);
            }

            return $calls;
        });

        $tcpResults = $tcp === [] ? [] : TcpProbe::probe($tcp, $timeout);

        foreach ($meta as $id => $service) {
            $range = $service->expectRange();
            $web = $this->interpret('backend', $responses["{$id}|configured"] ?? null, $times["{$id}|configured"] ?? null, $range);

            if (! $web->ok && isset($requests["{$id}|host"])) {
                $viaHost = $this->interpret('backend', $responses["{$id}|host"] ?? null, $times["{$id}|host"] ?? null, $range);

                if ($viaHost->ok) {
                    $web = new CheckResult('backend', true, $viaHost->ms, $viaHost->detail.' (with Host: '.($service->domain ?: $service->public_domain).')');
                } elseif (! str_starts_with($web->detail, 'HTTP') && str_starts_with($viaHost->detail, 'HTTP')) {
                    $web = $viaHost; // at least this one got an answer: its detail explains more
                }
            }

            $port = $tcpResults["{$id}:tcp"] ?? null;
            $names = [];
            foreach (array_keys($this->domainChecks($service)) as $label) {
                if (isset($requests["{$id}|{$label}"])) {
                    $names[] = $this->interpret($label, $responses["{$id}|{$label}"] ?? null, $times["{$id}|{$label}"] ?? null, $range);
                }
            }

            // Up if ANY check passes. Degraded only when the backend is fine but one of its names is not (the proxy or DNS is broken).
            // Down only when nothing passes: a backend LabMan cannot reach directly (another VLAN) is still up if a name answers.
            $backendUp = $web->ok || ($port !== null && $port->ok);
            $nameUp = collect($names)->contains(fn (CheckResult $c) => $c->ok);
            $nameDown = collect($names)->contains(fn (CheckResult $c) => ! $c->ok);
            $state = match (true) {
                ! $backendUp && ! $nameUp => Verdict::DOWN,
                $backendUp && $nameDown => Verdict::DEGRADED,
                default => Verdict::UP,
            };

            // The port result is only worth showing when it is the reason the service is up (or an extra clue when it is down).
            $checks = [$web];
            if ($port !== null && ! $web->ok) {
                $checks[] = $port;
            }
            array_push($checks, ...$names);

            $outcomes[$id] = ['state' => $state, 'checks' => $checks];
        }

        return $outcomes;
    }

    /**
     * The HTTPS name checks of a service, by label. With only one name it is called "public" (as it always was);
     * with both, the primary one is "local" and the second "public".
     *
     * @return array<string, string> label => URL
     */
    private function domainChecks(Service $service): array
    {
        $out = [];

        if ($service->domain) {
            $out[$service->public_domain ? 'local' : 'public'] = $service->localUrl();
        }
        if ($service->public_domain) {
            $out['public'] = $service->publicDomainUrl();
        }

        return $out;
    }

    /** Turn an HTTP response (or the exception that replaced it) into a check result. */
    public function interpret(string $label, mixed $response, ?float $seconds, ExpectRange $range): CheckResult
    {
        $ms = $seconds !== null ? (int) round($seconds * 1000) : null;

        if ($response instanceof Response) {
            $status = $response->status();
            $ok = $range->contains($status);

            return new CheckResult(
                $label,
                $ok,
                $ms,
                $ok ? "HTTP {$status}" : "HTTP {$status} (expected {$range})",
            );
        }

        $message = $response instanceof Throwable ? $response->getMessage() : 'no response';

        return new CheckResult($label, false, $ms, $this->describeFailure($message));
    }

    public function describeFailure(string $message): string
    {
        $lower = strtolower($message);

        return match (true) {
            str_contains($lower, 'curl error 28'), str_contains($lower, 'timed out') => 'timed out',
            str_contains($lower, 'curl error 6'), str_contains($lower, 'resolve host') => 'could not resolve host',
            (bool) preg_match('/curl error (60|51|58|77|83|90|91)\b/', $lower) => 'certificate: '.$this->curlReason($message),
            str_contains($lower, 'curl error 35'), str_contains($lower, 'curl error 1:') => 'TLS/protocol error: '.$this->curlReason($message),
            str_contains($lower, 'curl error 52'), str_contains($lower, 'empty reply') => 'empty reply (probably the wrong scheme: http vs https)',
            str_contains($lower, 'no route to host') => 'no route to host',
            str_contains($lower, 'unreachable') => 'network is unreachable',
            str_contains($lower, 'curl error 7'), str_contains($lower, 'refused') => 'connection refused',
            default => $this->curlReason($message),
        };
    }

    private function curlReason(string $message): string
    {
        if (preg_match('/cURL error \d+: (.*?)(?: \(see https?:\/\/|$)/s', $message, $m)) {
            return trim($m[1]);
        }

        return mb_substr(trim($message), 0, 160);
    }

    /** @param array<int, array{state: string, checks: list<CheckResult>}> $outcomes */
    private function store(string $table, array $outcomes): void
    {
        $now = now();

        foreach ($outcomes as $id => $outcome) {
            DB::table($table)->where('id', $id)->update([
                'state' => $outcome['state'],
                'checks' => json_encode(array_map(fn (CheckResult $c) => $c->toArray(), $outcome['checks'])),
                'checked_at' => $now,
            ]);
        }
    }
}
