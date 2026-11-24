<?php

namespace Tests\Feature;

use App\Config\ConfigException;
use App\Config\YamlConfig;
use App\Models\Server;
use App\Models\Service;
use App\Models\Zone;
use App\Support\Settings;
use App\Support\Zones;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Step 1 of zones: the model, the migration from a single-zone lab, ownership, and YAML. */
class ZonesTest extends TestCase
{
    use RefreshDatabase;

    private function zone(string $name, int $level, array $extra = []): Zone
    {
        return Zone::create(['name' => $name, 'level' => $level] + $extra);
    }

    // ------------------------------------------------------------ migration and defaults

    public function test_a_fresh_install_has_one_default_zone(): void
    {
        $this->assertSame(1, Zone::count());
        $this->assertTrue(Zones::default()->is_default);
        $this->assertFalse(Zones::multiple());
    }

    public function test_the_migration_puts_existing_servers_in_one_default_zone_and_changes_nothing_else(): void
    {
        $server = Server::create(['name' => 'nas', 'address' => '192.0.2.10', 'domain' => 'nas.example.com']);
        $service = Service::create(['name' => 'Wiki', 'server_id' => $server->id, 'scheme' => 'http', 'path' => '/']);
        $path = 'database/migrations/2025_01_10_000001_create_zones.php';

        // Go back to the schema before zones, add a server the old way, then migrate forward.
        Artisan::call('migrate:rollback', ['--path' => $path, '--force' => true]);
        $this->assertFalse(\Schema::hasTable('zones'));
        DB::table('servers')->insert(['name' => 'old-box', 'address' => '192.0.2.11', 'ssh_port' => 22, 'created_at' => now(), 'updated_at' => now()]);
        Artisan::call('migrate', ['--path' => $path, '--force' => true]);

        $this->assertSame(1, Zone::count());
        $default = Zones::default();
        $this->assertSame('Default', $default->name);
        $this->assertSame([$default->id], Server::query()->pluck('zone_id')->unique()->values()->all());
        $this->assertSame(2, Server::count());
        $this->assertNull($service->fresh()->zone_id, 'a service follows its server unless it overrides');
        $this->assertSame($default->id, Zones::of($service->fresh())->id);
        $this->assertSame('nas.example.com', Server::where('name', 'nas')->first()->domain);
    }

    public function test_new_servers_land_in_the_default_zone_unless_placed(): void
    {
        $lan = $this->zone('LAN', 40);

        $a = Server::create(['name' => 'a', 'address' => '192.0.2.1']);
        $b = Server::create(['name' => 'b', 'address' => '192.0.2.2', 'zone_id' => $lan->id]);

        $this->assertSame(Zones::defaultId(), $a->zone_id);
        $this->assertSame($lan->id, $b->zone_id);
    }

    public function test_a_service_has_its_servers_zone_unless_it_overrides_it(): void
    {
        $lan = $this->zone('LAN', 40);
        $dmz = $this->zone('External', 10);
        $server = Server::create(['name' => 'a', 'address' => '192.0.2.1', 'zone_id' => $lan->id]);
        $inherits = Service::create(['name' => 'One', 'server_id' => $server->id, 'scheme' => 'http', 'path' => '/']);
        $overrides = Service::create(['name' => 'Two', 'server_id' => $server->id, 'zone_id' => $dmz->id, 'scheme' => 'http', 'path' => '/']);

        $this->assertSame($lan->id, Zones::of($inherits)->id);
        $this->assertSame($dmz->id, Zones::of($overrides)->id);
    }

    // ---------------------------------------------------------------- ownership and CIDR

