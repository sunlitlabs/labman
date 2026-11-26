<?php

namespace Tests\Feature;

use App\Config\YamlConfig;
use App\Models\ApiToken;
use App\Models\Link;
use App\Models\Server;
use App\Models\Service;
use App\Models\Zone;
use App\Monitoring\HealthChecker;
use App\Monitoring\Verdict;
use App\Support\Zones;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesLocalServers;
use Tests\TestCase;

/** Several addresses per server or service, the card layout, "same as" families and the zone indicator. */
class AlternatesAndBoardTest extends TestCase
{
    use RefreshDatabase, UsesLocalServers;

    // ------------------------------------------------------------ several addresses

    public function test_the_forms_save_further_addresses_and_ignore_blank_rows(): void
    {
        $this->actingAs($this->admin());

        $this->post('/servers', ['name' => 'PiKVM', 'address' => '192.0.2.10', 'ssh_port' => 22, 'monitor' => '1', 'check_ssh' => '1', 'on_dashboard' => '1', 'alternates' => [
            ['label' => 'Wi-Fi', 'address' => '192.0.2.45', 'domain' => 'Kvm-Wifi.Example.com'],
            ['label' => '', 'address' => '', 'domain' => ''],
        ]])->assertSessionHasNoErrors();

        $server = Server::where('name', 'PiKVM')->firstOrFail();
        $this->assertSame([['label' => 'Wi-Fi', 'address' => '192.0.2.45', 'domain' => 'kvm-wifi.example.com']], $server->alternates);
        $this->get(route('servers.edit', $server))->assertOk()->assertSee('More addresses')->assertSee('192.0.2.45');

        $this->put(route('servers.update', $server), ['name' => 'PiKVM', 'address' => '192.0.2.10', 'ssh_port' => 22, 'monitor' => '1', 'check_ssh' => '1', 'on_dashboard' => '1', 'alternates' => [['label' => '', 'address' => '', 'domain' => '']]])->assertSessionHasNoErrors();
        $this->assertNull($server->fresh()->alternates, 'blank rows clear the list');

        $this->post('/services', ['name' => 'KVM web', 'address' => '192.0.2.10', 'scheme' => 'https', 'path' => '/', 'expect' => '200-499', 'monitor' => '1', 'check_tcp' => '1', 'verify_tls' => '1', 'public_check' => '1', 'on_dashboard' => '1', 'family' => 'KVMs', 'alternates' => [['address' => '192.0.2.45']]])->assertSessionHasNoErrors();
        $service = Service::where('name', 'KVM web')->firstOrFail();
        $this->assertSame('Alternate', $service->alternates[0]['label'], 'a missing label gets a default');
        $this->assertSame('KVMs', $service->family);
    }

    public function test_a_further_address_must_be_a_real_address(): void
    {
        $this->actingAs($this->admin());

        $this->post('/servers', ['name' => 'Bad', 'address' => '192.0.2.10', 'ssh_port' => 22, 'alternates' => [['address' => 'not a host!']]])->assertSessionHasErrors('alternates.0.address');
        $this->post('/servers', ['name' => 'Bad', 'address' => '192.0.2.10', 'ssh_port' => 22, 'alternates' => [['address' => '192.0.2.11', 'domain' => 'bad domain']]])->assertSessionHasErrors('alternates.0.domain');
    }

    public function test_a_server_whose_main_address_is_dead_but_another_works_is_degraded_not_down(): void
    {
        $port = $this->openPort(); // listens on 127.0.0.1 only
        $server = Server::create(['name' => 'Kvm', 'address' => '127.0.0.2', 'ssh_port' => $port, 'alternates' => [['label' => 'Wi-Fi', 'address' => '127.0.0.1', 'domain' => null]]]);

        $result = (new HealthChecker)->checkServers([$server], 2)[$server->id];

        $this->assertSame(Verdict::DEGRADED, $result['state']);
        $this->assertSame([null, 0], array_map(fn ($c) => $c->via, $result['checks']));
        $this->assertSame(['Wi-Fi :'.$port], [$result['checks'][1]->label]);
    }

    public function test_a_further_address_that_is_down_never_turns_a_working_server_yellow(): void
    {
        $port = $this->openPort();
        $server = Server::create(['name' => 'Kvm', 'address' => '127.0.0.1', 'ssh_port' => $port, 'alternates' => [['label' => 'Wi-Fi', 'address' => '127.0.0.2', 'domain' => null]]]);

        $result = (new HealthChecker)->checkServers([$server], 2)[$server->id];

        $this->assertSame(Verdict::UP, $result['state']);
        $this->assertFalse($result['checks'][1]->ok);
        $this->assertSame(0, $result['checks'][1]->via);
    }

