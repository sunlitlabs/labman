<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Service;
use App\Monitoring\CheckResult;
use App\Monitoring\HealthChecker;
use App\Monitoring\StatusView;
use App\Monitoring\TcpProbe;
use App\Monitoring\Verdict;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\UsesLocalServers;
use Tests\TestCase;

/** Health state logic against real local endpoints (PHP's built-in server and raw TCP listeners). */
class HealthCheckTest extends TestCase
{
    use RefreshDatabase, UsesLocalServers;

    private function checker(): HealthChecker
    {
        return new HealthChecker;
    }

    private function server(array $attributes = []): Server
    {
        return Server::create($attributes + ['name' => 's'.uniqid(), 'address' => '127.0.0.1', 'ssh_port' => 22]);
    }

    private function service(int $port, string $path, array $attributes = []): Service
    {
        return Service::create($attributes + [
            'name' => 'svc'.uniqid(), 'address' => '127.0.0.1', 'port' => $port,
            'scheme' => 'http', 'path' => $path, 'public_check' => false,
            // The web check on its own by default; the open-port fallback has its own tests below.
            'check_tcp' => false,
        ]);
    }

    /** @return array{state: string, checks: list<CheckResult>} */
    private function outcome(Service $service, float $timeout = 3): array
    {
        return $this->checker()->checkServices([$service->load('server')], $timeout)[$service->id];
    }

    // ------------------------------------------------------------------ servers

    public function test_server_is_up_when_every_port_answers(): void
    {
        $ssh = $this->openPort();
        $extra = $this->openPort();
        $server = $this->server(['ssh_port' => $ssh, 'ports' => [$extra]]);

        $result = $this->checker()->checkServers([$server], 2)[$server->id];

        $this->assertSame(Verdict::UP, $result['state']);
        $this->assertSame(['ssh :'.$ssh, 'tcp :'.$extra], array_map(fn ($c) => $c->label, $result['checks']));
        $this->assertContainsOnly('bool', array_map(fn ($c) => $c->ok, $result['checks']));
        $this->assertSame([true, true], array_map(fn ($c) => $c->ok, $result['checks']));
    }

    public function test_server_is_degraded_when_only_some_ports_answer(): void
    {
        $server = $this->server(['ssh_port' => $this->openPort(), 'ports' => [$this->closedPort()]]);

        $result = $this->checker()->checkServers([$server], 2)[$server->id];

        $this->assertSame(Verdict::DEGRADED, $result['state']);
        $this->assertSame('connection refused', $result['checks'][1]->detail);
    }

    public function test_server_is_down_when_nothing_answers(): void
    {
        $server = $this->server(['ssh_port' => $this->closedPort(), 'ports' => [$this->closedPort()]]);

        $result = $this->checker()->checkServers([$server], 2)[$server->id];

        $this->assertSame(Verdict::DOWN, $result['state']);
    }

    public function test_server_state_is_unknown_without_results_and_before_the_first_check(): void
    {
        $this->assertSame(Verdict::UNKNOWN, Verdict::server([]));

        $server = $this->server();
        $this->assertSame(Verdict::UNKNOWN, StatusView::of($server->fresh())['state']);
    }

    // ----------------------------------------------------------------- services

    public function test_a_redirect_counts_as_answered_and_is_not_followed(): void
    {
        $port = $this->startHttpServer();

        foreach (['/redirect' => 'HTTP 302', '/moved' => 'HTTP 301'] as $path => $detail) {
            $result = $this->outcome($this->service($port, $path));

            $this->assertSame(Verdict::UP, $result['state'], $path);
            $this->assertSame($detail, $result['checks'][0]->detail);
        }
    }