    public function test_the_longest_domain_suffix_owns_a_host_name(): void
    {
        $parent = $this->zone('Parent', 40, ['domains' => ['public' => ['example.dev']]]);
        $child = $this->zone('Child', 30, ['domains' => ['public' => ['a.example.dev']]]);

        $this->assertSame($child->id, Zones::ownerOf('wiki.a.example.dev')->id);
        $this->assertSame($child->id, Zones::ownerOf('a.example.dev')->id);
        $this->assertSame($parent->id, Zones::ownerOf('wiki.example.dev')->id);
        $this->assertSame($parent->id, Zones::ownerOf('WIKI.Example.Dev.')->id);
        $this->assertNull(Zones::ownerOf('wiki.badexample.dev'), 'a suffix must end at a dot');
        $this->assertNull(Zones::ownerOf('wiki.example.me'));
    }

    public function test_two_zones_claiming_the_same_name_are_reported_not_guessed(): void
    {
        $this->zone('One', 40, ['domains' => ['local' => ['lab.example.me']]]);
        $this->zone('Two', 30, ['domains' => ['public' => ['lab.example.me']]]);

        $owners = Zones::owners('wiki.lab.example.me');

        $this->assertCount(2, $owners);
        $this->assertNull(Zones::ownerOf('wiki.lab.example.me'));
    }

    public function test_the_default_zone_reads_its_domains_from_the_settings(): void
    {
        Settings::set(['local_domains' => 'home.example.com', 'public_domains' => 'example.org']);

        $this->assertSame(['local' => ['home.example.com'], 'public' => ['example.org']], Zones::default()->domains());
        $this->assertSame(Zones::default()->id, Zones::ownerOf('wiki.home.example.com')->id);
    }

    public function test_a_server_address_suggests_the_narrowest_matching_zone(): void
    {
        $wide = $this->zone('Wide', 40, ['cidrs' => ['192.0.2.0/24']]);
        $narrow = $this->zone('Narrow', 60, ['cidrs' => ['192.0.2.128/25']]);
        $v6 = $this->zone('Six', 20, ['cidrs' => ['2001:db8::/32']]);

        $this->assertSame($wide->id, Zones::suggestFor('192.0.2.10')->id);
        $this->assertSame($narrow->id, Zones::suggestFor('192.0.2.200')->id);
        $this->assertSame($v6->id, Zones::suggestFor('2001:db8::5')->id);
        $this->assertNull(Zones::suggestFor('198.51.100.1'));
        $this->assertNull(Zones::cidrBits('192.0.2.0/24', '2001:db8::1'), 'address families never match');
        $this->assertFalse(Zones::validCidr('192.0.2.0/33'));
        $this->assertFalse(Zones::validCidr('not-a-block'));
    }

    // --------------------------------------------------------------------- the admin screens

    public function test_an_admin_manages_zones_and_the_forms_offer_them_once_there_are_two(): void
    {
        $this->actingAs($this->admin());

        $this->get(route('zones.index'))->assertOk()->assertSee('One zone so far');
        $this->get(route('servers.create'))->assertOk()->assertDontSee('name="zone_id"', false);

        $this->post(route('zones.store'), ['name' => 'External', 'level' => 10, 'cidrs' => '198.51.100.0/24', 'public_domains' => 'example.dev', 'proxy_address' => '192.0.2.5'])
            ->assertRedirect(route('zones.index'))->assertSessionHasNoErrors();
        $external = Zone::where('name', 'External')->firstOrFail();
        $this->assertSame(['local' => [], 'public' => ['example.dev']], $external->domains());

        $this->get(route('servers.create'))->assertOk()->assertSee('name="zone_id"', false)->assertSee('External');
        $this->get(route('services.create'))->assertOk()->assertSee('same as the server');

        $this->post(route('servers.store'), ['name' => 'edge', 'address' => '198.51.100.7', 'ssh_port' => 22, 'zone_id' => $external->id])->assertSessionHasNoErrors();
        $this->assertSame($external->id, Server::where('name', 'edge')->first()->zone_id);

        $this->put(route('servers.update', Server::where('name', 'edge')->first()), ['name' => 'edge', 'address' => '198.51.100.7', 'ssh_port' => 22, 'zone_id' => ''])->assertSessionHasNoErrors();
        $this->assertSame($external->id, Server::where('name', 'edge')->first()->zone_id, 'a blank zone keeps the current one');
    }

