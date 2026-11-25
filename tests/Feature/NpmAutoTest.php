<?php

namespace Tests\Feature;

use App\Domain\SettingsInput;
use App\Models\Notice;
use App\Models\Server;
use App\Models\Service;
use App\Npm\Connection;
use App\Npm\NpmAuto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeNpm;
use Tests\TestCase;

/** LabMan as the source of truth for NPM: changes move over by themselves, certificates included. */
class NpmAutoTest extends TestCase
{
    use RefreshDatabase;

    private FakeNpm $npm;

    protected function setUp(): void
    {
        parent::setUp();

        $this->npm = FakeNpm::install();
        Connection::save(FakeNpm::URL, 'npm@example.com', 'npm-secret', true);
        SettingsInput::update(['local_domains' => 'home.example.com', 'public_domains' => 'example.org']);
        $this->actingAs($this->admin());
    }

    private function linked(array $service = [], array $host = []): array
    {
        $id = $this->npm->addHost($host + ['domain_names' => ['wiki.home.example.com'], 'forward_host' => '192.0.2.5', 'forward_port' => 8080]);
        $svc = Service::create($service + ['name' => 'Wiki', 'domain' => 'wiki.home.example.com', 'address' => '192.0.2.5', 'port' => 8080, 'scheme' => 'http', 'path' => '/', 'npm_host_id' => $id]);
        NpmAuto::flush(); // already in line with NPM, so nothing is written
        $this->npm->requests = [];

        return [$svc, $id];
    }

    private function host(int $id): array
    {
        return $this->npm->hosts[$id];
    }

    public function test_changing_a_service_updates_its_proxy_host(): void
    {
        [$svc, $id] = $this->linked();
        $this->npm->requests = [];

        $this->put(route('services.update', $svc), [
            'name' => 'Wiki', 'address' => '192.0.2.9', 'scheme' => 'http', 'port' => 8080, 'path' => '/', 'expect' => '200-499', 'local_domains' => ['wiki.home.example.com'],
        ])->assertSessionHasNoErrors();

        $this->assertSame('192.0.2.9', $this->host($id)['forward_host']);
        $this->assertTrue(NpmAuto::last()['ok']);
    }

    public function test_nothing_is_sent_when_automatic_updates_are_off(): void
    {
        [$svc] = $this->linked();
        $this->post('/npm/auto', ['auto' => 0]);
        $this->npm->requests = [];

        $svc->update(['address' => '192.0.2.9']);
        NpmAuto::flush();

        $this->assertSame([], $this->npm->writes());
    }

    public function test_a_change_to_something_npm_does_not_hold_sends_nothing(): void
    {
        [$svc] = $this->linked();
        $this->npm->requests = [];

        $svc->update(['notes' => 'just a note']);
        NpmAuto::flush();

        $this->assertSame([], $this->npm->requests, 'not even a look at NPM');
    }

    public function test_a_new_service_with_a_domain_gets_a_proxy_host_with_the_domains_certificate(): void
    {
        $this->npm->certificates = [['id' => 3, 'nice_name' => 'Local', 'domain_names' => ['*.home.example.com']]];
        SettingsInput::update(['npm_cert_local' => '3']);

        $svc = Service::create(['name' => 'Blog', 'domain' => 'blog.home.example.com', 'address' => '192.0.2.6', 'scheme' => 'http', 'path' => '/']);
        NpmAuto::flush(); // a request would do this when it ends

        $created = collect($this->npm->hosts)->first(fn ($h) => $h['domain_names'] === ['blog.home.example.com']);
        $this->assertNotNull($created);
        $this->assertSame(3, $created['certificate_id']);
        $this->assertSame($created['id'], $svc->fresh()->npm_host_id);
    }

    public function test_changing_a_certificate_moves_it_to_every_host_of_that_kind(): void
    {
        [, $localId] = $this->linked(host: ['certificate_id' => 3]);
        $publicHost = $this->npm->addHost(['domain_names' => ['wiki.example.org'], 'forward_host' => '192.0.2.5', 'forward_port' => 8080, 'certificate_id' => 7]);
        Service::where('name', 'Wiki')->update(['public_domain' => 'wiki.example.org', 'npm_public_host_id' => $publicHost]);
        SettingsInput::update(['npm_cert_local' => '3', 'npm_cert_public' => '7']);
        $this->npm->requests = [];

        $this->put('/npm/domains', ['local_domains' => 'home.example.com', 'public_domains' => 'example.org', 'npm_cert_local' => '4', 'npm_cert_public' => '7'])
            ->assertRedirect(route('npm.proxy', \App\Support\Zones::default()));

        $this->assertSame(4, $this->host($localId)['certificate_id'], 'the local certificate follows the setting');
        $this->assertSame(7, $this->host($publicHost)['certificate_id'], 'public hosts are left alone');
        $this->assertCount(1, $this->npm->writes());

        $this->get('/npm')->assertSee('Last automatic update')->assertSee('wiki.home.example.com');
    }

    public function test_a_certificate_change_waits_for_a_push_when_updates_are_off(): void
    {
        [, $id] = $this->linked(host: ['certificate_id' => 3]);
        SettingsInput::update(['npm_cert_local' => '3']);
        $this->post('/npm/auto', ['auto' => 0]);
        $this->npm->requests = [];

        $this->put('/npm/domains', ['local_domains' => 'home.example.com', 'npm_cert_local' => '4'])->assertSessionHasNoErrors();

        $this->assertSame(3, $this->host($id)['certificate_id']);
        $this->assertSame([], $this->npm->writes());

        // The push preview now lists the difference.
        $this->get('/npm/push')->assertSee('certificate');
    }

    public function test_a_servers_new_address_reaches_the_proxy_hosts_of_its_services(): void
    {
        $server = Server::create(['name' => 'box', 'address' => '192.0.2.5']);
        $id = $this->npm->addHost(['domain_names' => ['app.home.example.com'], 'forward_host' => '192.0.2.5', 'forward_port' => 3000]);
        Service::create(['name' => 'App', 'domain' => 'app.home.example.com', 'server_id' => $server->id, 'port' => 3000, 'scheme' => 'http', 'path' => '/', 'npm_host_id' => $id]);
        $this->npm->requests = [];

        $server->update(['address' => '192.0.2.8']);
        NpmAuto::flush();

        $this->assertSame('192.0.2.8', $this->host($id)['forward_host']);
    }

    public function test_a_failure_is_recorded_and_raised_and_the_change_is_still_saved(): void
    {
        [$svc] = $this->linked();
        $this->npm->password = 'changed';

        $svc->update(['address' => '192.0.2.9']);
        NpmAuto::flush();

        $this->assertSame('192.0.2.9', $svc->fresh()->address);
        $this->assertFalse(NpmAuto::last()['ok']);
        $this->assertNotNull(Notice::where('key', 'npm-sync')->first());
    }

    public function test_nothing_is_ever_deleted_in_npm(): void
    {
        [$svc, $id] = $this->linked();
        $this->npm->requests = [];

        $svc->delete();
        NpmAuto::flush();

        $this->assertArrayHasKey($id, $this->npm->hosts);
        $this->assertNotContains('DELETE', $this->npm->methods());
    }

    public function test_the_domains_live_on_the_npm_page_not_in_general_settings(): void
    {
        $this->get('/settings')->assertOk()->assertDontSee('Add local domain');
        $this->get(route('npm.proxy', \App\Support\Zones::default()))->assertOk()->assertSee('Add local domain');
        $this->get('/npm')->assertOk()->assertSee('Keep NPM up to date');
    }
}
