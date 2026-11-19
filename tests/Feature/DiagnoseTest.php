<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Monitoring\Diagnoser;
use App\Monitoring\HealthHints;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesLocalServers;
use Tests\TestCase;

/** The "why is this red" tool, against real local endpoints, and the catalog of app-specific health paths. */
class DiagnoseTest extends TestCase
{
    use RefreshDatabase, UsesLocalServers;

    private function service(int $port, string $path, array $attributes = []): Service
    {
        return Service::create($attributes + [
            'name' => 'svc'.uniqid(), 'address' => '127.0.0.1', 'port' => $port, 'scheme' => 'http', 'path' => $path, 'public_check' => false,
        ]);
    }

    public function test_it_finds_a_path_that_works_and_recommends_it(): void
    {
        $port = $this->startHttpServer();

        $result = (new Diagnoser)->run($this->service($port, '/error'), 3);

        $this->assertSame('tune', $result['kind']);
        $this->assertSame('/health', $result['changes']['health_path']);
        $this->assertTrue($result['port_result']['ok']);
        $this->assertNotEmpty($result['attempts']);
    }

    public function test_a_healthy_service_is_left_alone(): void
    {
        $port = $this->startHttpServer();

        $result = (new Diagnoser)->run($this->service($port, '/ok'), 3);

        $this->assertSame('ok', $result['kind']);
        $this->assertSame([], $result['changes']);
    }

    public function test_a_refused_port_is_explained(): void
    {
        $result = (new Diagnoser)->run($this->service($this->closedPort(), '/'), 2);

        $this->assertSame('refused', $result['kind']);
        $this->assertStringContainsString('nothing is listening', strtolower($result['message']));
        $this->assertSame([], $result['changes']);
    }

    public function test_a_server_that_only_answers_for_its_domain_is_noted(): void
    {
        $port = $this->startHttpServer();

        $result = (new Diagnoser)->run($this->service($port, '/vhost', ['domain' => 'vhost.example.com', 'expect' => '200']), 3);

        $this->assertContains($result['kind'], ['ok', 'tune']);
        $this->assertTrue(collect($result['attempts'])->contains(fn ($a) => $a['host'] === 'vhost.example.com' && $a['status'] === 200));
    }

    public function test_a_service_without_an_address_cannot_be_probed(): void
    {
        $result = (new Diagnoser)->run(Service::create(['name' => 'orphan', 'scheme' => 'http', 'path' => '/']), 1);

        $this->assertArrayHasKey('error', $result);
    }

    public function test_scores_rank_answers(): void
    {
        $this->assertGreaterThan(Diagnoser::score(404), Diagnoser::score(200));
        $this->assertGreaterThan(Diagnoser::score(500), Diagnoser::score(401));
        $this->assertSame(0, Diagnoser::score(null));
    }

    // ------------------------------------------------------------------ pages

    public function test_the_page_and_apply_are_admin_only(): void
    {
        $this->admin();
        $service = $this->service($this->closedPort(), '/');

        $this->get(route('services.diagnose', $service))->assertRedirect();
        $this->actingAs($this->user())->get(route('services.diagnose', $service))->assertForbidden();
        $this->actingAs($this->user())->post(route('services.diagnose.apply', $service), ['changes' => ['health_path' => '/health']])->assertForbidden();
    }

    public function test_the_page_shows_the_diagnosis_and_apply_saves_only_health_settings(): void
    {
        $port = $this->startHttpServer();
        $service = $this->service($port, '/error', ['name' => 'Keep']);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('services.diagnose', $service))->assertOk()->assertSee('/health')->assertSee('Apply these settings');

        $this->actingAs($admin)->post(route('services.diagnose.apply', $service), ['changes' => ['health_path' => '/health', 'name' => 'Hacked']])
            ->assertRedirect(route('services.edit', $service));

        $fresh = $service->fresh();
        $this->assertSame('/health', $fresh->health_path);
        $this->assertSame('Keep', $fresh->name);

        // The same validation as the form applies.
        $this->actingAs($admin)->post(route('services.diagnose.apply', $service), ['changes' => ['health_path' => 'no-slash']])->assertSessionHasErrors('health_path');
    }

    // ------------------------------------------------------------------ hints

    public function test_known_apps_have_a_health_path(): void
    {
        $this->assertSame('/health', HealthHints::find('Jellyfin')['health_path']);
        $this->assertSame('/identity', HealthHints::find('Plex Media Server')['health_path']);
        $this->assertSame('/ping', HealthHints::find('Sonarr')['health_path']);
        $this->assertSame('/api/health', HealthHints::find('Grafana (lab)')['health_path']);
        $this->assertSame('https', HealthHints::find('Proxmox VE')['scheme']);
        $this->assertSame('Home Assistant', HealthHints::find('Home Assistant')['app']);
        $this->assertSame('/ping', HealthHints::find('TV', 'sonarr.example.com')['health_path']);
        $this->assertNull(HealthHints::find('Elegoo Printer'));
    }
}