    public function test_zone_input_is_validated(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('zones.store'), ['name' => 'Bad', 'level' => 500])->assertSessionHasErrors('level');
        $this->post(route('zones.store'), ['name' => 'Bad', 'level' => 10, 'cidrs' => '192.0.2.0/40'])->assertSessionHasErrors('cidrs');
        $this->post(route('zones.store'), ['name' => 'Bad', 'level' => 10, 'public_domains' => 'bad_domain!.com'])->assertSessionHasErrors('public_domains');
        $this->post(route('zones.store'), ['name' => 'Bad', 'level' => 10, 'color' => 'red'])->assertSessionHasErrors('color');
        $this->post(route('zones.store'), ['name' => 'Default', 'level' => 10])->assertSessionHasErrors('name');
        $this->assertSame(1, Zone::count());
    }

    public function test_a_zone_with_servers_and_the_default_zone_cannot_be_deleted(): void
    {
        $this->actingAs($this->admin());
        $lan = $this->zone('LAN', 40);
        Server::create(['name' => 'a', 'address' => '192.0.2.1', 'zone_id' => $lan->id]);

        $this->delete(route('zones.destroy', $lan))->assertSessionHasErrors('zone');
        $this->delete(route('zones.destroy', Zones::default()))->assertSessionHasErrors('zone');
        $this->assertSame(2, Zone::count());

        Server::query()->update(['zone_id' => Zones::defaultId()]);
        $this->delete(route('zones.destroy', $lan))->assertRedirect(route('zones.index'));
        $this->assertSame(1, Zone::count());
    }

    public function test_making_another_zone_the_default_swaps_the_domains_instead_of_losing_them(): void
    {
        $this->actingAs($this->admin());
        Settings::set(['local_domains' => 'home.example.com', 'public_domains' => '']);
        $lan = $this->zone('LAN', 40, ['domains' => ['local' => ['lan.example.com']]]);
        $old = Zones::default();

        $this->put(route('zones.update', $lan), ['name' => 'LAN', 'level' => 40, 'is_default' => 1, 'local_domains' => 'lan.example.com'])->assertSessionHasNoErrors();

        $this->assertSame($lan->id, Zones::default()->id);
        $this->assertSame(['local' => ['lan.example.com'], 'public' => []], Zones::default()->domains());
        $this->assertSame('lan.example.com', Settings::get('local_domains'));
        $this->assertSame(['local' => ['home.example.com'], 'public' => []], $old->fresh()->domains());
        $this->assertSame(1, Zone::where('is_default', true)->count());
    }

    public function test_only_admins_reach_the_zone_screens(): void
    {
        $this->admin();
        $this->actingAs($this->user())->get(route('zones.index'))->assertForbidden();
    }

    // ------------------------------------------------------------------------------- YAML

    public function test_a_single_zone_export_has_no_zone_keys_and_old_files_still_import(): void
    {
        Server::create(['name' => 'nas', 'address' => '192.0.2.10']);
        $yaml = YamlConfig::export();

        $this->assertStringNotContainsString('zone', $yaml);

        $result = YamlConfig::import(YamlConfig::parse($yaml));
        $this->assertArrayNotHasKey('zones', $result);
        $this->assertSame(1, Server::count());
    }

    public function test_zones_round_trip_through_yaml_without_secrets(): void
    {
        $lan = $this->zone('LAN', 40, ['color' => '#2557d6', 'cidrs' => ['192.0.2.0/24'], 'domains' => ['local' => ['lan.example.com']], 'proxy_address' => '192.0.2.5', 'notes' => 'inside']);
        $ext = $this->zone('External', 10, ['domains' => ['public' => ['example.dev']]]);
        $a = Server::create(['name' => 'a', 'address' => '192.0.2.1', 'zone_id' => $lan->id]);
        Server::create(['name' => 'b', 'address' => '198.51.100.1', 'zone_id' => $ext->id]);
        Service::create(['name' => 'Wiki', 'server_id' => $a->id, 'zone_id' => $ext->id, 'scheme' => 'http', 'path' => '/']);

        $yaml = YamlConfig::export();
        $this->assertStringContainsString('zones:', $yaml);
        $this->assertStringContainsString('zone: External', $yaml);

        // Wipe and restore from the file.
        Service::query()->delete();
        Server::query()->delete();
        Zone::where('is_default', false)->delete();

        YamlConfig::import(YamlConfig::parse($yaml));

        $this->assertSame(3, Zone::count());
        $lan = Zone::where('name', 'LAN')->first();
        $this->assertSame(['192.0.2.0/24'], $lan->cidrs);
        $this->assertSame('#2557d6', $lan->color);
        $this->assertSame('192.0.2.5', $lan->proxy_address);
        $this->assertSame(['local' => ['lan.example.com'], 'public' => []], $lan->domains());
        $this->assertSame($lan->id, Server::where('name', 'a')->first()->zone_id);
        $this->assertSame(Zone::where('name', 'External')->first()->id, Service::where('name', 'Wiki')->first()->zone_id);
    }

    public function test_the_yaml_default_flag_hands_over_the_domains_setting(): void
    {
        Settings::set(['local_domains' => 'home.example.com']);

        YamlConfig::import(YamlConfig::parse("zones:\n  - {name: Core, level: 90, default: true, domains: {local: [core.example.com]}}\nservers: []\nservices: []\n"));

        $core = Zones::default();
        $this->assertSame('Core', $core->name);
        $this->assertSame('core.example.com', Settings::get('local_domains'));
        $this->assertSame(['local' => ['home.example.com'], 'public' => []], Zone::where('name', 'Default')->first()->domains());
    }

    public function test_domains_set_in_the_same_file_belong_to_the_zone_that_file_makes_default(): void
    {
        YamlConfig::import(YamlConfig::parse("settings:\n  local_domains: home.example.com\nzones:\n  - {name: Core, level: 90, default: true}\nservers: []\nservices: []\n"));

        $this->assertSame('Core', Zones::default()->name);
        $this->assertSame(['local' => ['home.example.com'], 'public' => []], Zones::default()->domains());
        $this->assertSame([], Zone::where('name', 'Default')->first()->domains()['local'], 'the old default does not keep them');
    }

    public function test_bad_zone_yaml_is_rejected_with_clear_messages(): void
    {
        $cases = [
            "zones: [{level: 5}]\nservers: []\nservices: []\n" => "'name' is required",
            "zones: [{name: A, level: 500}]\nservers: []\nservices: []\n" => "'level' must be a whole number from 0 to 100",
            "zones: [{name: A, cidrs: ['192.0.2.0/40']}]\nservers: []\nservices: []\n" => 'is not a network block',
            "zones: [{name: A, domains: {public: ['bad_domain!.com']}}]\nservers: []\nservices: []\n" => 'not a domain name',
            "zones: [{name: A, default: true}, {name: B, default: true}]\nservers: []\nservices: []\n" => 'only one zone can be the default',
            "zones: [{name: A}, {name: a}]\nservers: []\nservices: []\n" => 'duplicate zone name',
            "servers: [{name: s, address: 192.0.2.1, zone: Nowhere}]\nservices: []\n" => "zone 'Nowhere' is not defined",
        ];

        foreach ($cases as $yaml => $message) {
            try {
                YamlConfig::parse($yaml);
                $this->fail("Expected a rejection containing: {$message}");
            } catch (ConfigException $e) {
                $this->assertStringContainsString($message, implode(' | ', $e->errors));
            }
        }
    }
}
