<?php

namespace Tests\Feature;

use App\Config\YamlConfig;
use App\Dns\AdguardClient;
use App\Dns\AdguardSync;
use App\Dns\DnsPlan;
use App\Models\ApiToken;
use App\Models\ManagedRewrite;
use App\Models\Mirror;
use App\Models\Service;
use App\Models\Zone;
use App\Npm\Connection;
use App\Npm\Mirrors;
use App\Npm\NpmAuto;
use App\Npm\NpmClient;
use App\Npm\NpmSync;
use App\Support\Settings;
use App\Support\Zones;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeNpm;
use Tests\TestCase;

/**
 * A mirror covers only the names the mirroring zone answers, never makes two answers for one name, can cover chosen
 * names, can forward straight to the service ("direct"), and an install with over-broad mirrors can clean them up.
 *
 * LAN answers example.dev inside. example.me belongs to the Other zone, whose wildcard answers it. The App service
 * lives in External with a name under each, and has its own explicit record for the example.me one.
 */
class MirrorNamesTest extends TestCase
{
    use RefreshDatabase;

    private FakeNpm $npm;

    private Zone $lan;

    private Zone $external;

    private Zone $other;

    private Service $svc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->admin(['email' => 'root@example.com']));
        $this->npm = FakeNpm::install();
        NpmAuto::setEnabled(false);

        Settings::set(['local_domains' => 'example.dev']);
        $this->lan = Zones::default();
        $this->lan->update(['name' => 'LAN', 'level' => 60, 'proxy_address' => '192.0.2.5']);
        $this->external = Zone::create(['name' => 'External', 'level' => 10, 'domains' => ['public' => ['example.dev']], 'proxy_address' => '203.0.113.5']);
        $this->other = Zone::create(['name' => 'Other', 'level' => 30, 'domains' => ['local' => ['example.me']], 'proxy_address' => '192.0.2.9']);
        Connection::save(FakeNpm::URL, 'npm@example.com', 'npm-secret', true);

        $this->svc = Service::create([
            'name' => 'App', 'address' => '198.51.100.7', 'zone_id' => $this->external->id, 'domain' => 'app.example.me', 'public_domain' => 'app.example.dev',
            'scheme' => 'http', 'port' => 8080, 'path' => '/', 'websockets' => true,
            'publish_zones' => [['zone_id' => $this->lan->id, 'upstream' => 'auto']],
        ]);
        $this->npm->certificates[] = ['id' => 1, 'nice_name' => 'Wildcard', 'provider' => 'other', 'domain_names' => ['*.example.dev', '*.example.me'], 'expires_on' => gmdate('Y-m-d H:i:s', time() + 90 * 86400), 'meta' => []];
    }

    private function sync(): NpmSync
    {
        return new NpmSync(NpmClient::fromStored($this->lan), $this->lan);
    }

    /** @return list<array<string, mixed>> the mirror items of a LAN push plan */
    private function mirrorItems(): array
    {
        return array_values(array_filter($this->sync()->pushPlan(), fn ($i) => str_starts_with($i['key'], 'mirror:')));
    }

    /** @return list<array<string, mixed>> */
    private function recordsFor(string $name, ?string $resolver = 'inside'): array
    {
        return array_values(array_filter(DnsPlan::build()['records'], fn ($r) => $r['name'] === $name && ($resolver === null || $r['resolver'] === $resolver)));
    }

    // ------------------------------------------------------------ only the names the zone answers

    public function test_a_two_name_service_is_mirrored_only_under_the_domain_the_mirroring_zone_answers(): void
    {
        $this->assertSame(['app.example.dev'], array_keys(Mirrors::wanted($this->lan)));
        $this->assertSame(['app.example.dev'], Mirrors::defaultNames($this->svc, $this->lan));
        $this->assertSame(['app.example.me'], Mirrors::defaultNames($this->svc, $this->other), 'each zone covers the names under its own domains');

        $items = $this->mirrorItems();
        $this->assertCount(1, $items, 'one mirror host, not two');
        $this->assertStringContainsString('app.example.dev', $items[0]['title']);
        $this->assertSame('create', $items[0]['action']);
    }

    public function test_the_dns_plan_gives_each_name_one_answer_and_leaves_the_other_zones_name_alone(): void
    {
        $plan = DnsPlan::build();

        $me = $this->recordsFor('app.example.me');
        $this->assertCount(1, $me, 'once, not once per source');
        $this->assertSame('service', $me[0]['kind']);
        $this->assertSame('203.0.113.5', $me[0]['value'], 'its own record to its own zone proxy, not moved to the LAN proxy');

        $this->assertSame('192.0.2.5', DnsPlan::lookup($plan['records'], 'inside', 'app.example.dev'), 'the LAN wildcard answers the name the mirror serves');
        $this->assertSame([], array_filter($plan['conflicts'], fn ($c) => $c['kind'] === 'duplicate_name'));
    }

    public function test_a_name_claimed_by_a_mirror_and_a_service_record_is_a_conflict_with_no_record_written(): void
    {
        $this->svc->update(['publish_zones' => [['zone_id' => $this->lan->id, 'upstream' => 'auto', 'names' => ['app.example.me', 'app.example.dev']]]]);

        $plan = DnsPlan::build();
        $conflict = collect($plan['conflicts'])->firstWhere('kind', 'duplicate_name');

        $this->assertNotNull($conflict);
        $this->assertSame('error', $conflict['severity']);
        $this->assertSame('app.example.me', $conflict['key']);
        $this->assertStringContainsString('more than one answer', $conflict['message']);
        $this->assertSame([], $this->recordsFor('app.example.me'), 'neither answer is written');
        $this->assertSame('192.0.2.5', DnsPlan::lookup($plan['records'], 'inside', 'app.example.dev'), 'the other name is unaffected');
    }

    public function test_the_same_answer_from_two_sources_is_one_record_not_a_conflict(): void
    {
        // A service in LAN itself with an explicit record for a name the wildcard does not cover, and a server with that name and address of the proxy.
        $plan = DnsPlan::build();
        $names = array_column(array_filter($plan['records'], fn ($r) => $r['kind'] !== 'wildcard'), 'name');
        $this->assertSame($names, array_values(array_unique($names)));
    }

    // ------------------------------------------------------------ choosing the names

    public function test_a_service_can_choose_the_names_a_mirror_covers(): void
    {
        $this->svc->update(['publish_zones' => [['zone_id' => $this->lan->id, 'upstream' => 'auto', 'names' => ['app.example.me']]]]);

        $this->assertSame(['app.example.me'], array_keys(Mirrors::wanted($this->lan)));

        $this->svc->update(['publish_zones' => [['zone_id' => $this->lan->id, 'upstream' => 'auto', 'names' => ['nope.example.dev']]]]);
        $this->assertSame([], Mirrors::wanted($this->lan), 'a name the service does not have covers nothing');
    }

    public function test_the_service_form_lists_the_names_ticked_by_the_rule_and_saves_a_different_choice(): void
    {
        $page = $this->get(route('services.edit', $this->svc))->assertOk()->getContent();
        $this->assertStringContainsString('name="publish_names['.$this->lan->id.'][]" value="app.example.dev" checked', $page);
        $this->assertStringNotContainsString('name="publish_names['.$this->lan->id.'][]" value="app.example.me" checked', $page);
        $this->assertStringContainsString('trusted-proxy list', $page, 'the warning about direct');

        $form = ['name' => 'App', 'address' => '198.51.100.7', 'scheme' => 'http', 'port' => 8080, 'path' => '/', 'expect' => '200-499', 'monitor' => '1', 'check_tcp' => '1', 'verify_tls' => '1', 'public_check' => '1', 'on_dashboard' => '1', 'websockets' => '1',
            'local_domains' => ['app.example.me'], 'public_domains' => ['app.example.dev'], 'zone_id' => $this->external->id,
            'publish_form' => '1', 'publish_in' => [$this->lan->id], 'publish_upstream' => [$this->lan->id => 'direct']];

        // Exactly the rule's own default is stored as "no choice", so it keeps following the rule.
        $this->put(route('services.update', $this->svc), $form + ['publish_names' => [$this->lan->id => ['app.example.dev']]])->assertSessionHasNoErrors();
        $this->assertSame([['zone_id' => $this->lan->id, 'upstream' => 'direct']], $this->svc->fresh()->publish_zones);

        $this->put(route('services.update', $this->svc), $form + ['publish_names' => [$this->lan->id => ['app.example.me', 'app.example.dev']]])->assertSessionHasNoErrors();
        $this->assertSame(['app.example.me', 'app.example.dev'], $this->svc->fresh()->publish_zones[0]['names']);
        $this->get(route('services.edit', $this->svc))->assertSee('value="app.example.me" checked', false);
    }

    public function test_the_yaml_round_trips_the_names_and_the_direct_upstream(): void
    {
        $this->svc->update(['publish_zones' => [['zone_id' => $this->lan->id, 'upstream' => 'direct', 'names' => ['app.example.me']]]]);

        $yaml = YamlConfig::export();
        $this->assertStringContainsString('direct', $yaml);
        $this->assertStringContainsString('app.example.me', $yaml);

        $this->svc->update(['publish_zones' => null]);
        YamlConfig::import(YamlConfig::parse($yaml));
        $this->assertSame([['zone_id' => $this->lan->id, 'upstream' => 'direct', 'names' => ['app.example.me']]], $this->svc->fresh()->publish_zones);

        $this->expectException(\App\Config\ConfigException::class);
        YamlConfig::parse(str_replace('app.example.me', 'not a host!', preg_replace('/^(\s*)- name: App$/m', '$1- name: App', $yaml)));
    }

    public function test_mcp_sets_the_names_and_direct_and_rejects_names_the_service_does_not_have(): void
    {
        [, $token] = ApiToken::issue($this->admin(), 'test', 'admin');
        $call = fn (array $args) => $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'set_service_zone', 'arguments' => (object) $args]], ['Authorization' => 'Bearer '.$token])->assertOk()->json('result');

        $out = $call(['service' => 'App', 'publish_in' => [['zone' => 'LAN', 'upstream' => 'direct', 'names' => ['app.example.me']]]]);
        $this->assertFalse($out['isError']);
        $ref = $out['structuredContent']['publish_in'][0];
        $this->assertSame(['direct', ['app.example.me'], true], [$ref['upstream'], $ref['names'], $ref['names_chosen']]);

        $default = $call(['service' => 'App', 'publish_in' => [['zone' => 'LAN']]]);
        $this->assertSame([['app.example.dev'], false], [$default['structuredContent']['publish_in'][0]['names'], $default['structuredContent']['publish_in'][0]['names_chosen']]);

        $this->assertTrue($call(['service' => 'App', 'publish_in' => [['zone' => 'LAN', 'names' => ['ghost.example.dev']]]])['isError']);
    }

    // ------------------------------------------------------------ direct

    public function test_direct_forwards_to_the_services_own_backend_with_websockets_and_warns_about_trusted_proxies(): void
    {
        $this->external->update(['proxy_address' => null]); // the other proxy is not even needed
        $this->svc->update(['publish_zones' => [['zone_id' => $this->lan->id, 'upstream' => 'direct']]]);

        $items = $this->mirrorItems();
        $this->assertCount(1, $items);
        $item = $items[0];
        $p = $item['data']['payload'];

        $this->assertSame('create', $item['action']);
        $this->assertSame(['198.51.100.7', 8080, 'http', true], [$p['forward_host'], $p['forward_port'], $p['forward_scheme'], $p['allow_websocket_upgrade']]);
        $this->assertStringContainsString(Mirrors::MARKER.' External', $p['advanced_config']);
        $this->assertStringNotContainsString('proxy_ssl', $p['advanced_config']);
        $this->assertStringContainsString('trusted-proxy list', implode(' ', $item['warnings']));
        $this->assertStringContainsString('Home Assistant', implode(' ', $item['warnings']));
        $this->assertSame([], $this->npm->writes(), 'a preview writes nothing');

        // An https backend is forwarded to over https, without verifying its own certificate.
        $this->svc->update(['scheme' => 'https', 'port' => 8443]);
        $p = $this->mirrorItems()[0]['data']['payload'];
        $this->assertSame(['https', 8443], [$p['forward_scheme'], $p['forward_port']]);
        $this->assertStringContainsString('proxy_ssl_verify off;', $p['advanced_config']);
    }

    public function test_direct_makes_no_loop_conflict_in_the_dns_plan(): void
    {
        $this->external->update(['proxy_address' => 'edge.example.dev']); // would loop through the LAN wildcard if mirrored via the proxy
        $this->svc->update(['publish_zones' => [['zone_id' => $this->lan->id, 'upstream' => 'auto']]]);
        $this->assertNotNull(collect(DnsPlan::build()['conflicts'])->firstWhere('kind', 'mirror_loop'));

        $this->svc->update(['publish_zones' => [['zone_id' => $this->lan->id, 'upstream' => 'direct']]]);
        $this->assertNull(collect(DnsPlan::build()['conflicts'])->firstWhere('kind', 'mirror_loop'), 'direct does not go through the other proxy');
    }

    // ------------------------------------------------------------ cleaning up an install with over-broad mirrors

    private function oldMirror(string $domain): int
    {
        $hostId = $this->npm->addHost([
            'domain_names' => [$domain], 'forward_scheme' => 'https', 'forward_host' => '203.0.113.5', 'forward_port' => 443,
            'advanced_config' => Mirrors::advancedConfig('https', 'External'),
        ]);
        Mirror::create(['service_id' => $this->svc->id, 'service_name' => 'App', 'zone_id' => $this->lan->id, 'domain' => $domain, 'npm_host_id' => $hostId, 'upstream' => 'auto']);

        return $hostId;
    }

    public function test_mirror_hosts_for_names_that_no_longer_qualify_become_orphans_offered_for_deletion_and_not_ticked(): void
    {
        // What an earlier version made: a mirror for both names.
        $keep = $this->oldMirror('app.example.dev');
        $extra = $this->oldMirror('app.example.me');

        $plan = $this->sync()->pushPlan();
        $deletes = array_values(array_filter($plan, fn ($i) => $i['action'] === 'delete'));

        $this->assertCount(1, $deletes);
        $this->assertStringContainsString('app.example.me', $deletes[0]['title']);
        $this->assertSame($extra, $deletes[0]['data']['host_id']);
        $this->assertStringContainsString('does not answer this name', implode(' ', $deletes[0]['warnings']));
        $this->assertStringContainsString('cannot be undone', implode(' ', $deletes[0]['warnings']));
        $this->assertNotContains('DELETE', $this->npm->methods(), 'a preview deletes nothing');

        // The way the review ticks things: creates and updates only.
        $ticked = [];
        foreach ($plan as $item) {
            if (in_array($item['action'], ['create', 'update'], true)) {
                $ticked[$item['key']] = $item['fingerprint'];
            }
        }
        $this->assertArrayNotHasKey($deletes[0]['key'], $ticked);
        $this->sync()->applyPush($ticked);
        $this->assertArrayHasKey($extra, $this->npm->hosts, 'still there until someone ticks the delete');
        $this->assertArrayHasKey($keep, $this->npm->hosts);

        $sync = $this->sync();
        $sync->applyPush([$deletes[0]['key'] => collect($sync->pushPlan())->firstWhere('key', $deletes[0]['key'])['fingerprint']]);
        $this->assertArrayNotHasKey($extra, $this->npm->hosts);
        $this->assertArrayHasKey($keep, $this->npm->hosts);
    }

    public function test_dns_records_labman_wrote_for_the_old_mirror_names_are_offered_for_update_in_the_adguard_preview(): void
    {
        // An earlier version moved the other zone's name to the LAN proxy in AdGuard, and wrote the mirror's name too.
        $url = 'http://adguard.test:3000';
        $rewrites = [['domain' => 'app.example.me', 'answer' => '192.0.2.5'], ['domain' => 'gone.example.dev', 'answer' => '192.0.2.5']];
        ManagedRewrite::create(['domain' => 'app.example.me', 'answer' => '192.0.2.5']);
        ManagedRewrite::create(['domain' => 'gone.example.dev', 'answer' => '192.0.2.5']);
        Http::swap(new \Illuminate\Http\Client\Factory); // this test needs no NPM; AdGuard is the only thing that answers
        Http::fake([$url.'/control/rewrite/list' => Http::response($rewrites)]);

        $plan = (new AdguardSync(new AdguardClient(['url' => $url, 'username' => '', 'password' => '', 'verify_tls' => true])))->plan();
        $byKey = collect($plan['items'])->keyBy('key');

        $update = $byKey['app.example.me|A'] ?? collect($plan['items'])->first(fn ($i) => str_contains($i['key'], 'app.example.me'));
        $this->assertSame('update', $update['action'], 'moved back to the zone that answers it');
        $this->assertContains('203.0.113.5', [$update['data']['answer'] ?? null, $update['data']['new'] ?? null, $update['data']['value'] ?? null]);

        $deletes = array_column(array_filter($plan['items'], fn ($i) => $i['action'] === 'delete'), 'key');
        $this->assertSame(['delete|gone.example.dev|192.0.2.5'], $deletes, 'a record nothing asks for any more is offered for deletion');
    }
}