    public function test_default_expect_range_is_200_to_499(): void
    {
        $port = $this->startHttpServer();

        $ok = $this->outcome($this->service($port, '/ok'));
        $this->assertSame(Verdict::UP, $ok['state']);
        $this->assertSame('HTTP 200', $ok['checks'][0]->detail);
        $this->assertNotNull($ok['checks'][0]->ms);

        // Any answer that is not a server error means the app is running (401 login pages, 404 on "/", ...).
        foreach (['/nope', '/unauthorized', '/forbidden'] as $path) {
            $this->assertSame(Verdict::UP, $this->outcome($this->service($port, $path))['state'], $path);
        }

        $error = $this->outcome($this->service($port, '/error'));
        $this->assertSame(Verdict::DOWN, $error['state']);
        $this->assertSame('HTTP 500 (expected 200-499)', $error['checks'][0]->detail);
    }

    public function test_expect_accepts_a_list_of_ranges(): void
    {
        $port = $this->startHttpServer();

        $svc = fn (string $path) => $this->service($port, $path, ['expect' => '200,401']);

        $this->assertSame(Verdict::UP, $this->outcome($svc('/unauthorized'))['state']);
        $this->assertSame(Verdict::DOWN, $this->outcome($svc('/forbidden'))['state']);
    }

    // ------------------------------------------------- any check passing means up

    public function test_an_open_port_keeps_a_service_up_when_the_web_check_fails(): void
    {
        $port = $this->startHttpServer();

        $result = $this->outcome($this->service($port, '/error', ['check_tcp' => true]));

        $this->assertSame(Verdict::UP, $result['state']);
        $this->assertFalse($result['checks'][0]->ok);
        $this->assertTrue($result['checks'][1]->ok);
        $this->assertSame('tcp :'.$port, $result['checks'][1]->label);

        // With the port check switched off, the same service is down.
        $this->assertSame(Verdict::DOWN, $this->outcome($this->service($port, '/error', ['check_tcp' => false]))['state']);
    }

    public function test_a_closed_port_is_still_down_with_the_port_check_on(): void
    {
        $this->assertSame(Verdict::DOWN, $this->outcome($this->service($this->closedPort(), '/', ['check_tcp' => true]))['state']);
    }

    public function test_the_public_address_alone_is_enough_to_be_up(): void
    {
        Http::fake(['https://ha.example.com/*' => Http::response('ok', 200)]);

        // LabMan cannot reach the backend (another VLAN), but the public name answers.
        $result = $this->outcome($this->service($this->closedPort(), '/', ['domain' => 'ha.example.com', 'public_check' => true, 'check_tcp' => true]));

        $this->assertSame(Verdict::UP, $result['state']);
    }

    public function test_a_name_based_proxy_is_asked_for_the_right_host(): void
    {
        $port = $this->startHttpServer();

        // /vhost answers 200 only for Host: vhost.example.com and 421 for anything else.
        $plain = $this->outcome($this->service($port, '/vhost', ['expect' => '200']));
        $this->assertSame(Verdict::DOWN, $plain['state']);

        $named = $this->outcome($this->service($port, '/vhost', ['expect' => '200', 'domain' => 'vhost.example.com']));
        $this->assertSame(Verdict::UP, $named['state']);
        $this->assertStringContainsString('Host: vhost.example.com', $named['checks'][0]->detail);
    }

    public function test_a_separate_health_path_is_used_for_the_check(): void
    {
        $port = $this->startHttpServer();

        $result = $this->outcome($this->service($port, '/error', ['health_path' => '/ok']));

        $this->assertSame(Verdict::UP, $result['state']);
        $this->assertSame('HTTP 200', $result['checks'][0]->detail);
    }

    public function test_unmonitored_items_are_skipped_and_reported_as_off(): void
    {
        $port = $this->startHttpServer();
        $watched = $this->service($port, '/ok');
        $hidden = $this->service($this->closedPort(), '/', ['monitor' => false]);
        $quiet = $this->server(['monitor' => false, 'ssh_port' => $this->closedPort()]);

        $this->checker()->run();

        $this->assertSame(Verdict::UP, $watched->fresh()->state);
        $this->assertSame(Verdict::OFF, StatusView::of($hidden->fresh())['state']);
        $this->assertSame(Verdict::OFF, $hidden->fresh()->state);
        $this->assertSame(Verdict::OFF, StatusView::of($quiet->fresh())['state']);

        // Not counted in the totals.
        $this->assertSame(['up' => 1, 'degraded' => 0, 'down' => 0, 'unknown' => 0], StatusView::summary(Service::all()));
    }

