<?php

namespace App\Monitoring;

use App\Models\Server;
use App\Models\Service;
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
 * Runs one health-check round: every server (TCP) and every service (HTTP),
 * concurrently, then stores the outcome on the rows. Pages and the JSON endpoint
 * only ever read what is stored here.
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

        $servers = Server::all();
        $services = Service::with('server')->get();

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

        return [
            'servers' => count($serverOutcomes),
            'services' => count($serviceOutcomes),
            'seconds' => round(microtime(true) - $started, 2),
        ];
    }

    /**
     * TCP connect to the SSH port plus every extra port.
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
                $label = $port === (int) $server->ssh_port ? "ssh :{$port}" : "tcp :{$port}";
                $targets["{$server->id}:{$port}"] = [$server->address, $port, $label];
                $order[$server->id][] = "{$server->id}:{$port}";
            }
        }

        $results = TcpProbe::probe($targets, $timeout);

        $outcomes = [];
        foreach ($order as $id => $keys) {
            $checks = array_values(array_filter(array_map(fn ($k) => $results[$k] ?? null, $keys)));
            $outcomes[$id] = ['state' => Verdict::server($checks), 'checks' => $checks];
        }

        return $outcomes;
    }

    /**
     * Backend GET (TLS not verified, redirects not followed) and, when the service
     * has a domain and public_check is on, a second GET to https://<domain><path>.
     *
     * @param  iterable<Service>  $services
     * @return array<int, array{state: string, checks: list<CheckResult>}>  keyed by service id
     */
    public function checkServices(iterable $services, float $timeout): array
    {
        $outcomes = [];
        $requests = [];
        $meta = [];

        foreach ($services as $service) {
            $backend = $service->backendUrl();

            if ($backend === null) {
                $none = new CheckResult('backend', false, null, 'no address');
                $outcomes[$service->id] = ['state' => Verdict::DOWN, 'checks' => [$none]];

                continue;
            }

            $meta[$service->id] = $service;
            $requests["{$service->id}|backend"] = [$backend, false];

            if ($service->domain && $service->public_check) {
                $requests["{$service->id}|public"] = [$service->publicUrl(), (bool) $service->verify_tls];
            }
        }

        $times = [];
        $responses = $requests === [] ? [] : Http::pool(function (Pool $pool) use ($requests, $timeout, &$times) {
            $calls = [];
            foreach ($requests as $key => [$url, $verify]) {
                $calls[] = $pool->as($key)
                    ->withUserAgent('LabMan/1.0 (health check)')
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

        foreach ($meta as $id => $service) {
            $range = $service->expectRange();
            $backend = $this->interpret('backend', $responses["{$id}|backend"] ?? null, $times["{$id}|backend"] ?? null, $range);
            $public = isset($requests["{$id}|public"])
                ? $this->interpret('public', $responses["{$id}|public"] ?? null, $times["{$id}|public"] ?? null, $range)
                : null;

            $outcomes[$id] = [
                'state' => Verdict::service($backend, $public),
                'checks' => array_values(array_filter([$backend, $public])),
            ];
        }

        return $outcomes;
    }

    private function interpret(string $label, mixed $response, ?float $seconds, \App\Support\ExpectRange $range): CheckResult
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

    private function describeFailure(string $message): string
    {
        $lower = strtolower($message);

        return match (true) {
            str_contains($lower, 'curl error 28'), str_contains($lower, 'timed out') => 'timed out',
            str_contains($lower, 'curl error 6'), str_contains($lower, 'resolve host') => 'could not resolve host',
            (bool) preg_match('/curl error (60|51|58|77|83|90|91)\b/', $lower) => 'certificate: '.$this->curlReason($message),
            str_contains($lower, 'curl error 35'), str_contains($lower, 'curl error 1:') => 'TLS/protocol error: '.$this->curlReason($message),
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
