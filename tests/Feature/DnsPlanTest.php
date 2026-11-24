<?php

namespace Tests\Feature;

use App\Config\ConfigException;
use App\Config\YamlConfig;
use App\Dns\DnsExport;
use App\Dns\DnsOwners;
use App\Dns\DnsPlan;
use App\Models\Server;
use App\Models\Service;
use App\Models\Zone;
use App\Npm\Mirrors;
use App\Support\Settings;
use App\Support\Zones;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Step 5 of zones: shared domains settled by an owner, and the read-only DNS plan with its exports. */
class DnsPlanTest extends TestCase
{
    use RefreshDatabase;

    private Zone $internal;

    private Zone $external;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->admin(['email' => 'root@example.com']));
        Settings::set(['local_domains' => 'home.example.com']);
        $this->internal = Zones::default();
        $this->internal->update(['name' => 'Internal', 'level' => 60, 'proxy_address' => '192.0.2.5']);
        $this->external = Zone::create(['name' => 'External', 'level' => 10, 'domains' => ['public' => ['example.dev']], 'proxy_address' => '203.0.113.5', 'dns' => ['public_address' => '203.0.113.10']]);
    }

    private function rec(array $plan, string $name, string $resolver = 'inside'): ?array
    {
        foreach ($plan['records'] as $r) {
            if ($r['name'] === $name && $r['resolver'] === $resolver) {
                return $r;
            }
        }

        return null;
    }

    private function conflict(array $plan, string $kind): ?array
    {
        foreach ($plan['conflicts'] as $c) {
            if ($c['kind'] === $kind) {
                return $c;
            }
        }

        return null;
    }

    // ----------------------------------------------------------------------------- wildcards

    public function test_each_zone_domain_gets_a_wildcard_on_the_resolver_that_serves_it(): void
    {
        $plan = DnsPlan::build();

        $inside = $this->rec($plan, '*.home.example.com');
        $this->assertSame('192.0.2.5', $inside['value']);
        $this->assertSame('Internal', $inside['zone']);
        $this->assertSame('A', $inside['type']);

        $public = $this->rec($plan, '*.example.dev', 'public');
        $this->assertSame('203.0.113.10', $public['value']);
        $this->assertNull($this->rec($plan, '*.example.dev', 'inside'), 'a public domain is not answered inside unless split-horizon is on');
    }

    public function test_split_horizon_answers_a_public_domain_inside_with_the_zones_proxy(): void
    {
        $this->external->update(['dns' => ['public_address' => '203.0.113.10', 'split_horizon' => true]]);

        $plan = DnsPlan::build();

        $this->assertSame('203.0.113.5', $this->rec($plan, '*.example.dev', 'inside')['value']);
        $this->assertSame('203.0.113.10', $this->rec($plan, '*.example.dev', 'public')['value']);
    }

    public function test_a_zone_without_a_proxy_address_is_flagged_and_a_public_domain_without_an_address_waits_for_one(): void
    {
        $this->internal->update(['proxy_address' => null]);
        $this->external->update(['dns' => null]);

        $plan = DnsPlan::build();

        $this->assertNull($this->rec($plan, '*.home.example.com'));
        $this->assertStringContainsString('no proxy address', $this->conflict($plan, 'no_proxy_address')['message']);
        $waiting = $this->rec($plan, '*.example.dev', 'public');
        $this->assertNull($waiting['value']);
        $this->assertStringContainsString('CDN, tunnel or public address', $waiting['note']);
    }

    // ------------------------------------------------------------------------------ servers

    public function test_every_server_gets_a_record_at_its_own_address_with_a_suggested_name_when_it_has_no_domain(): void
    {
        Server::create(['name' => 'nas', 'address' => '192.0.2.10', 'domain' => 'nas.home.example.com']);
        Server::create(['name' => 'Docker Host', 'address' => '192.0.2.20']);
        Server::create(['name' => 'alias', 'address' => 'box.internal.example.net', 'domain' => 'alias.home.example.com']);
        Server::create(['name' => 'edge', 'address' => '198.51.100.7', 'zone_id' => $this->external->id]);

        $plan = DnsPlan::build();

        $nas = $this->rec($plan, 'nas.home.example.com');
        $this->assertSame('192.0.2.10', $nas['value']);
        $this->assertSame('server', $nas['kind']);
        $this->assertFalse($nas['derived']);

        $derived = $this->rec($plan, 'docker-host.home.example.com');
        $this->assertSame('192.0.2.20', $derived['value']);
        $this->assertTrue($derived['derived']);

        $this->assertSame('CNAME', $this->rec($plan, 'alias.home.example.com')['type']);
        $this->assertSame([], array_filter($plan['records'], fn ($r) => $r['value'] === '198.51.100.7'), 'a zone with no local domain makes no name up');
    }

    // ------------------------------------------------------------------------------ services

    public function test_a_service_name_a_wildcard_already_covers_needs_no_record_but_others_do(): void
    {
        $server = Server::create(['name' => 'web1', 'address' => '192.0.2.11']);
        Service::create(['name' => 'Wiki', 'server_id' => $server->id, 'domain' => 'wiki.home.example.com', 'scheme' => 'http', 'path' => '/']);
        Service::create(['name' => 'Tool', 'server_id' => $server->id, 'domain' => 'tool.other.example.net', 'scheme' => 'http', 'path' => '/']);
        Service::create(['name' => 'Blog', 'address' => '198.51.100.7', 'zone_id' => $this->external->id, 'public_domain' => 'blog.example.dev', 'scheme' => 'http', 'path' => '/']);

        $plan = DnsPlan::build();

        $this->assertNull($this->rec($plan, 'wiki.home.example.com'), 'covered by *.home.example.com');
        $tool = $this->rec($plan, 'tool.other.example.net');
        $this->assertSame('192.0.2.5', $tool['value'], 'a name under no zone domain gets its own record, to its zone\'s proxy');
        $this->assertSame('service', $tool['kind']);
        $this->assertNull($this->rec($plan, 'blog.example.dev', 'public'), 'covered by the public wildcard');
    }

    public function test_a_name_below_a_record_of_its_own_is_flagged_and_gets_an_explicit_record(): void
    {
        $server = Server::create(['name' => 'nas', 'address' => '192.0.2.10', 'domain' => 'nas.home.example.com']);
        Service::create(['name' => 'Deep', 'server_id' => $server->id, 'domain' => 'app.nas.home.example.com', 'scheme' => 'http', 'path' => '/']);

        $plan = DnsPlan::build();

        $this->assertSame('192.0.2.5', $this->rec($plan, 'app.nas.home.example.com')['value']);
        $this->assertStringContainsString('has its own record', $this->conflict($plan, 'shadowed')['message']);
    }

    public function test_a_server_record_that_hides_a_proxied_name_is_flagged(): void
    {
        $server = Server::create(['name' => 'nas', 'address' => '192.0.2.10', 'domain' => 'nas.home.example.com']);
        Service::create(['name' => 'Files', 'server_id' => $server->id, 'domain' => 'nas.home.example.com', 'scheme' => 'http', 'path' => '/']);

        $plan = DnsPlan::build();

        $this->assertSame('192.0.2.10', $this->rec($plan, 'nas.home.example.com')['value']);
        $this->assertStringContainsString('also hides the proxied name of Files', $this->conflict($plan, 'server_shadows_service')['message']);
    }

    // ------------------------------------------------------------------------------ mirrors

    public function test_a_mirrors_name_is_answered_inside_by_the_mirroring_zones_proxy(): void
    {
        Service::create(['name' => 'Blog', 'address' => '198.51.100.7', 'zone_id' => $this->external->id, 'public_domain' => 'blog.example.dev', 'scheme' => 'http', 'path' => '/']);
        $this->internal->update(['mirror_sources' => [['zone_id' => $this->external->id, 'upstream' => 'auto']]]);

        $plan = DnsPlan::build();

        $m = $this->rec($plan, 'blog.example.dev', 'inside');
        $this->assertSame('mirror', $m['kind']);
        $this->assertSame('192.0.2.5', $m['value']);
        $this->assertSame('Internal', $m['zone']);
        $this->assertSame('203.0.113.10', $this->rec($plan, '*.example.dev', 'public')['value'], 'outside it still resolves to the source zone');
    }

    public function test_a_mirror_whose_upstream_name_resolves_to_its_own_proxy_is_an_error(): void
    {
        Service::create(['name' => 'Blog', 'address' => '198.51.100.7', 'zone_id' => $this->external->id, 'public_domain' => 'blog.example.dev', 'scheme' => 'http', 'path' => '/']);
        $this->internal->update(['mirror_sources' => [['zone_id' => $this->external->id, 'upstream' => 'auto']]]);
        $this->external->update(['proxy_address' => 'edge.home.example.com']); // under the inside wildcard: answered by the Internal proxy

        $c = $this->conflict(DnsPlan::build(), 'mirror_loop');

        $this->assertSame('error', $c['severity']);
        $this->assertStringContainsString('would loop', $c['message']);

        $this->external->update(['proxy_address' => '203.0.113.5']);
        $this->assertNull($this->conflict(DnsPlan::build(), 'mirror_loop'));
    }

    // --------------------------------------------------------------------- shared domains

    public function test_two_zones_listing_the_same_domain_need_an_owner_before_any_record_is_made(): void
    {
        $lab = Zone::create(['name' => 'Lab', 'level' => 30, 'domains' => ['local' => ['home.example.com']], 'proxy_address' => '198.51.100.5']);

        $plan = DnsPlan::build();
        $c = $this->conflict($plan, 'domain');

        $this->assertSame('error', $c['severity']);
        $this->assertEqualsCanonicalizing(['Internal', 'Lab'], $c['zones']);
        $this->assertNull($this->rec($plan, '*.home.example.com'), 'nothing is guessed');
        $this->assertSame(1, count(DnsPlan::open($plan['conflicts'])));
        $this->assertCount(2, Zones::owners('wiki.home.example.com'));
        $this->assertNull(Zones::ownerOf('wiki.home.example.com'));

        $this->post(route('dns.owner'), ['kind' => 'domain', 'key' => 'home.example.com', 'zone_id' => $lab->id])->assertRedirect(route('dns.index'));

        $after = DnsPlan::build();
        $this->assertSame('resolved', $this->conflict($after, 'domain')['severity']);
        $this->assertSame('198.51.100.5', $this->rec($after, '*.home.example.com')['value']);
        $this->assertSame('Lab', $this->rec($after, '*.home.example.com')['zone']);
        $this->assertSame($lab->id, Zones::ownerOf('wiki.home.example.com')->id, 'the picked owner settles the tie everywhere');
        $this->assertSame($lab->id, DnsOwners::domainOwner('HOME.example.com'));

        // The other zone can still reach the owner's hosts by mirroring them.
        $this->post(route('dns.propose'), ['kind' => 'zone', 'target_zone' => $this->internal->id, 'source_zone' => $lab->id])->assertRedirect(route('dns.index'));
        $this->assertSame([$lab->id => 'auto'], Mirrors::sourcesOf($this->internal->fresh()));
    }

    public function test_a_more_specific_sub_domain_zone_needs_no_owner_it_wins_by_the_longest_suffix(): void
    {
        $this->external->update(['domains' => ['public' => ['example.dev']]]);
        $sub = Zone::create(['name' => 'Sub', 'level' => 20, 'domains' => ['public' => ['a.example.dev']], 'dns' => ['public_address' => '203.0.113.20']]);

        $plan = DnsPlan::build();

        $this->assertNull($this->conflict($plan, 'domain'));
        $this->assertSame($sub->id, Zones::ownerOf('wiki.a.example.dev')->id);
        $this->assertSame($this->external->id, Zones::ownerOf('wiki.example.dev')->id);
        $this->assertSame('203.0.113.20', $this->rec($plan, '*.a.example.dev', 'public')['value']);
        $this->assertSame('203.0.113.10', $this->rec($plan, '*.example.dev', 'public')['value']);
    }

    public function test_the_same_name_used_in_two_zones_needs_an_owner_and_the_other_zone_can_publish_it(): void
    {
        $outer = Service::create(['name' => 'Shared', 'address' => '198.51.100.7', 'zone_id' => $this->external->id, 'public_domain' => 'shared.example.dev', 'scheme' => 'http', 'path' => '/']);
        Service::create(['name' => 'Shared inside', 'address' => '192.0.2.50', 'zone_id' => $this->internal->id, 'public_domain' => 'shared.example.dev', 'scheme' => 'http', 'path' => '/']);

        $plan = DnsPlan::build();
        $c = $this->conflict($plan, 'name');

        $this->assertSame('shared.example.dev', $c['key']);
        $this->assertSame('error', $c['severity']);
        $this->assertNull($this->rec($plan, 'shared.example.dev', 'public'));

        $this->post(route('dns.owner'), ['kind' => 'name', 'key' => 'shared.example.dev', 'zone_id' => $this->external->id])->assertSessionHasNoErrors();
        $this->assertSame('resolved', $this->conflict(DnsPlan::build(), 'name')['severity']);

        $this->post(route('dns.propose'), ['kind' => 'service', 'target_zone' => $this->internal->id, 'service_id' => $outer->id])->assertRedirect(route('dns.index'));
        $this->assertSame([['zone_id' => $this->internal->id, 'upstream' => 'auto']], $outer->fresh()->publish_zones);
    }

    // ------------------------------------------------------------------------------ exports

    private function exportFixture(): void
    {
        Server::create(['name' => 'nas', 'address' => '192.0.2.10', 'domain' => 'nas.home.example.com']);
        Server::create(['name' => 'alias', 'address' => 'box.internal.example.net', 'domain' => 'alias.home.example.com']);
        $this->external->update(['dns' => null]); // the public wildcard has no address yet
    }

    public function test_the_exports_carry_the_same_records_in_each_format(): void
    {
        $this->exportFixture();
        $plan = DnsPlan::build();

        $hosts = DnsExport::render($plan, 'hosts');
        $this->assertStringContainsString("192.0.2.10 nas.home.example.com\n", $hosts);
        $this->assertStringContainsString('# 192.0.2.5 *.home.example.com  (wildcard: use a resolver)', $hosts);
        $this->assertStringContainsString('# alias.home.example.com CNAME box.internal.example.net', $hosts);
        $this->assertStringNotContainsString('example.dev', $hosts, 'public records are not for an inside hosts file');

        $adguard = DnsExport::render($plan, 'adguard');
        $this->assertStringContainsString('|nas.home.example.com^$dnsrewrite=NOERROR;A;192.0.2.10', $adguard);
        $this->assertStringContainsString('|*.home.example.com^$dnsrewrite=NOERROR;A;192.0.2.5', $adguard);
        $this->assertStringContainsString('|alias.home.example.com^$dnsrewrite=NOERROR;CNAME;box.internal.example.net', $adguard);

        $json = json_decode(DnsExport::render($plan, 'json'), true);
        $names = array_column($json['records'], 'name');
        $this->assertContains('nas.home.example.com', $names);
        $this->assertNotContains('*.example.dev', $names, 'a record with no answer yet is left out of the machine formats');
        $this->assertArrayHasKey('conflicts', $json);

        $text = DnsExport::render($plan, 'text');
        $this->assertStringContainsString('(set the public address)', $text, 'the readable plan lists it, waiting');
        $this->assertStringContainsString('Nothing here has been written anywhere', $text);
    }

    public function test_the_export_downloads_with_the_right_type_and_unknown_formats_are_refused(): void
    {
        $this->exportFixture();

        $this->get(route('dns.export', ['format' => 'json']))->assertOk()->assertHeader('Content-Type', 'application/json; charset=UTF-8')
            ->assertHeader('Content-Disposition', 'attachment; filename="labman-dns.json"');
        $this->get(route('dns.export', ['format' => 'hosts']))->assertOk()->assertSee('nas.home.example.com');
        $this->get(route('dns.export', ['format' => 'zonefile']))->assertNotFound();
    }

    // ------------------------------------------------------------------------ screens and YAML

    public function test_the_dns_page_shows_both_resolvers_and_the_collisions_to_settle(): void
    {
        Zone::create(['name' => 'Lab', 'level' => 30, 'domains' => ['local' => ['home.example.com']], 'proxy_address' => '198.51.100.5']);

        $this->get(route('dns.index'))->assertOk()
            ->assertSee('Inside resolver')->assertSee('Public provider')
            ->assertSee('collision(s) need an owner')->assertSee('Pick owner')->assertSee('home.example.com')
            ->assertSee(route('dns.export', ['format' => 'adguard']), false);

        $this->actingAs($this->user())->get(route('dns.index'))->assertForbidden();
    }

    public function test_the_zone_form_saves_the_dns_settings_and_they_round_trip_through_yaml(): void
    {
        $this->put(route('zones.update', $this->external), ['name' => 'External', 'level' => 10, 'public_domains' => 'example.dev', 'dns_public_address' => '203.0.113.77', 'dns_split_horizon' => 1])->assertSessionHasNoErrors();
        $this->assertSame(['split_horizon' => true, 'public_address' => '203.0.113.77'], $this->external->fresh()->dnsSettings());

        $this->get(route('zones.edit', $this->external))->assertOk()->assertSee('Public address')->assertSee('split-horizon');
        $this->put(route('zones.update', $this->external), ['name' => 'External', 'level' => 10, 'public_domains' => 'example.dev', 'dns_public_address' => 'not an address!'])->assertSessionHasErrors('dns_public_address');

        $yaml = YamlConfig::export();
        $this->assertStringContainsString('split_horizon: true', $yaml);

        $this->external->update(['dns' => null]);
        YamlConfig::import(YamlConfig::parse($yaml));
        $this->assertSame(['split_horizon' => true, 'public_address' => '203.0.113.77'], $this->external->fresh()->dnsSettings());

        foreach ([
            "zones: [{name: A, dns: {bogus: 1}}]\nservers: []\nservices: []\n" => "'dns' must be a mapping",
            "zones: [{name: A, dns: {split_horizon: maybe}}]\nservers: []\nservices: []\n" => 'split_horizon must be true or false',
            "zones: [{name: A, dns: {public_address: 'bad address!'}}]\nservers: []\nservices: []\n" => 'public_address must be an IP address or host name',
        ] as $bad => $message) {
            try {
                YamlConfig::parse($bad);
                $this->fail("expected: {$message}");
            } catch (ConfigException $e) {
                $this->assertStringContainsString($message, implode(' | ', $e->errors));
            }
        }
    }
}