    public function test_the_ssh_port_check_can_be_switched_off(): void
    {
        $extra = $this->openPort();
        $server = $this->server(['ssh_port' => $this->closedPort(), 'check_ssh' => false, 'ports' => [$extra]]);

        $result = $this->checker()->checkServers([$server], 2)[$server->id];

        $this->assertSame(Verdict::UP, $result['state']);
        $this->assertSame(['tcp :'.$extra], array_map(fn ($c) => $c->label, $result['checks']));

        $nothing = $this->server(['check_ssh' => false]);
        $this->assertSame(Verdict::OFF, $this->checker()->checkServers([$nothing], 2)[$nothing->id]['state']);
    }

    public function test_custom_expect_ranges_and_single_codes(): void
    {
        $port = $this->startHttpServer();

        $this->assertSame(Verdict::UP, $this->outcome($this->service($port, '/unauthorized', ['expect' => '401']))['state']);
        $this->assertSame(Verdict::UP, $this->outcome($this->service($port, '/forbidden', ['expect' => '200-499']))['state']);
        $this->assertSame(Verdict::UP, $this->outcome($this->service($port, '/created', ['expect' => '201']))['state']);

        $strict = $this->outcome($this->service($port, '/redirect', ['expect' => '200']));
        $this->assertSame(Verdict::DOWN, $strict['state']);
        $this->assertSame('HTTP 302 (expected 200)', $strict['checks'][0]->detail);
    }

    public function test_service_is_down_when_the_backend_refuses_or_times_out(): void
    {
        $refused = $this->outcome($this->service($this->closedPort(), '/'));
        $this->assertSame(Verdict::DOWN, $refused['state']);
        $this->assertSame('connection refused', $refused['checks'][0]->detail);

        $port = $this->startHttpServer();
        $slow = $this->outcome($this->service($port, '/slow'), 1);
        $this->assertSame(Verdict::DOWN, $slow['state']);
        $this->assertSame('timed out', $slow['checks'][0]->detail);
    }

    public function test_service_is_degraded_when_the_backend_is_ok_but_the_public_name_fails(): void
    {
        $port = $this->startHttpServer();

        // https://localhost/ has no listener here, so the public check fails while the backend is fine.
        $service = $this->service($port, '/ok', ['domain' => 'localhost', 'public_check' => true]);
        $result = $this->outcome($service);

        $this->assertSame(Verdict::DEGRADED, $result['state']);
        $this->assertSame(['backend', 'public'], array_map(fn ($c) => $c->label, $result['checks']));
        $this->assertTrue($result['checks'][0]->ok);
        $this->assertFalse($result['checks'][1]->ok);
    }

    public function test_service_is_up_when_backend_and_public_both_pass(): void
    {
        $port = $this->startHttpServer();
        Http::fake(['https://wiki.example.com/*' => Http::response('ok', 200)]);

        $service = $this->service($port, '/ok', ['domain' => 'wiki.example.com', 'public_check' => true]);
        $result = $this->outcome($service);

        $this->assertSame(Verdict::UP, $result['state']);
        $this->assertSame('HTTP 200', $result['checks'][1]->detail);
    }

    public function test_public_check_is_skipped_when_disabled_or_without_domain(): void
    {
        $port = $this->startHttpServer();

        $off = $this->outcome($this->service($port, '/ok', ['domain' => 'localhost', 'public_check' => false]));
        $this->assertCount(1, $off['checks']);
        $this->assertSame(Verdict::UP, $off['state']);

        $none = $this->outcome($this->service($port, '/ok', ['public_check' => true]));
        $this->assertCount(1, $none['checks']);
    }

    public function test_a_service_without_any_address_is_down(): void
    {
        $service = Service::create(['name' => 'orphan', 'scheme' => 'http', 'path' => '/']);

        $result = $this->outcome($service);

        $this->assertSame(Verdict::DOWN, $result['state']);
        $this->assertSame('no address', $result['checks'][0]->detail);
    }

