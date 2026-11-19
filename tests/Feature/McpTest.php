<?php

namespace Tests\Feature;

use App\Mcp\McpServer;
use App\Models\ApiToken;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class McpTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $adminToken;

    private string $readToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->admin(['email' => 'root@example.com']);
        [, $this->adminToken] = ApiToken::issue($this->admin, 'test admin', 'admin');
        [, $this->readToken] = ApiToken::issue($this->admin, 'test read', 'read');
    }

    // ------------------------------------------------------------------ helpers

    private function rpc(string $method, array $params = [], ?string $token = null, int $id = 1)
    {
        $headers = $token === null ? [] : ['Authorization' => 'Bearer '.$token];

        return $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params], $headers);
    }

    /** Call a tool and return [isError, structured-or-text]. */
    private function tool(string $tool, array $args = [], ?string $token = null): array
    {
        $result = $this->rpc('tools/call', ['name' => $tool, 'arguments' => (object) $args], $token ?? $this->adminToken)->assertOk()->json('result');

        return [$result['isError'], $result['structuredContent'] ?? $result['content'][0]['text']];
    }

    private function ok(string $tool, array $args = []): array
    {
        [$error, $out] = $this->tool($tool, $args);
        $this->assertFalse($error, is_string($out) ? $out : json_encode($out));

        return $out;
    }

    private function fails(string $tool, array $args = [], ?string $token = null): string
    {
        [$error, $out] = $this->tool($tool, $args, $token);
        $this->assertTrue($error, 'expected a tool error');

        return $out;
    }

    // ----------------------------------------------------------- authentication

    public function test_requests_without_a_valid_token_are_refused(): void
    {
        $this->rpc('ping')->assertUnauthorized()->assertHeader('WWW-Authenticate', 'Bearer realm="labman"');
        $this->rpc('ping', [], 'lm_not-a-real-token')->assertUnauthorized();
        $this->rpc('ping', [], $this->adminToken)->assertOk();
    }

    public function test_a_revoked_token_stops_working(): void
    {
        ApiToken::findByPlain($this->adminToken)->delete();

        $this->rpc('ping', [], $this->adminToken)->assertUnauthorized();
    }

    public function test_an_admin_token_dies_when_its_owner_is_demoted_or_disabled(): void
    {
        $other = $this->admin(['email' => 'second@example.com']);
        [, $plain] = ApiToken::issue($other, 'second', 'admin');
        $this->rpc('ping', [], $plain)->assertOk();

        $other->role = 'user';
        $other->save();
        $this->rpc('ping', [], $plain)->assertUnauthorized();

        $other->role = 'admin';
        $other->status = 'disabled';
        $other->save();
        $this->rpc('ping', [], $plain)->assertUnauthorized();
    }

    public function test_only_the_hash_is_stored(): void
    {
        $row = DB::table('api_tokens')->where('name', 'test admin')->first();

        $this->assertNotSame($this->adminToken, $row->token_hash);
        $this->assertSame(hash('sha256', $this->adminToken), $row->token_hash);
        $this->assertStringStartsWith('lm_', $this->adminToken);
        $this->assertSame(substr($this->adminToken, 0, 8), $row->token_prefix);
    }

    public function test_mcp_can_be_switched_off(): void
    {
        config(['labman.mcp.enabled' => false]);

        $this->rpc('ping', [], $this->adminToken)->assertNotFound();
    }

    public function test_foreign_browser_origins_are_refused(): void
    {
        $headers = ['Authorization' => 'Bearer '.$this->adminToken];
        $body = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'];

        $this->postJson('/mcp', $body, $headers + ['Origin' => 'https://evil.example.net'])->assertForbidden();
        $this->postJson('/mcp', $body, $headers + ['Origin' => 'http://localhost'])->assertOk();

        config(['labman.mcp.allowed_origins' => 'https://client.example.org']);
        $this->postJson('/mcp', $body, $headers + ['Origin' => 'https://client.example.org'])->assertOk();
    }

    public function test_get_and_delete_are_not_supported(): void
    {
        $this->getJson('/mcp', ['Authorization' => 'Bearer '.$this->adminToken])->assertStatus(405)->assertHeader('Allow', 'POST');
        $this->deleteJson('/mcp', [], ['Authorization' => 'Bearer '.$this->adminToken])->assertStatus(405);
        $this->getJson('/mcp')->assertUnauthorized();
    }

    public function test_the_endpoint_ignores_cookies_and_sessions(): void
    {
        // A signed-in browser session is not a credential for /mcp.
        $this->actingAs($this->admin)->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->assertUnauthorized();
    }

    // ----------------------------------------------------------------- protocol

    public function test_initialize_negotiates_the_protocol_version(): void
    {
        $r = $this->rpc('initialize', ['protocolVersion' => '2025-03-26', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'x', 'version' => '1']], $this->adminToken)
            ->assertOk()->assertJsonPath('result.serverInfo.name', 'labman')->assertJsonPath('result.protocolVersion', '2025-03-26');

        $this->assertArrayHasKey('tools', $r->json('result.capabilities'));
        $this->assertNotEmpty($r->json('result.instructions'));

        $this->rpc('initialize', ['protocolVersion' => '1999-01-01'], $this->adminToken)->assertJsonPath('result.protocolVersion', McpServer::SUPPORTED_VERSIONS[0]);
    }

    public function test_notifications_get_202_and_ping_works(): void
    {
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], ['Authorization' => 'Bearer '.$this->adminToken])
            ->assertStatus(202);

        $this->rpc('ping', [], $this->adminToken)->assertOk()->assertJsonPath('id', 1);
    }

    public function test_protocol_errors(): void
    {
        $this->rpc('nope/nothing', [], $this->adminToken)->assertOk()->assertJsonPath('error.code', -32601);
        $this->rpc('tools/call', ['name' => 'no_such_tool'], $this->adminToken)->assertJsonPath('error.code', -32602);

        $this->postJson('/mcp', ['hello' => 'world'], ['Authorization' => 'Bearer '.$this->adminToken])->assertJsonPath('error.code', -32600);


        $this->call('POST', '/mcp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->adminToken], '{not json')
            ->assertStatus(400)->assertJsonPath('error.code', -32700);
    }

    public function test_batches_are_answered_in_one_array(): void
    {
        $r = $this->postJson('/mcp', [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
        ], ['Authorization' => 'Bearer '.$this->adminToken])->assertOk();

        $this->assertCount(2, $r->json());
        $this->assertSame([1, 2], array_column($r->json(), 'id'));
    }

    public function test_tool_lists_depend_on_the_token_scope(): void
    {
        $read = array_column($this->rpc('tools/list', [], $this->readToken)->assertOk()->json('result.tools'), 'name');
        $admin = array_column($this->rpc('tools/list', [], $this->adminToken)->assertOk()->json('result.tools'), 'name');

        $this->assertEqualsCanonicalizing(['list_services', 'list_servers', 'get_status', 'get_settings', 'export_yaml'], $read);
        $this->assertGreaterThan(count($read), count($admin));
        foreach (['create_server', 'update_server', 'delete_server', 'create_service', 'update_service', 'delete_service', 'update_settings',
            'import_yaml', 'run_health_check', 'list_users', 'create_user', 'update_user', 'approve_user',
            'npm_set_connection', 'npm_import_preview', 'npm_import_apply', 'npm_push_preview', 'npm_push_apply'] as $tool) {
            $this->assertContains($tool, $admin);
        }
    }

    public function test_every_tool_has_a_valid_schema_and_honest_annotations(): void
    {
        $tools = $this->rpc('tools/list', [], $this->adminToken)->json('result.tools');

        foreach ($tools as $tool) {
            $this->assertSame('object', $tool['inputSchema']['type'], $tool['name']);
            $this->assertFalse($tool['inputSchema']['additionalProperties']);
            $this->assertNotEmpty($tool['description']);
            $this->assertArrayHasKey('readOnlyHint', $tool['annotations']);
        }

        // Anything a read token may call must be read-only; anything destructive must not claim to be read-only.
        foreach (McpServer::registry() as $tool) {
            if ($tool->scope === 'read') {
                $this->assertTrue($tool->readOnly, $tool->name);
            }
            $this->assertFalse($tool->readOnly && $tool->destructive, $tool->name);
        }
    }

    public function test_health_options_and_icons_can_be_set_and_read_back(): void
    {
        $service = $this->ok('create_service', [
            'name' => 'Wiki', 'address' => '192.0.2.5', 'health_path' => '/health', 'monitor' => false, 'check_tcp' => false, 'icon' => 'Wikijs', 'expect' => '200-399,401',
        ])['service'];

        $this->assertSame('/health', $service['health_path']);
        $this->assertFalse($service['monitor']);
        $this->assertFalse($service['check_tcp']);
        $this->assertSame('wikijs', $service['icon']);
        $this->assertSame('off', $service['state']);
        $this->assertSame('200-399,401', $service['expect']);

        // Omitted flags keep their value on update.
        $this->assertFalse($this->ok('update_service', ['name' => 'Wiki', 'notes' => 'x'])['service']['monitor']);
        $this->assertTrue($this->ok('update_service', ['name' => 'Wiki', 'monitor' => true])['service']['monitor']);

        $server = $this->ok('create_server', ['name' => 'nas', 'address' => '192.0.2.9', 'monitor' => false, 'check_ssh' => false, 'icon' => 'proxmox'])['server'];
        $this->assertFalse($server['monitor']);
        $this->assertFalse($server['check_ssh']);
        $this->assertSame('off', $server['state']);

        $this->assertStringContainsString('health path', $this->fails('create_service', ['name' => 'Bad', 'address' => '192.0.2.6', 'health_path' => 'nope']));
    }

    public function test_diagnose_service_explains_and_changes_nothing_by_default(): void
    {
        $this->ok('create_service', ['name' => 'Dead', 'address' => '127.0.0.1', 'port' => 1]);

        $result = $this->ok('diagnose_service', ['name' => 'Dead']);

        $this->assertSame('Dead', $result['service']);
        $this->assertSame('refused', $result['diagnosis']['kind']);
        $this->assertArrayNotHasKey('applied', $result['diagnosis']);
    }

    public function test_tokens_cannot_manage_tokens(): void
    {
        $names = array_column($this->rpc('tools/list', [], $this->adminToken)->json('result.tools'), 'name');

        $this->assertSame([], array_values(array_filter($names, fn ($n) => str_contains($n, 'token'))));
    }

    public function test_a_read_token_cannot_call_write_tools(): void
    {
        $message = $this->fails('create_server', ['name' => 'x', 'address' => '192.0.2.1'], $this->readToken);

        $this->assertStringContainsString("'read' scope", $message);
        $this->assertSame(0, Server::count());
        $this->fails('update_settings', ['title' => 'Hacked'], $this->readToken);
        $this->assertNotSame('Hacked', Settings::title());
    }

    public function test_unknown_and_missing_arguments_are_reported(): void
    {
        $this->assertStringContainsString('Unknown argument', $this->fails('create_server', ['name' => 'x', 'address' => '192.0.2.1', 'bogus' => 1]));
        $this->assertStringContainsString('Missing required', $this->fails('create_server', ['name' => 'x']));
    }

    // -------------------------------------------------------------- read tools

    public function test_read_tools_describe_the_catalog(): void
    {
        $nas = Server::create(['name' => 'nas', 'address' => '192.0.2.10', 'group' => 'Storage', 'ssh_user' => 'deploy', 'ssh_port' => 2222]);
        Server::create(['name' => 'docker-1', 'address' => '192.0.2.20', 'group' => 'Compute']);
        Service::create(['name' => 'Wiki', 'domain' => 'wiki.example.com', 'server_id' => $nas->id, 'port' => 3000, 'scheme' => 'http', 'path' => '/', 'group' => 'Apps']);

        $servers = $this->ok('list_servers')['servers'];
        $this->assertCount(2, $servers);
        $this->assertSame('ssh -p 2222 deploy@192.0.2.10', collect($servers)->firstWhere('name', 'nas')['ssh_command']);
        $this->assertCount(1, $this->ok('list_servers', ['group' => 'Compute'])['servers']);
        $this->assertCount(0, $this->ok('list_servers', ['state' => 'up'])['servers']);

        $services = $this->ok('list_services')['services'];
        $this->assertSame('https://wiki.example.com/', $services[0]['open_url']);
        $this->assertSame('http://192.0.2.10:3000/', $services[0]['backend_url']);
        $this->assertSame('nas', $services[0]['server']);

        $status = $this->ok('get_status');
        $this->assertSame(['up' => 0, 'degraded' => 0, 'down' => 0, 'unknown' => 2], $status['summary']['servers']);

        $this->assertSame('LabMan', $this->ok('get_settings')['settings']['title']);
        $this->assertStringContainsString('name: nas', $this->ok('export_yaml')['yaml']);
    }

    public function test_read_tokens_can_call_read_tools(): void
    {
        [$error] = $this->tool('get_status', [], $this->readToken);
        $this->assertFalse($error);
    }

    // ------------------------------------------------------------ server tools

    public function test_server_crud(): void
    {
        $created = $this->ok('create_server', ['name' => 'nas', 'address' => '192.0.2.10', 'ports' => [445], 'ssh_user' => 'deploy'])['server'];
        $this->assertSame([445], $created['ports']);
        $this->assertSame('ssh deploy@192.0.2.10', $created['ssh_command']);

        $updated = $this->ok('update_server', ['name' => 'nas', 'new_name' => 'nas-1', 'address' => '192.0.2.11'])['server'];
        $this->assertSame('nas-1', $updated['name']);
        $this->assertSame('192.0.2.11', $updated['address']);
        $this->assertSame([445], $updated['ports'], 'omitted fields keep their value');
        $this->assertSame('deploy', $updated['ssh_user']);

        $this->ok('update_server', ['id' => $created['id'], 'domain' => 'nas.example.com']);
        $this->assertSame('nas.example.com', Server::first()->domain);

        $this->assertSame(['deleted' => 'nas-1'], $this->ok('delete_server', ['id' => $created['id']]));
        $this->assertSame(0, Server::count());
    }

    public function test_server_validation_matches_the_admin_form(): void
    {
        foreach ([['address' => '192.0.2.1; reboot'], ['ssh_user' => '-oProxyCommand=x'], ['ssh_port' => 70000], ['ports' => [99999]], ['domain' => 'not a domain']] as $bad) {
            $message = $this->fails('create_server', $bad + ['name' => 'x', 'address' => '192.0.2.1']);
            $this->assertStringContainsString('Validation failed', $message);
        }

        $this->ok('create_server', ['name' => 'dup', 'address' => '192.0.2.1']);
        $this->assertStringContainsString('already been taken', $this->fails('create_server', ['name' => 'dup', 'address' => '192.0.2.2']));
        $this->assertSame(1, Server::count());
        $this->assertStringContainsString('Server not found', $this->fails('update_server', ['name' => 'ghost', 'notes' => 'x']));
    }

    // ----------------------------------------------------------- service tools

    public function test_service_crud_with_server_or_address(): void
    {
        $this->ok('create_server', ['name' => 'docker-1', 'address' => '192.0.2.20']);

        $wiki = $this->ok('create_service', ['name' => 'Wiki', 'domain' => 'wiki.example.com', 'server' => 'docker-1', 'port' => 3000])['service'];
        $this->assertSame('docker-1', $wiki['server']);
        $this->assertSame('http://192.0.2.20:3000/', $wiki['backend_url']);
        $this->assertTrue($wiki['verify_tls']);
        $this->assertTrue($wiki['public_check']);

        $router = $this->ok('create_service', ['name' => 'Router', 'address' => '192.0.2.1', 'scheme' => 'https', 'expect' => '200-499', 'public_check' => false])['service'];
        $this->assertSame('https://192.0.2.1:443/', $router['backend_url']);
        $this->assertFalse($router['public_check']);

        // Switch Wiki from a server to its own address with one partial update.
        $moved = $this->ok('update_service', ['name' => 'Wiki', 'address' => '192.0.2.99'])['service'];
        $this->assertNull($moved['server']);
        $this->assertSame('192.0.2.99', $moved['address']);
        $this->assertSame('wiki.example.com', $moved['domain'], 'omitted fields keep their value');

        // ...and back.
        $back = $this->ok('update_service', ['name' => 'Wiki', 'server' => 'docker-1'])['service'];
        $this->assertSame('docker-1', $back['server']);
        $this->assertNull($back['address']);

        $this->ok('delete_service', ['name' => 'Router']);
        $this->assertSame(1, Service::count());
    }

    public function test_service_validation(): void
    {
        $this->assertStringContainsString('exactly one', $this->fails('create_service', ['name' => 'X']));
        $this->ok('create_server', ['name' => 'docker-1', 'address' => '192.0.2.20']);
        $this->assertStringContainsString('exactly one', $this->fails('create_service', ['name' => 'X', 'server' => 'docker-1', 'address' => '192.0.2.5']));
        $this->assertStringContainsString("Server 'ghost' does not exist", $this->fails('create_service', ['name' => 'X', 'server' => 'ghost']));
        $this->assertStringContainsString('backwards', $this->fails('create_service', ['name' => 'X', 'address' => '192.0.2.5', 'expect' => '399-200']));
        $this->assertStringContainsString('start with /', $this->fails('create_service', ['name' => 'X', 'address' => '192.0.2.5', 'path' => 'nope']));
        $this->assertSame(0, Service::count());
    }

    // ---------------------------------------------------------- settings & yaml

    public function test_update_settings_including_visibility(): void
    {
        $out = $this->ok('update_settings', ['title' => 'Home Lab', 'check_interval' => 15, 'registration_mode' => 'open', 'visibility' => ['servers' => 'everyone']])['settings'];

        $this->assertSame('Home Lab', $out['title']);
        $this->assertSame('open', $out['registration_mode']);
        $this->assertSame('everyone', $out['visibility']['servers']);
        $this->assertSame('users', $out['visibility']['services'], 'untouched screens keep their level');
        $this->assertSame('everyone', Settings::visibility('servers'));
        $this->assertSame(15, Settings::get('check_interval'));

        $this->assertStringContainsString('Validation failed', $this->fails('update_settings', ['check_interval' => 2]));
        $this->assertStringContainsString('Validation failed', $this->fails('update_settings', ['visibility' => ['users' => 'martians']]));
        $this->assertStringContainsString('Validation failed', $this->fails('update_settings', ['ssh_user' => '-oFoo']));
    }

    public function test_import_yaml_validates_dry_runs_and_replaces(): void
    {
        $yaml = (string) file_get_contents(base_path('labman.example.yml'));

        $this->assertSame(['valid' => true, 'servers' => 2, 'services' => 2], $this->ok('import_yaml', ['yaml' => $yaml, 'dry_run' => true]));
        $this->assertSame(0, Server::count(), 'dry run changes nothing');

        $this->assertSame(['servers' => 2, 'services' => 2, 'removed' => 0], $this->ok('import_yaml', ['yaml' => $yaml]));

        $message = $this->fails('import_yaml', ['yaml' => "servers:\n  - {name: x}\nservices: []\n"]);
        $this->assertStringContainsString("server 'x': 'address' (IP or hostname) is required", $message);

        $replace = $this->ok('import_yaml', ['yaml' => "servers:\n  - {name: only, address: 192.0.2.77}\nservices: []\n", 'replace' => true]);
        $this->assertSame(4, $replace['removed'], 'the 2 servers and 2 services from the example file');
        $this->assertSame(['only'], Server::pluck('name')->all());
    }

    public function test_run_health_check_updates_stored_states(): void
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) explode(':', stream_socket_get_name($listener, false))[1];
        $this->ok('create_server', ['name' => 'local', 'address' => '127.0.0.1', 'ssh_port' => $port]);

        $this->ok('run_health_check');

        $this->assertSame('up', $this->ok('list_servers')['servers'][0]['state']);
        fclose($listener);
    }

    // ---------------------------------------------------------------- user tools

    public function test_user_tools(): void
    {
        $created = $this->ok('create_user', ['name' => 'Pat', 'email' => 'Pat@Example.com', 'password' => 'a long password'])['user'];
        $this->assertSame('pat@example.com', $created['email']);
        $this->assertSame('user', $created['role']);
        $this->assertSame('active', $created['status']);
        $this->assertArrayNotHasKey('password', $created);

        $this->assertCount(2, $this->ok('list_users')['users']);

        $this->ok('update_user', ['id' => $created['id'], 'role' => 'admin']);
        $this->assertSame('admin', User::find($created['id'])->role);

        $pending = User::factory()->pending()->create(['email' => 'wait@example.com']);
        $this->assertSame('active', $this->ok('approve_user', ['current_email' => 'wait@example.com'])['user']['status']);
        $this->assertSame('active', $pending->fresh()->status);

        // Password reset through the tool: the new password works, the old one does not.
        $this->ok('update_user', ['id' => $created['id'], 'password' => 'brand new password']);
        $this->post('/login', ['email' => 'pat@example.com', 'password' => 'a long password'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => 'pat@example.com', 'password' => 'brand new password'])->assertRedirect();
    }

    public function test_the_token_owner_cannot_demote_or_disable_themselves(): void
    {
        $message = $this->fails('update_user', ['id' => $this->admin->id, 'role' => 'user']);
        $this->assertStringContainsString('cannot demote or disable your own account', $message);

        $this->fails('update_user', ['id' => $this->admin->id, 'status' => 'disabled']);

        $this->assertSame('admin', $this->admin->fresh()->role);
        $this->assertSame('active', $this->admin->fresh()->status);
    }

    public function test_user_validation(): void
    {
        $this->assertStringContainsString('Validation failed', $this->fails('create_user', ['name' => 'X', 'email' => 'not-an-email', 'password' => 'long enough pw']));
        $this->assertStringContainsString('Validation failed', $this->fails('create_user', ['name' => 'X', 'email' => 'x@example.com', 'password' => 'short']));
        $this->assertStringContainsString('already been taken', $this->fails('create_user', ['name' => 'X', 'email' => 'root@example.com', 'password' => 'long enough pw']));
    }

    // ---------------------------------------------------------- token management UI

    public function test_admin_creates_a_token_that_is_shown_once_and_works(): void
    {
        $response = $this->actingAs($this->admin)->post('/settings/tokens', ['name' => 'Laptop', 'scope' => 'admin'])->assertOk();

        preg_match('/lm_[A-Za-z0-9]{40}/', $response->getContent(), $m);
        $this->assertNotEmpty($m, 'the plaintext token is shown on the creation page');
        $response->assertSee('claude mcp add --transport http labman', false);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        $this->assertSame(1, ApiToken::where('name', 'Laptop')->count());
        $this->assertNull(ApiToken::where('token_hash', $m[0])->first(), 'only the hash is stored');
        $this->rpc('ping', [], $m[0])->assertOk();

        // The settings page lists it by prefix only, never the full token.
        $this->get('/settings/mcp')->assertOk()->assertSee('Laptop')->assertSee(substr($m[0], 0, 8))->assertDontSee($m[0]);
        $this->assertSame(0, DB::table('sessions')->where('payload', 'like', '%'.$m[0].'%')->count());
    }

    public function test_only_admins_manage_tokens_and_revoking_works(): void
    {
        $this->post('/settings/tokens', ['name' => 'x', 'scope' => 'read'])->assertRedirect(route('login'));
        $this->actingAs($this->user())->post('/settings/tokens', ['name' => 'x', 'scope' => 'read'])->assertForbidden();
        $this->actingAs($this->user())->delete('/settings/tokens/1')->assertForbidden();

        $this->actingAs($this->admin)->post('/settings/tokens', ['name' => 'x', 'scope' => 'banana'])->assertSessionHasErrors('scope');

        $token = ApiToken::findByPlain($this->readToken);
        $this->actingAs($this->admin)->delete("/settings/tokens/{$token->id}")->assertRedirect(route('settings.mcp'));
        $this->rpc('ping', [], $this->readToken)->assertUnauthorized();
    }

    public function test_non_admins_never_see_the_token_section(): void
    {
        Settings::set(['visibility.settings' => 'users']);

        $this->actingAs($this->user())->get('/settings')->assertOk()->assertDontSee('API tokens')->assertDontSee('test admin');
        $this->get('/settings/mcp')->assertForbidden();
    }
}