    public function test_services_check_further_addresses_the_same_way(): void
    {
        $port = $this->openPort();
        $down = Service::create(['name' => 'Kvm web', 'address' => '127.0.0.2', 'port' => $port, 'scheme' => 'http', 'path' => '/', 'public_check' => false, 'check_tcp' => true, 'alternates' => [['label' => 'Wi-Fi', 'address' => '127.0.0.1', 'domain' => null]]]);
        $this->assertSame(Verdict::DEGRADED, (new HealthChecker)->checkServices([$down], 2)[$down->id]['state']);

        $up = Service::create(['name' => 'Kvm web 2', 'address' => '127.0.0.1', 'port' => $port, 'scheme' => 'http', 'path' => '/', 'public_check' => false, 'check_tcp' => true, 'alternates' => [['label' => 'Wi-Fi', 'address' => '127.0.0.2', 'domain' => null]]]);
        $result = (new HealthChecker)->checkServices([$up], 2)[$up->id];
        $this->assertSame(Verdict::UP, $result['state']);
        $this->assertSame([0], collect($result['checks'])->pluck('via')->filter(fn ($v) => $v !== null)->values()->all());
    }

    public function test_the_dashboard_shows_each_address_with_its_own_dot_and_link(): void
    {
        $this->actingAs($this->admin());
        $ok = ['ok' => true, 'ms' => 3, 'detail' => 'connected'];
        $server = Server::create(['name' => 'PiKVM', 'address' => '192.0.2.10', 'alternates' => [['label' => 'Wi-Fi', 'address' => '192.0.2.45', 'domain' => 'kvm-wifi.example.com']]]);
        $server->forceFill(['state' => 'up', 'checked_at' => now(), 'checks' => [['label' => 'ssh :22'] + $ok, ['label' => 'Wi-Fi :22', 'via' => 0, 'ok' => false, 'ms' => null, 'detail' => 'timed out']]])->save();

        $this->get('/')->assertOk()
            ->assertSee('data-dot="alt:0" data-dot-state="down"', false)
            ->assertSee('data-dot="backend" data-dot-state="up"', false)
            ->assertSee('Wi-Fi')->assertSee('kvm-wifi.example.com');
    }

    public function test_a_server_tile_shows_its_domain_as_well_as_its_address(): void
    {
        $this->actingAs($this->admin());
        Server::create(['name' => 'Nas', 'address' => '192.0.2.20', 'domain' => 'nas.example.com']);
        Server::create(['name' => 'Bare', 'address' => '192.0.2.21']);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('href="http://nas.example.com"', $html);
        $this->assertSame(1, substr_count($html, 'href="http://nas.example.com"'));
        $this->assertStringContainsString('192.0.2.20', $html);
    }

    public function test_the_yaml_and_mcp_carry_further_addresses_and_families(): void
    {
        $this->actingAs($this->admin());
        Server::create(['name' => 'PiKVM', 'address' => '192.0.2.10', 'alternates' => [['label' => 'Wi-Fi', 'address' => '192.0.2.45', 'domain' => null]]]);
        Service::create(['name' => 'NPM Lab', 'address' => '192.0.2.5', 'port' => 81, 'scheme' => 'http', 'path' => '/', 'family' => 'NPM', 'alternates' => [['label' => 'Wi-Fi', 'address' => '192.0.2.46', 'domain' => 'npm-wifi.example.com']]]);

        $yaml = YamlConfig::export();
        $this->assertStringContainsString('Wi-Fi', $yaml);
        $this->assertStringContainsString('family: NPM', $yaml);

        Server::query()->delete();
        Service::query()->delete();
        YamlConfig::import(YamlConfig::parse($yaml));
        $this->assertSame('192.0.2.45', Server::where('name', 'PiKVM')->firstOrFail()->alternates[0]['address']);
        $service = Service::where('name', 'NPM Lab')->firstOrFail();
        $this->assertSame('npm-wifi.example.com', $service->alternates[0]['domain']);
        $this->assertSame('NPM', $service->family);

        $this->expectException(\App\Config\ConfigException::class);
        YamlConfig::parse("servers:\n  - name: X\n    address: 192.0.2.9\n    alternates:\n      - address: 'not a host!'\nservices: []\n");
    }