    public function test_a_service_uses_its_linked_servers_address(): void
    {
        $port = $this->startHttpServer();
        $server = $this->server(['address' => '127.0.0.1']);

        $service = Service::create([
            'name' => 'linked', 'server_id' => $server->id, 'port' => $port, 'scheme' => 'http', 'path' => '/ok', 'public_check' => false,
        ]);

        $this->assertSame(Verdict::UP, $this->outcome($service)['state']);
    }

    // ------------------------------------------------------- dead hosts do not block

    public function test_dead_hosts_do_not_block_the_round_or_each_other(): void
    {
        $live = $this->openPort();

        $dead = $this->server(['name' => 'dead', 'address' => '192.0.2.1', 'ssh_port' => 22, 'ports' => [23, 24, 25, 26]]);
        $alive = $this->server(['name' => 'alive', 'ssh_port' => $live]);

        $start = microtime(true);
        $results = $this->checker()->checkServers([$dead, $alive], 1.0);
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(2.5, $elapsed, 'five dead ports on one host must cost ~1 timeout, not five');
        $this->assertSame(Verdict::DOWN, $results[$dead->id]['state']);
        $this->assertSame(Verdict::UP, $results[$alive->id]['state']);
    }

    public function test_dead_http_backends_do_not_block_live_ones(): void
    {
        // Separate server instances: a PHP built-in-server worker can hold several accepted
        // connections, so a blocked /slow handler could otherwise stall a queued /ok request.
        $slowPort = $this->startHttpServer();
        $livePort = $this->startHttpServer();

        $dead = $this->service(81, '/', ['name' => 'dead', 'address' => '192.0.2.1']);
        $slow = $this->service($slowPort, '/slow', ['name' => 'slow']);
        $live = $this->service($livePort, '/ok', ['name' => 'live']);

        $start = microtime(true);
        // 2 s leaves the freshly started PHP test server room under CI load; /slow sleeps 8 s regardless.
        $results = $this->checker()->checkServices([$dead, $slow, $live], 2.0);
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(4.5, $elapsed, 'two timed-out backends must cost ~1 timeout together, not two');
        $this->assertSame(Verdict::DOWN, $results[$dead->id]['state']);
        $this->assertSame(Verdict::DOWN, $results[$slow->id]['state']);
        $this->assertSame(Verdict::UP, $results[$live->id]['state']);
    }

    public function test_tcp_probe_runs_targets_concurrently(): void
    {
        $targets = [];
        foreach (range(1, 6) as $i) {
            $targets["t{$i}"] = ['192.0.2.1', 1000 + $i, "tcp :{$i}"];
        }

        $start = microtime(true);
        $results = TcpProbe::probe($targets, 1.0);

        $this->assertLessThan(2.5, microtime(true) - $start);
        $this->assertCount(6, $results);
        foreach ($results as $result) {
            $this->assertFalse($result->ok);
        }
    }

    // ------------------------------------------------------- persistence & command

    public function test_a_round_stores_results_and_the_check_command_prints_them(): void
    {
        $port = $this->startHttpServer();
        $server = $this->server(['name' => 'stored', 'ssh_port' => $this->openPort()]);
        $service = $this->service($port, '/ok', ['name' => 'stored-svc']);

        $this->artisan('labman:check')->assertSuccessful();

        $this->assertSame('up', $server->fresh()->state);
        $this->assertNotNull($server->fresh()->checked_at);
        $this->assertSame('up', $service->fresh()->state);
        $this->assertSame('backend', $service->fresh()->checks[0]['label']);
        $this->assertSame('HTTP 200', $service->fresh()->checks[0]['detail']);
    }

    public function test_monitor_runs_a_single_round_with_once(): void
    {
        $server = $this->server(['ssh_port' => $this->openPort()]);

        $this->artisan('labman:monitor', ['--once' => true])->assertSuccessful();

        $this->assertSame('up', $server->fresh()->state);
    }
}
