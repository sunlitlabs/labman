<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Service;
use App\Models\Zone;
use App\Npm\Connection;
use App\Npm\NpmAuto;
use App\Npm\NpmClient;
use App\Npm\NpmSync;
use App\Npm\ProxyStatus;
use App\Support\Zones;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeNpm;
use Tests\TestCase;

/** Step 2 of zones: one Nginx Proxy Manager per zone, previews and applies scoped to it, and the grouped preview. */
class ZoneProxiesTest extends TestCase
{
    use RefreshDatabase;

    private const URL2 = 'http://npm-external.test:81';

    private FakeNpm $inner;

    private FakeNpm $outer;

    private Zone $internal;

    private Zone $external;

    private Server $web;

    private Server $edge;

    private Service $wiki;

    private Service $blog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->admin(['email' => 'root@example.com']));
        $this->inner = FakeNpm::install();
        $this->outer = FakeNpm::install(self::URL2);
        NpmAuto::setEnabled(false);

        $this->internal = Zones::default();
        $this->internal->update(['name' => 'Internal', 'level' => 60]);
        \App\Support\Settings::set(['local_domains' => 'home.example.com']);
        $this->external = Zone::create(['name' => 'External', 'level' => 10, 'domains' => ['public' => ['example.dev']]]);

        $this->web = Server::create(['name' => 'web1', 'address' => '192.0.2.11']);
        $this->edge = Server::create(['name' => 'edge', 'address' => '198.51.100.7', 'zone_id' => $this->external->id]);
        $this->wiki = Service::create(['name' => 'Wiki', 'server_id' => $this->web->id, 'domain' => 'wiki.home.example.com', 'scheme' => 'http', 'path' => '/']);
        $this->blog = Service::create(['name' => 'Blog', 'server_id' => $this->edge->id, 'public_domain' => 'blog.example.dev', 'scheme' => 'http', 'path' => '/']);
    }

    private function connectBoth(): void
    {
        Connection::save(FakeNpm::URL, 'npm@example.com', 'npm-secret', true);
        Connection::save(self::URL2, 'npm@example.com', 'npm-secret', true, $this->external);
    }

    private function sync(Zone $zone): NpmSync
    {
        return new NpmSync(NpmClient::fromStored($zone), $zone);
    }

    /** @param list<array<string, mixed>> $plan @return array<string, string> */
    private function pick(array $plan): array
    {
        $out = [];
        foreach ($plan as $item) {
            if (in_array($item['action'], ['create', 'update'], true)) {
                $out[$item['key']] = $item['fingerprint'];
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------------ connections

    public function test_each_zone_keeps_its_own_login_and_the_default_zone_keeps_the_original_keys(): void
    {
        $this->connectBoth();

        $this->assertSame(FakeNpm::URL, Connection::get()['url']);
        $this->assertSame(self::URL2, Connection::get($this->external)['url']);
        $this->assertSame([$this->internal->id, $this->external->id], array_map(fn ($z) => $z->id, Connection::connectedZones()));
        $this->assertArrayNotHasKey('password', Connection::summary($this->external));
        $this->assertNotNull(\App\Models\Setting::where('key', 'npm.url')->first(), 'the default zone still uses the original keys');

        Connection::forget($this->external);

        $this->assertNull(Connection::get($this->external));
        $this->assertNotNull(Connection::get(), "forgetting one zone's login leaves the others");
    }

    public function test_a_zone_without_a_login_says_which_zone_is_missing_one(): void
    {
        $this->expectExceptionMessage('not connected for the External zone');

        NpmClient::fromStored($this->external);
    }

    public function test_connecting_a_zones_proxy_from_the_page_stores_it_for_that_zone_only(): void
    {
        $this->post(route('npm.connect', ['zone' => $this->external->id]), ['url' => self::URL2, 'email' => 'npm@example.com', 'password' => 'npm-secret', 'verify_tls' => 1])
            ->assertRedirect(route('npm.wizard', ['zone' => $this->external->id]));

        $this->assertSame(self::URL2, Connection::get($this->external)['url']);
        $this->assertNull(Connection::get(), 'the default zone is untouched');
    }

    // ---------------------------------------------------------------------------- scoping

    public function test_a_push_plan_covers_only_the_services_of_its_zone(): void
    {
        $this->connectBoth();

        $inner = array_column($this->sync($this->internal)->pushPlan(), 'key');
        $outer = array_column($this->sync($this->external)->pushPlan(), 'key');

        $this->assertSame(['svc:'.$this->wiki->id], $inner);
        $this->assertSame(['svc:'.$this->blog->id.':public'], $outer);
    }

    public function test_applying_a_push_writes_only_to_that_zones_proxy(): void
    {
        $this->connectBoth();
        $sync = $this->sync($this->external);

        $result = $sync->applyPush($this->pick($sync->pushPlan()));

        $this->assertSame(1, $result['applied']);
        $this->assertCount(1, $this->outer->hosts);
        $this->assertSame([], $this->inner->hosts);
        $this->assertSame([], $this->inner->writes());
        $this->assertNotNull($this->blog->fresh()->npm_public_host_id);
        $this->assertSame(['blog.example.dev'], array_values($this->outer->hosts)[0]['domain_names']);
    }

    public function test_an_import_only_sees_its_zones_servers_and_files_new_services_there(): void
    {
        $this->connectBoth();
        $this->outer->addHost(['domain_names' => ['shop.example.dev'], 'forward_host' => '198.51.100.7', 'forward_port' => 8080]);
        $this->outer->addHost(['domain_names' => ['wiki2.example.dev'], 'forward_host' => '203.0.113.9', 'forward_port' => 80]);
        $this->outer->addHost(['domain_names' => ['inner.example.dev'], 'forward_host' => '192.0.2.11', 'forward_port' => 80]);

        $sync = $this->sync($this->external);
        $sync->applyImport($this->pick($sync->importPlan()));

        $shop = Service::where('public_domain', 'shop.example.dev')->first();
        $this->assertSame($this->edge->id, $shop->server_id, 'a forward address in the zone is matched to its server');
        $this->assertSame($this->external->id, Zones::of($shop)->id);

        $loose = Service::where('public_domain', 'wiki2.example.dev')->first();
        $this->assertNull($loose->server_id);
        $this->assertSame($this->external->id, $loose->zone_id, 'with no server the service names its zone');
        $this->assertSame($this->external->id, Zones::of($loose)->id);

        $other = Service::where('public_domain', 'inner.example.dev')->first();
        $this->assertNull($other->server_id, 'web1 is in another zone, so it is not offered as this proxy\'s backend');
        $this->assertSame($this->external->id, Zones::of($other)->id);
    }

    public function test_services_follow_their_zone_in_the_zone_lists(): void
    {
        $override = Service::create(['name' => 'Tool', 'server_id' => $this->web->id, 'zone_id' => $this->external->id, 'domain' => 'tool.example.dev', 'scheme' => 'http', 'path' => '/']);

        $this->assertContains($override->id, Zones::servicesIn($this->external)->pluck('id')->all());
        $this->assertNotContains($override->id, Zones::servicesIn($this->internal)->pluck('id')->all());
        $this->assertContains($this->wiki->id, Zones::servicesIn($this->internal)->pluck('id')->all());
    }

    // ------------------------------------------------------------------- grouped preview

    public function test_the_grouped_preview_has_a_section_per_proxy_with_its_own_switch(): void
    {
        $this->connectBoth();

        $page = $this->get(route('npm.all', ['mode' => 'push']))->assertOk()
            ->assertSee('Push to every proxy')->assertSee('Internal')->assertSee('External')
            ->assertSee('Wiki → wiki.home.example.com')->assertSee('Blog → blog.example.dev')
            ->getContent();

        $this->assertSame(2, substr_count($page, 'name="zones[]"'));
        $this->assertStringContainsString('name="apply['.$this->external->id.'][]"', $page);
        $this->assertStringContainsString('name="apply['.$this->internal->id.'][]"', $page);
    }

    public function test_an_unreachable_proxy_degrades_only_its_own_section(): void
    {
        $this->connectBoth();
        $this->outer->password = 'changed-elsewhere';

        $page = $this->get(route('npm.all', ['mode' => 'push']))->assertOk()->getContent();

        $this->assertStringContainsString('could not be checked', $page);
        $this->assertStringContainsString('Wiki → wiki.home.example.com', $page, 'the healthy proxy is still previewed');
        $this->assertStringNotContainsString('Blog → blog.example.dev', $page);
    }

    public function test_applying_all_writes_only_to_the_switched_on_proxies_and_reports_a_failure_without_stopping(): void
    {
        $this->connectBoth();
        $inner = $this->sync($this->internal)->pushPlan();
        $outer = $this->sync($this->external)->pushPlan();
        $post = fn (array $zones) => $this->post(route('npm.all.apply'), [
            'mode' => 'push', 'zones' => $zones,
            'apply' => [$this->internal->id => array_keys($this->pick($inner)), $this->external->id => array_keys($this->pick($outer))],
            'fp' => [$this->internal->id => $this->pick($inner), $this->external->id => $this->pick($outer)],
        ]);

        // Only the internal proxy is switched on.
        $post([$this->internal->id])->assertRedirect(route('npm.index'));
        $this->assertCount(1, $this->inner->hosts);
        $this->assertSame([], $this->outer->hosts);

        // Both on, but the external proxy no longer accepts the login: the internal one is not blocked, the failure is shown.
        $this->outer->password = 'changed-elsewhere';
        $this->inner->hosts = [];
        $this->post(route('npm.all.apply'), [
            'mode' => 'push', 'zones' => [$this->internal->id, $this->external->id],
            'apply' => [$this->internal->id => array_keys($this->pick($inner)), $this->external->id => array_keys($this->pick($outer))],
            'fp' => [$this->internal->id => $this->pick($inner), $this->external->id => $this->pick($outer)],
        ])->assertRedirect(route('npm.index'))->assertSessionHas('status', fn ($s) => str_contains($s, '1 change(s) applied') && str_contains($s, '1 failed'));
        $this->assertCount(1, $this->inner->hosts);
    }

    public function test_a_stale_preview_in_one_section_is_refused_for_that_proxy_only(): void
    {
        $this->connectBoth();
        $inner = $this->pick($this->sync($this->internal)->pushPlan());
        $outer = $this->pick($this->sync($this->external)->pushPlan());
        $this->blog->update(['port' => 9000]); // changes the external plan after it was shown

        $this->post(route('npm.all.apply'), [
            'mode' => 'push', 'zones' => [$this->internal->id, $this->external->id],
            'apply' => [$this->internal->id => array_keys($inner), $this->external->id => array_keys($outer)],
            'fp' => [$this->internal->id => $inner, $this->external->id => $outer],
        ])->assertRedirect();

        $this->assertCount(1, $this->inner->hosts, 'the internal proxy was applied');
        $this->assertSame([], $this->outer->hosts, 'nothing was written to the changed one');
    }

    // ------------------------------------------------------------------------ pages and status

    public function test_the_proxy_status_reports_connected_unreachable_and_missing(): void
    {
        $this->connectBoth();
        $this->outer->addHost(['domain_names' => ['shop.example.dev'], 'forward_host' => '203.0.113.9', 'forward_port' => 80]);

        $ok = ProxyStatus::of($this->external);
        $this->assertSame('ok', $ok['state']);
        $this->assertSame('2.17.0', $ok['version']);
        $this->assertSame(1, $ok['hosts']);
        $this->assertSame(1, $ok['unknown_to_labman']);
        $this->assertSame(1, $ok['to_push']);

        $this->outer->password = 'changed-elsewhere';
        $this->assertSame('error', ProxyStatus::of($this->external)['state']);

        Connection::forget($this->external);
        $this->assertSame('none', ProxyStatus::of($this->external)['state']);
    }

    public function test_the_npm_page_shows_a_row_per_proxy_and_the_chosen_zones_connection(): void
    {
        $this->connectBoth();

        $this->get(route('npm.index', ['zone' => $this->external->id]))->assertOk()
            ->assertSee('Proxies')->assertSee('Internal')->assertSee('External')->assertSee('connected')
            ->assertSee(self::URL2)->assertSee('Preview push to all proxies')
            ->assertSee(route('npm.push', ['zone' => $this->external->id]), false)
            ->assertSee('The External zone')->assertDontSee('name="local_domains[]"', false);

        // The default zone's page keeps the domain and certificate settings.
        $this->get(route('npm.index'))->assertOk()->assertSee('Domains and certificates')->assertSee('Save domains');
    }

    public function test_a_single_zone_lab_sees_the_same_addresses_as_before(): void
    {
        $this->external->delete();
        Server::where('zone_id', $this->external->id)->update(['zone_id' => $this->internal->id]);
        $this->connectBoth();

        $this->assertFalse(Zones::multiple());
        $this->get(route('npm.index'))->assertOk()->assertDontSee('Preview push to all proxies')
            ->assertSee('href="'.route('npm.push').'"', false);
    }

    // ------------------------------------------------------------------------------ automatic

    public function test_an_automatic_update_goes_to_the_proxy_of_the_services_zone(): void
    {
        $this->connectBoth();
        NpmAuto::setEnabled(true);

        $this->blog->update(['port' => 8123]);
        NpmAuto::flush();

        $this->assertCount(1, $this->outer->hosts, 'the host was created in the external proxy');
        $this->assertSame(8123, array_values($this->outer->hosts)[0]['forward_port']);
        $this->assertSame([], $this->inner->writes());
        $this->assertSame('External: Blog → blog.example.dev', NpmAuto::last()['results'][0]['title']);
    }

    public function test_moving_a_server_to_another_zone_sends_its_services_to_that_zones_proxy(): void
    {
        $this->connectBoth();
        NpmAuto::setEnabled(true);

        $this->web->update(['zone_id' => $this->external->id]);
        NpmAuto::flush();

        $this->assertCount(1, $this->outer->hosts);
        $this->assertSame(['wiki.home.example.com'], array_values($this->outer->hosts)[0]['domain_names']);
        $this->assertSame([], $this->inner->hosts);
    }
}
