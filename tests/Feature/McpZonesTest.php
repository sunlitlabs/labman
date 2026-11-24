<?php

namespace Tests\Feature;

use App\Mcp\McpServer;
use App\Models\ApiToken;
use App\Models\Mirror;
use App\Models\ProxyLog;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Models\Zone;
use App\Npm\Connection;
use App\Npm\NpmAuto;
use App\Support\Settings;
use App\Support\Zones;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeNpm;
use Tests\TestCase;

/** Step 7 of zones: the MCP tools for zones, per-zone proxies, the grouped preview, certificates, mirrors and the DNS plan. */
class McpZonesTest extends TestCase
{
    use RefreshDatabase;

    private const URL_OUTER = 'http://npm-external.test:81';

    private string $adminToken;

    private string $readToken;

    private FakeNpm $inner;

    private FakeNpm $outer;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = $this->admin(['email' => 'root@example.com']);
        [, $this->adminToken] = ApiToken::issue($admin, 'test admin', 'admin');
        [, $this->readToken] = ApiToken::issue($admin, 'test read', 'read');
        $this->inner = FakeNpm::install();
        $this->outer = FakeNpm::install(self::URL_OUTER);
        NpmAuto::setEnabled(false);
        Settings::set(['local_domains' => 'home.example.com']);
    }

    // ------------------------------------------------------------------------- helpers

    private function tool(string $tool, array $args = [], ?string $token = null): array
    {
        $result = $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => (object) $args]], ['Authorization' => 'Bearer '.($token ?? $this->adminToken)])->assertOk()->json('result');

        return [$result['isError'], $result['structuredContent'] ?? $result['content'][0]['text']];
    }

    private function ok(string $tool, array $args = []): array
    {
        [$error, $out] = $this->tool($tool, $args);
        $this->assertFalse($error, is_string($out) ? $out : json_encode($out));

        return $out;
    }

    private function fails(string $tool, array $args = []): string
    {
        [$error, $out] = $this->tool($tool, $args);
        $this->assertTrue($error, 'expected '.$tool.' to fail');

        return is_string($out) ? $out : json_encode($out);
    }

    /** Two zones, each with a proxy and a service. */
    private function lab(): array
    {
        $internal = Zones::default();
        $internal->update(['name' => 'Internal', 'level' => 60, 'proxy_address' => '192.0.2.5']);
        $external = Zone::create(['name' => 'External', 'level' => 10, 'domains' => ['public' => ['example.dev']], 'proxy_address' => '203.0.113.5', 'dns' => ['public_address' => '203.0.113.10']]);
        Connection::save(FakeNpm::URL, 'npm@example.com', 'npm-secret', true);
        Connection::save(self::URL_OUTER, 'npm@example.com', 'npm-secret', true, $external);
        Service::create(['name' => 'Wiki', 'address' => '192.0.2.50', 'domain' => 'wiki.home.example.com', 'scheme' => 'http', 'path' => '/']);
        Service::create(['name' => 'Blog', 'address' => '198.51.100.7', 'zone_id' => $external->id, 'public_domain' => 'blog.example.dev', 'scheme' => 'http', 'path' => '/']);

        return [$internal, $external];
    }

    private function cert(FakeNpm $npm, int $id, array $domains, int $days = 90): void
    {
        $npm->certificates[] = ['id' => $id, 'nice_name' => 'Cert '.$id, 'provider' => 'other', 'domain_names' => $domains, 'expires_on' => gmdate('Y-m-d H:i:s', time() + $days * 86400), 'meta' => []];
    }

    // ------------------------------------------------------------------------ the tool list

    public function test_zone_tools_are_admin_only_and_honest_about_what_they_do(): void
    {
        $read = array_column($this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], ['Authorization' => 'Bearer '.$this->readToken])->json('result.tools'), 'name');
        $admin = array_column($this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], ['Authorization' => 'Bearer '.$this->adminToken])->json('result.tools'), 'name');

        $zoneTools = ['list_zones', 'create_zone', 'update_zone', 'delete_zone', 'assign_server_zone', 'set_service_zone', 'zone_for_address', 'npm_preview_all', 'npm_apply_all',
            'npm_list_certificates', 'npm_match_certificate', 'list_mirrors', 'mirror_preview', 'forget_mirror', 'dns_plan', 'dns_set_owner'];
        foreach ($zoneTools as $tool) {
            $this->assertContains($tool, $admin);
            $this->assertNotContains($tool, $read);
        }

        $byName = collect(McpServer::registry())->keyBy('name');
        foreach (['list_zones', 'zone_for_address', 'npm_preview_all', 'npm_list_certificates', 'npm_match_certificate', 'list_mirrors', 'mirror_preview', 'dns_plan'] as $readOnly) {
            $this->assertTrue($byName[$readOnly]->readOnly, $readOnly);
        }
        foreach (['npm_apply_all', 'npm_push_apply', 'delete_zone'] as $writes) {
            $this->assertTrue($byName[$writes]->destructive, $writes);
        }
        $this->assertSame([], array_values(array_filter($admin, fn ($n) => str_contains($n, 'token'))), 'tokens still cannot manage tokens');
    }

    // ------------------------------------------------------------------------------ zones

    public function test_list_zones_describes_each_zone_without_any_secret(): void
    {
        $this->lab();
        $out = $this->ok('list_zones');

        $this->assertSame(['Internal', 'External'], array_column($out['zones'], 'name'), 'most trusted first');
        $internal = $out['zones'][0];
        $this->assertTrue($internal['default']);
        $this->assertSame(['local' => ['home.example.com'], 'public' => []], $internal['domains']);
        $this->assertSame(1, $internal['services']);
        $this->assertTrue($internal['proxy']['connected']);
        $this->assertSame('203.0.113.10', $out['zones'][1]['dns']['public_address']);
        $this->assertStringNotContainsString('npm-secret', json_encode($out));
        $this->assertStringNotContainsString('password', json_encode($out));
        $this->assertStringContainsString('update_zone', $out['next']);
    }

    public function test_a_single_zone_install_is_told_how_to_add_more(): void
    {
        $out = $this->ok('list_zones');

        $this->assertCount(1, $out['zones']);
        $this->assertStringContainsString('create_zone', $out['next']);
    }

    public function test_a_zone_is_created_changed_and_deleted_with_the_screens_rules(): void
    {
        $created = $this->ok('create_zone', [
            'name' => 'LAN', 'level' => 40, 'color' => '#2557D6', 'cidrs' => ['192.0.2.0/24'], 'local_domains' => ['lan.example.com'], 'proxy_address' => '192.0.2.6',
            'certificates' => ['strategy' => 'letsencrypt_http', 'letsencrypt_email' => 'admin@example.com'], 'dns' => ['split_horizon' => true],
        ])['zone'];

        $this->assertSame('LAN', $created['name']);
        $this->assertSame('#2557d6', $created['color']);
        $this->assertSame(['192.0.2.0/24'], $created['cidrs']);
        $this->assertSame(['local' => ['lan.example.com'], 'public' => []], $created['domains']);
        $this->assertSame('letsencrypt_http', $created['certificates']['strategy']);
        $this->assertTrue($created['dns']['split_horizon']);
        $this->assertStringContainsString('assign_server_zone', $this->ok('create_zone', ['name' => 'Other'])['next']);

        // Only what is given changes.
        $updated = $this->ok('update_zone', ['zone' => 'lan', 'level' => 45, 'certificates' => ['wildcard' => true], 'public_domains' => ['example.dev']])['zone'];
        $this->assertSame(45, $updated['level']);
        $this->assertSame('letsencrypt_http', $updated['certificates']['strategy'], 'the policy is merged, not replaced');
        $this->assertTrue($updated['certificates']['wildcard']);
        $this->assertSame(['local' => ['lan.example.com'], 'public' => ['example.dev']], $updated['domains']);
        $this->assertSame('192.0.2.6', $updated['proxy_address']);

        $this->assertSame('Other', $this->ok('delete_zone', ['zone' => 'Other'])['deleted']);
        $this->assertNull(Zone::where('name', 'Other')->first());
    }

    public function test_bad_zone_input_is_refused_with_a_reason_and_nothing_changes(): void
    {
        $this->ok('create_zone', ['name' => 'LAN']);

        $this->assertStringContainsString('level', $this->fails('create_zone', ['name' => 'X', 'level' => 500]));
        $this->assertStringContainsString('network block', $this->fails('create_zone', ['name' => 'X', 'cidrs' => ['192.0.2.0/40']]));
        $this->assertStringContainsString('name', $this->fails('create_zone', ['name' => 'lan']));
        $this->assertStringContainsString('cannot request', $this->fails('update_zone', ['zone' => 'LAN', 'certificates' => ['strategy' => 'letsencrypt_http', 'edge_managed' => true]]));
        $this->assertStringContainsString('Unknown certificate setting', $this->fails('update_zone', ['zone' => 'LAN', 'certificates' => ['bogus' => 1]]));
        $this->assertStringContainsString('There is no zone', $this->fails('update_zone', ['zone' => 'Nowhere']));
        $this->assertStringContainsString('There is no zone', $this->fails('update_zone', ['zone' => 'LAN', 'mirrors' => [['zone' => 'Nowhere']]]));
        $this->assertStringContainsString('upstream', $this->fails('update_zone', ['zone' => 'LAN', 'mirrors' => [['zone' => 'Default', 'upstream' => 'carrier-pigeon']]]));
        $stored = Zone::where('name', 'LAN')->first();
        $this->assertSame('existing', $stored->certificatePolicy()->strategy, 'a refused change leaves the zone as it was');
        $this->assertSame(50, $stored->level);
        $this->assertSame(2, Zone::count());
    }

    public function test_the_default_zone_and_a_zone_with_servers_cannot_be_deleted(): void
    {
        $this->ok('create_zone', ['name' => 'LAN']);
        Server::create(['name' => 'web1', 'address' => '192.0.2.11', 'zone_id' => Zone::where('name', 'LAN')->value('id')]);

        $this->assertStringContainsString('default zone cannot be deleted', $this->fails('delete_zone', ['zone' => 'Default']));
        $this->assertStringContainsString('still has 1 server', $this->fails('delete_zone', ['zone' => 'LAN']));
        $this->assertSame(2, Zone::count());
    }

    public function test_making_a_zone_the_default_swaps_the_domains_instead_of_losing_them(): void
    {
        $this->ok('create_zone', ['name' => 'Core', 'local_domains' => ['core.example.com']]);

        $this->ok('update_zone', ['zone' => 'Core', 'is_default' => true]);

        $this->assertSame('Core', Zones::default()->name);
        $this->assertSame('core.example.com', Settings::get('local_domains'));
        $this->assertSame(['home.example.com'], Zone::where('name', 'Default')->first()->domains()['local']);
    }

    public function test_mirror_rules_are_set_by_zone_name(): void
    {
        [$internal, $external] = $this->lab();

        $zone = $this->ok('update_zone', ['zone' => 'Internal', 'mirrors' => [['zone' => 'External', 'upstream' => 'https']]])['zone'];

        $this->assertSame([['zone' => 'External', 'upstream' => 'https']], $zone['mirrors']);
        $this->assertSame([], $this->ok('update_zone', ['zone' => 'Internal', 'mirrors' => []])['zone']['mirrors']);
    }

    // ------------------------------------------------------------------ servers and services

    public function test_servers_and_services_are_placed_by_name(): void
    {
        [$internal, $external] = $this->lab();
        $external->update(['cidrs' => ['198.51.100.0/24']]);
        $server = Server::create(['name' => 'edge', 'address' => '198.51.100.7']);

        $this->assertSame('External', $this->ok('zone_for_address', ['address' => '198.51.100.7'])['zone']);
        $this->assertNull($this->ok('zone_for_address', ['address' => '192.0.2.1'])['zone']);

        $out = $this->ok('assign_server_zone', ['server' => 'edge', 'zone' => 'external']);
        $this->assertSame($external->id, $server->fresh()->zone_id);
        $this->assertSame('External', $out['zone']);
        $this->assertStringContainsString('There is no server', $this->fails('assign_server_zone', ['server' => 'ghost', 'zone' => 'External']));
        $this->assertStringContainsString('There is no zone', $this->fails('assign_server_zone', ['server' => 'edge', 'zone' => 'Mars']));

        $svc = $this->ok('set_service_zone', ['service' => 'Wiki', 'zone' => 'External', 'certificate' => 'letsencrypt_dns', 'publish_in' => [['zone' => 'Internal', 'upstream' => 'http']]]);
        $this->assertSame('External', $svc['zone']);
        $this->assertTrue($svc['zone_set_on_service']);
        $this->assertSame('letsencrypt_dns', $svc['certificate']);
        $this->assertSame([['zone' => 'Internal', 'upstream' => 'http']], $svc['publish_in']);

        $back = $this->ok('set_service_zone', ['service' => 'Wiki', 'zone' => '', 'certificate' => 'auto', 'publish_in' => []]);
        $this->assertFalse($back['zone_set_on_service']);
        $this->assertSame('auto', $back['certificate']);
        $this->assertSame([], $back['publish_in']);
        $this->assertStringContainsString('certificate must be', $this->fails('set_service_zone', ['service' => 'Wiki', 'certificate' => 'sometimes']));
    }

    // ----------------------------------------------------------------- per-zone NPM tools

    public function test_the_npm_tools_take_a_zone_and_default_to_the_default_zone_with_a_hint(): void
    {
        $external = Zone::create(['name' => 'External', 'level' => 10, 'domains' => ['public' => ['example.dev']]]);

        // With two zones, a call without a zone acts on the default zone and says so.
        $set = $this->ok('npm_set_connection', ['url' => FakeNpm::URL, 'email' => 'npm@example.com', 'password' => 'npm-secret']);
        $this->assertSame('Default', $set['zone']);
        $this->assertStringContainsString('2 zones', $set['hint']);
        $this->assertStringContainsString('npm_preview_all', $set['hint']);
        $this->assertNotNull(Connection::get());
        $this->assertNull(Connection::get($external));

        // With a zone it acts on that one and needs no hint.
        $outer = $this->ok('npm_set_connection', ['zone' => 'External', 'url' => self::URL_OUTER, 'email' => 'npm@example.com', 'password' => 'npm-secret']);
        $this->assertSame('External', $outer['zone']);
        $this->assertArrayNotHasKey('hint', $outer);
        $this->assertSame(self::URL_OUTER, Connection::get($external)['url']);

        $this->assertSame(self::URL_OUTER, $this->ok('npm_get_connection', ['zone' => 'External'])['connection']['url']);
        $this->assertStringNotContainsString('npm-secret', json_encode($this->ok('npm_get_connection', ['zone' => 'External'])));
        $this->assertSame('2.17.0', $this->ok('npm_test_connection', ['zone' => 'External'])['npm_version']);
        $this->assertFalse($this->ok('npm_remove_connection', ['zone' => 'External'])['connected']);
        $this->assertNotNull(Connection::get(), "the other zone's login is untouched");

        $this->assertStringContainsString('There is no zone', $this->fails('npm_get_connection', ['zone' => 'Mars']));
    }

    public function test_a_single_zone_install_gets_no_hint_and_the_old_calls_work_unchanged(): void
    {
        $this->ok('npm_set_connection', ['url' => FakeNpm::URL, 'email' => 'npm@example.com', 'password' => 'npm-secret']);

        $out = $this->ok('npm_push_preview');

        $this->assertArrayNotHasKey('hint', $out);
        $this->assertSame('Default', $out['zone']);
        $this->assertSame([], $out['items']);
        $this->assertTrue($this->ok('npm_get_connection')['connected']);
    }

    public function test_previews_are_scoped_to_the_zone_and_say_in_words_what_they_would_do(): void
    {
        $this->lab();

        $in = $this->ok('npm_push_preview');
        $out = $this->ok('npm_push_preview', ['zone' => 'External']);

        $this->assertStringContainsString('2 zones', $in['hint']);
        $this->assertCount(1, $in['items']);
        $this->assertSame('Wiki → wiki.home.example.com', $in['items'][0]['title']);
        $this->assertStringContainsString('Add wiki.home.example.com to the Internal proxy, forwarding to http://192.0.2.50:80', $in['items'][0]['summary']);
        $this->assertSame('Blog → blog.example.dev', $out['items'][0]['title']);
        $this->assertStringContainsString('Show the user', $in['next']);
    }

    public function test_applying_to_a_zone_writes_only_there_and_is_logged_as_the_token(): void
    {
        $this->lab();
        $item = $this->ok('npm_push_preview', ['zone' => 'External'])['items'][0];

        $out = $this->ok('npm_push_apply', ['zone' => 'External', 'items' => [['key' => $item['key'], 'fingerprint' => $item['fingerprint']]]]);

        $this->assertSame(1, $out['applied']);
        $this->assertCount(1, $this->outer->hosts);
        $this->assertSame([], $this->inner->hosts);
        $log = ProxyLog::first();
        $this->assertSame('MCP (test admin)', $log->who);
        $this->assertSame(Zone::where('name', 'External')->value('id'), $log->zone_id);
    }

    public function test_the_grouped_preview_and_apply_work_across_proxies_and_one_failing_proxy_does_not_stop_the_rest(): void
    {
        $this->lab();

        $preview = $this->ok('npm_preview_all');
        $this->assertSame(['Internal', 'External'], array_column($preview['sections'], 'zone'));
        $this->assertCount(1, $preview['sections'][0]['items']);
        $this->assertStringContainsString('npm_apply_all', $preview['next']);

        $items = [];
        foreach ($preview['sections'] as $s) {
            $items[] = ['zone' => $s['zone'], 'key' => $s['items'][0]['key'], 'fingerprint' => $s['items'][0]['fingerprint']];
        }

        $this->outer->password = 'changed-elsewhere';
        $this->assertStringContainsString('rejected the login', $this->ok('npm_preview_all')['sections'][1]['error']);

        $apply = $this->ok('npm_apply_all', ['items' => $items]);

        $this->assertSame(1, $apply['zones'][0]['applied']);
        $this->assertArrayHasKey('error', $apply['zones'][1]);
        $this->assertCount(1, $this->inner->hosts);
        $this->assertSame([], $this->outer->hosts);

        $this->assertStringContainsString('Each item needs a zone', $this->fails('npm_apply_all', ['items' => [['key' => 'x', 'fingerprint' => 'y']]]));
        $this->assertStringContainsString('Select at least one', $this->fails('npm_apply_all', ['items' => []]));
    }

    public function test_the_grouped_preview_needs_at_least_one_connected_proxy(): void
    {
        $this->assertStringContainsString('No zone has', $this->fails('npm_preview_all'));
    }

    public function test_a_stale_item_is_refused_for_its_zone_only(): void
    {
        $this->lab();
        $sections = $this->ok('npm_preview_all')['sections'];
        $items = array_map(fn ($s) => ['zone' => $s['zone'], 'key' => $s['items'][0]['key'], 'fingerprint' => $s['items'][0]['fingerprint']], $sections);
        Service::where('name', 'Blog')->update(['port' => 9000]); // the External plan changes after it was shown

        $apply = $this->ok('npm_apply_all', ['items' => $items]);

        $this->assertSame(1, $apply['zones'][0]['applied']);
        $this->assertStringContainsString('changed since the preview', $apply['zones'][1]['error']);
        $this->assertSame([], $this->outer->hosts);
    }

    // ------------------------------------------------------------------------ certificates

    public function test_certificates_are_listed_with_where_each_host_stands_and_a_name_can_be_matched(): void
    {
        [$internal] = $this->lab();
        $this->cert($this->inner, 1, ['*.home.example.com']);
        $this->cert($this->inner, 2, ['old.home.example.com'], -3);
        $this->inner->addHost(['domain_names' => ['wiki.home.example.com'], 'forward_host' => '192.0.2.50', 'forward_port' => 80, 'certificate_id' => 1]);
        Service::where('name', 'Wiki')->update(['npm_host_id' => 1]);
        $internal->update(['certificate_policy' => ['origin_certificates' => [2]]]);

        $out = $this->ok('npm_list_certificates');

        $this->assertSame('Internal', $out['zone']);
        $this->assertSame([1, 2], array_column($out['certificates'], 'id'));
        $this->assertSame(['*.home.example.com'], $out['certificates'][0]['domains']);
        $this->assertGreaterThan(80, $out['certificates'][0]['days_left']);
        $this->assertLessThan(0, $out['certificates'][1]['days_left']);
        $this->assertTrue($out['certificates'][1]['origin']);
        $this->assertSame('ok', $out['hosts'][0]['state']);
        $this->assertSame(1, $out['counts']['ok']);
        $this->assertStringContainsString('2 zones', $out['hint']);

        $match = $this->ok('npm_match_certificate', ['host' => 'new.home.example.com']);
        $this->assertSame('use', $match['mode']);
        $this->assertSame(1, $match['certificate_id']);
        $this->assertStringContainsString('already covers', $match['why']);

        $none = $this->ok('npm_match_certificate', ['zone' => 'External', 'host' => 'blog.example.dev']);
        $this->assertSame('missing', $none['mode']);
        $this->assertStringContainsString('No certificate in this proxy covers blog.example.dev', $none['warnings'][0]);
        $this->assertSame([], $this->inner->certificateRequests, 'matching requests nothing');
    }

    // --------------------------------------------------------------------------- mirrors

    public function test_mirrors_are_listed_previewed_and_orphans_forgotten_without_touching_npm(): void
    {
        [$internal, $external] = $this->lab();
        $this->cert($this->inner, 1, ['*.example.dev']);
        $this->assertSame([], $this->ok('list_mirrors')['zones']);
        $this->assertStringContainsString('update_zone', $this->ok('list_mirrors')['next']);

        $this->ok('update_zone', ['zone' => 'Internal', 'mirrors' => [['zone' => 'External']]]);
        $list = $this->ok('list_mirrors');
        $this->assertSame('Internal', $list['zones'][0]['zone']);
        $this->assertSame('blog.example.dev', $list['zones'][0]['mirrors'][0]['domain']);
        $this->assertFalse($list['zones'][0]['mirrors'][0]['created']);

        $preview = $this->ok('mirror_preview', ['zone' => 'Internal']);
        $this->assertCount(1, $preview['items']);
        $item = $preview['items'][0];
        $this->assertSame('create', $item['action']);
        $this->assertStringContainsString('as a mirror, forwarding to', $item['summary']);

        $this->ok('npm_push_apply', ['zone' => 'Internal', 'items' => [['key' => $item['key'], 'fingerprint' => $item['fingerprint']]]]);
        $this->assertTrue($this->ok('list_mirrors')['zones'][0]['mirrors'][0]['created']);

        // Switch the rule off: the mirror is orphaned, listed, and forgetting it deletes nothing in NPM.
        $this->ok('update_zone', ['zone' => 'Internal', 'mirrors' => []]);
        $orphan = $this->ok('list_mirrors')['zones'][0]['orphans'][0];
        $this->assertSame('blog.example.dev', $orphan['domain']);
        $this->assertCount(1, $this->inner->hosts);

        $this->ok('forget_mirror', ['id' => $orphan['id']]);
        $this->assertSame(0, Mirror::count());
        $this->assertCount(1, $this->inner->hosts);
        $this->assertNotContains('DELETE', $this->inner->methods());
        $this->assertStringContainsString('no mirror', strtolower($this->fails('forget_mirror', ['id' => 999])));
    }

    // ------------------------------------------------------------------------------- DNS

    public function test_the_dns_plan_comes_back_as_records_or_as_an_export_and_collisions_can_be_settled(): void
    {
        $this->lab();
        Server::create(['name' => 'nas', 'address' => '192.0.2.10', 'domain' => 'nas.home.example.com']);

        $plan = $this->ok('dns_plan');
        $names = array_column($plan['records'], 'name');
        $this->assertContains('*.home.example.com', $names);
        $this->assertContains('nas.home.example.com', $names);
        $this->assertSame(0, $plan['open_conflicts']);
        $this->assertStringContainsString('does not write DNS', $plan['next']);

        $hosts = $this->ok('dns_plan', ['format' => 'hosts']);
        $this->assertSame('hosts', $hosts['format']);
        $this->assertStringContainsString('192.0.2.10 nas.home.example.com', $hosts['text']);
        $this->assertStringContainsString('dnsrewrite', $this->ok('dns_plan', ['format' => 'adguard'])['text']);

        // The same domain in two zones: no record until an owner is picked.
        $lab = Zone::create(['name' => 'Lab', 'level' => 30, 'domains' => ['local' => ['home.example.com']], 'proxy_address' => '198.51.100.5']);
        $open = $this->ok('dns_plan');
        $this->assertSame(1, $open['open_conflicts']);
        $this->assertStringContainsString('dns_set_owner', $open['next']);
        $this->assertNotContains('*.home.example.com', array_column($open['records'], 'name'));

        $this->assertSame('Lab', $this->ok('dns_set_owner', ['kind' => 'domain', 'key' => 'home.example.com', 'zone' => 'Lab'])['owner']);
        $settled = $this->ok('dns_plan');
        $this->assertSame(0, $settled['open_conflicts']);
        $this->assertSame('198.51.100.5', collect($settled['records'])->firstWhere('name', '*.home.example.com')['value']);
    }

    public function test_a_read_token_cannot_call_any_zone_tool(): void
    {
        foreach (['list_zones', 'create_zone', 'dns_plan', 'npm_preview_all'] as $tool) {
            [$error] = $this->tool($tool, ['name' => 'X'], $this->readToken);
            $this->assertTrue($error, $tool);
        }
        $this->assertSame(1, Zone::count());
    }
}
