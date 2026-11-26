<?php

namespace Tests\Feature;

use App\Mcp\McpServer;
use App\Models\ApiToken;
use App\Models\Link;
use App\Models\ProxyLog;
use App\Models\Server;
use App\Models\Service;
use App\Models\Zone;
use App\Npm\NpmAuto;
use App\Support\Zones;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Everything an admin can do in the web UI has an MCP tool (apart from the deliberate exceptions listed below). */
class McpCoverageTest extends TestCase
{
    use RefreshDatabase;

    private string $adminToken;

    private string $readToken;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = $this->admin(['email' => 'root@example.com']);
        [, $this->adminToken] = ApiToken::issue($admin, 'test admin', 'admin');
        [, $this->readToken] = ApiToken::issue($admin, 'test read', 'read');
    }

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

    /**
     * What each area of the web UI is reachable as over MCP. A page with no entry here needs a tool or a reason
     * (see DELIBERATELY_NOT_OVER_MCP).
     */
    private const COVERAGE = [
        'servers' => ['list_servers', 'create_server', 'update_server', 'delete_server'],
        'services' => ['list_services', 'create_service', 'update_service', 'delete_service', 'diagnose_service'],
        'links' => ['list_links', 'create_link', 'update_link', 'delete_link'],
        'nodes' => ['node_list', 'node_save', 'node_push', 'node_remove'],
        'settings and access' => ['get_settings', 'update_settings'],
        'users' => ['list_users', 'create_user', 'update_user', 'approve_user'],
        'status and health' => ['get_status', 'run_health_check'],
        'import and export' => ['export_yaml', 'import_yaml'],
        'zones' => ['list_zones', 'create_zone', 'update_zone', 'delete_zone', 'assign_server_zone', 'set_service_zone', 'zone_for_address', 'zone_changes'],
        'mirrors' => ['list_mirrors', 'mirror_preview', 'forget_mirror'],
        'npm connection' => ['npm_get_connection', 'npm_set_connection', 'npm_test_connection', 'npm_remove_connection'],
        'npm sync' => ['npm_import_preview', 'npm_import_apply', 'npm_push_preview', 'npm_push_apply', 'npm_preview_all', 'npm_apply_all', 'npm_get_auto_update', 'npm_set_auto_update'],
        'npm certificates' => ['npm_list_certificates', 'npm_match_certificate'],
        'dns' => ['dns_plan', 'dns_set_owner'],
        'adguard' => ['adguard_get_connection', 'adguard_set_connection', 'adguard_test_connection', 'adguard_remove_connection', 'adguard_preview', 'adguard_apply'],
        'inventory' => ['inventory_get_connection', 'inventory_set_connection', 'inventory_remove_connection', 'inventory_compare', 'inventory_review', 'inventory_apply'],
        'semaphore' => ['semaphore_get_connection', 'semaphore_set_connection', 'semaphore_remove_connection', 'semaphore_list_projects', 'semaphore_list_templates', 'semaphore_run_template', 'semaphore_get_task', 'semaphore_list_tasks', 'semaphore_stop_task'],
        'semaphore host list' => ['semaphore_get_host_sync', 'semaphore_set_host_sync', 'semaphore_clear_host_sync', 'semaphore_sync_hosts', 'semaphore_list_unknown_hosts', 'semaphore_adopt_hosts'],
    ];

    /** Left out on purpose: an AI client must not hand itself access or widen what it can do. */
    private const DELIBERATELY_NOT_OVER_MCP = ['creating or revoking API tokens', 'dismissing admin notices', 'the zone setup wizard (a guided screen; zones have their own tools)', 'deleting users (the UI only disables them)'];

    public function test_every_covered_area_has_its_tools_and_they_are_admin_scoped_unless_read_only_data(): void
    {
        $registry = collect(McpServer::registry())->keyBy('name');

        foreach (self::COVERAGE as $area => $tools) {
            foreach ($tools as $name) {
                $this->assertTrue($registry->has($name), "{$area}: missing tool {$name}");
            }
        }

        $readScope = $registry->filter(fn ($t) => $t->scope === 'read')->keys()->sort()->values()->all();
        $this->assertSame(['export_yaml', 'get_settings', 'get_status', 'list_links', 'list_servers', 'list_services'], $readScope, 'read tokens see only the catalogue, never connections or writes');

        $this->assertNotEmpty(self::DELIBERATELY_NOT_OVER_MCP);
    }

    public function test_every_registered_tool_belongs_to_a_covered_area(): void
    {
        $covered = array_merge(...array_values(self::COVERAGE));
        $extra = array_diff(collect(McpServer::registry())->pluck('name')->all(), $covered);

        $this->assertSame([], array_values($extra), 'a new tool should be listed in COVERAGE so the matrix stays honest');
    }

    public function test_links_over_mcp(): void
    {
        $created = $this->ok('create_link', ['name' => 'Controller', 'url' => 'https://controller.example.com', 'group' => 'Network', 'on_dashboard' => false]);
        $this->assertFalse($created['link']['on_dashboard']);

        [$error] = $this->tool('create_link', ['name' => 'Bad', 'url' => 'javascript:alert(1)']);
        $this->assertTrue($error);
        [$error] = $this->tool('create_link', ['name' => 'Controller', 'url' => 'https://other.example.com']);
        $this->assertTrue($error, 'names are unique');

        $updated = $this->ok('update_link', ['name' => 'Controller', 'new_name' => 'Network controller', 'on_dashboard' => true]);
        $this->assertSame('Network controller', $updated['link']['name']);
        $this->assertSame('Network', $updated['link']['group'], 'omitted fields keep their value');
        $this->assertTrue($updated['link']['on_dashboard']);

        $this->assertCount(1, $this->ok('list_links')['links']);
        [$error, $out] = $this->tool('list_links', [], $this->readToken);
        $this->assertFalse($error, json_encode($out));

        $this->ok('delete_link', ['name' => 'Network controller']);
        $this->assertSame(0, Link::count());
        [$error] = $this->tool('delete_link', ['name' => 'Network controller']);
        $this->assertTrue($error);

        [$error] = $this->tool('create_link', ['name' => 'X', 'url' => 'https://x.example.com'], $this->readToken);
        $this->assertTrue($error, 'a read token cannot change links');
    }

    public function test_the_dashboard_switch_and_update_workbook_are_in_the_server_and_service_tools(): void
    {
        $server = $this->ok('create_server', ['name' => 'Nas', 'address' => '192.0.2.20', 'on_dashboard' => false]);
        $this->assertFalse($server['server']['on_dashboard']);
        $service = $this->ok('create_service', ['name' => 'Wiki', 'address' => '192.0.2.50', 'on_dashboard' => false, 'update_project' => 3, 'update_template' => 9]);
        $this->assertFalse($service['service']['on_dashboard']);
        $this->assertSame([3, 9], [$service['service']['update_project'], $service['service']['update_template']]);

        $again = $this->ok('update_service', ['name' => 'Wiki', 'on_dashboard' => true]);
        $this->assertTrue($again['service']['on_dashboard']);
        $this->assertSame(9, $again['service']['update_template'], 'omitted fields keep their value');
        $this->assertTrue(Service::where('name', 'Wiki')->first()->on_dashboard);
        $this->assertFalse(Server::where('name', 'Nas')->first()->on_dashboard);
    }

    public function test_npm_automatic_updates_can_be_read_and_switched(): void
    {
        NpmAuto::setEnabled(false);

        $this->assertFalse($this->ok('npm_get_auto_update')['enabled']);
        $on = $this->ok('npm_set_auto_update', ['enabled' => true]);
        $this->assertTrue($on['enabled']);
        $this->assertFalse($on['active'], 'the switch only acts once a proxy is connected');
        $this->assertFalse($on['connected']);

        \App\Npm\Connection::save('http://npm.test:81', 'npm@example.com', 'npm-secret', true);
        $this->assertTrue($this->ok('npm_get_auto_update')['active']);
        $this->assertFalse($this->ok('npm_set_auto_update', ['enabled' => false])['enabled']);
        $this->assertFalse(NpmAuto::enabled());
    }

    public function test_the_zone_change_log_can_be_read(): void
    {
        $zone = Zones::default();
        ProxyLog::record($zone, 'preview', [['title' => 'Wiki', 'outcome' => 'applied', 'message' => 'Proxy host created in NPM.']], 'MCP (test admin)');
        $other = Zone::create(['name' => 'Other', 'level' => 10]);

        $out = $this->ok('zone_changes', ['zone' => $zone->name]);
        $this->assertSame(1, count($out['changes']));
        $this->assertSame('MCP (test admin)', $out['changes'][0]['who']);
        $this->assertSame([], $this->ok('zone_changes', ['zone' => $other->name])['changes']);
    }

    public function test_the_semaphore_host_list_tools_work_without_a_connection_and_explain_themselves(): void
    {
        $this->assertNull($this->ok('semaphore_get_host_sync')['target']);
        $this->assertSame([], $this->ok('semaphore_list_unknown_hosts')['hosts']);
        $adopted = $this->ok('semaphore_adopt_hosts', ['add' => [['host' => 'ghost']]]);
        $this->assertSame([], $adopted['added']);
        $this->assertNotEmpty($adopted['problems']);

        [$error, $out] = $this->tool('semaphore_sync_hosts');
        $this->assertTrue($error);
        $this->assertNotSame('', trim((string) $out), 'the refusal explains itself');
        [$error] = $this->tool('semaphore_set_host_sync', ['project_id' => 1, 'inventory_id' => 2]);
        $this->assertTrue($error);

        $byName = collect(McpServer::registry())->keyBy('name');
        $this->assertTrue($byName['semaphore_list_unknown_hosts']->readOnly);
        $this->assertFalse($byName['semaphore_sync_hosts']->readOnly);
    }
}