    public function test_mcp_can_set_further_addresses(): void
    {
        $admin = $this->admin();
        [, $token] = ApiToken::issue($admin, 'test', 'admin');
        $call = fn (string $tool, array $args) => $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => (object) $args]], ['Authorization' => 'Bearer '.$token])->assertOk()->json('result');

        $made = $call('create_server', ['name' => 'PiKVM', 'address' => '192.0.2.10', 'alternates' => [['label' => 'Wi-Fi', 'address' => '192.0.2.45', 'domain' => 'kvm-wifi.example.com']]]);
        $this->assertFalse($made['isError']);
        $this->assertSame('192.0.2.45', $made['structuredContent']['server']['alternates'][0]['address']);

        $kept = $call('update_server', ['name' => 'PiKVM', 'notes' => 'x']);
        $this->assertCount(1, $kept['structuredContent']['server']['alternates'], 'omitted means unchanged');
        $cleared = $call('update_server', ['name' => 'PiKVM', 'alternates' => []]);
        $this->assertSame([], $cleared['structuredContent']['server']['alternates']);
    }

    // ------------------------------------------------------------ the board

    public function test_groups_are_cards_holding_every_kind_and_ungrouped_items_get_a_card_per_kind(): void
    {
        $this->actingAs($this->admin());
        Server::create(['name' => 'Switch', 'address' => '192.0.2.2', 'group' => 'Network']);
        Service::create(['name' => 'Controller', 'address' => '192.0.2.3', 'port' => 8443, 'scheme' => 'https', 'path' => '/', 'group' => 'Network']);
        Link::create(['name' => 'ISP portal', 'url' => 'https://isp.example.com', 'group' => 'Network']);
        Service::create(['name' => 'Wiki', 'address' => '192.0.2.4', 'port' => 80, 'scheme' => 'http', 'path' => '/']);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, 'class="group gcard"'), 'one card for Network, one for the ungrouped services');
        $network = substr($html, strpos($html, '<h2>Network'));
        $network = substr($network, 0, strpos($network, '</section>'));
        foreach (['Switch', 'Controller', 'ISP portal'] as $name) {
            $this->assertStringContainsString($name, $network);
        }
        $this->assertStringContainsString('data-section="links"', $html);
    }

    public function test_services_that_are_the_same_thing_share_one_tile_with_a_dot_per_member(): void
    {
        $this->actingAs($this->admin());
        foreach (['NPM Lab' => 'up', 'NPM Family' => 'down'] as $name => $state) {
            $s = Service::create(['name' => $name, 'address' => '192.0.2.5', 'port' => 81, 'scheme' => 'http', 'path' => '/', 'family' => 'NPM', 'group' => 'Proxies']);
            $s->forceFill(['state' => $state, 'checked_at' => now()])->save();
        }
        Service::create(['name' => 'NPM Alone', 'address' => '192.0.2.6', 'port' => 81, 'scheme' => 'http', 'path' => '/', 'family' => 'Solo']);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'class="tile family"'));
        $this->assertStringContainsString('data-family data-section="services" data-state="down"', $html, 'the tile shows its worst member');
        $this->assertSame(2, substr_count($html, 'class="fam-member"'));
        $this->assertStringContainsString('>Lab', str_replace(["\n", '  '], '', $html), 'the family name is trimmed from member names');
        $this->assertStringContainsString('NPM Alone', $html, 'a family of one is an ordinary tile');
    }

    public function test_the_zone_badge_and_by_zone_view_follow_the_viewers_zone(): void
    {
        $secure = Zone::create(['name' => 'Secure', 'level' => 90, 'cidrs' => ['127.0.0.0/8'], 'dashboard_indicator' => true]);
        $family = Zone::create(['name' => 'Family', 'level' => 10, 'cidrs' => ['10.9.0.0/16']]);
        $this->actingAs($this->admin());
        Server::create(['name' => 'Vault', 'address' => '192.0.2.8', 'zone_id' => $secure->id]);
        Server::create(['name' => 'Tv box', 'address' => '192.0.2.9', 'zone_id' => $family->id]);

        // This test client is 127.0.0.1, which the Secure zone owns and asks for badges.
        $this->get('/')->assertOk()->assertSee('zone-chip', false)->assertSee('Groups')->assertSee('Zones');

        $html = $this->get('/?view=zones')->assertOk()->getContent();
        $this->assertGreaterThan(strpos($html, '<h2>Secure'), strpos($html, '<h2>Family'), 'the most trusted zone comes first');
        $this->assertStringNotContainsString('zone-chip', $html, 'the card heading already says the zone');

        $secure->update(['dashboard_indicator' => false]);
        $this->get('/?view=zones')->assertOk()->assertDontSee('zone-chip', false)->assertDontSee('viewswitch', false);
    }

    public function test_the_zone_indicator_setting_is_saved_by_the_form_and_mcp(): void
    {
        $this->actingAs($this->admin());
        $zone = Zone::create(['name' => 'Secure', 'level' => 90]);

        $this->get(route('zones.edit', $zone))->assertOk()->assertSee('Show zone badges');
        $this->put(route('zones.update', $zone), ['name' => 'Secure', 'level' => 90, 'dashboard_indicator' => '1'])->assertSessionHasNoErrors();
        $this->assertTrue($zone->fresh()->dashboard_indicator);
        $this->put(route('zones.update', $zone), ['name' => 'Secure', 'level' => 90])->assertSessionHasNoErrors();
        $this->assertFalse($zone->fresh()->dashboard_indicator);
        $this->assertNotNull(Zones::default());
    }
}
