<?php

namespace Tests\Feature;

use App\Config\YamlConfig;
use App\Models\ApiToken;
use App\Models\Server;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Npm\Connection;
use App\Npm\NpmClient;
use App\Npm\NpmException;
use App\Npm\NpmSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeNpm;
use Tests\TestCase;

class NpmTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private FakeNpm $npm;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->admin(['email' => 'root@example.com']);
        $this->npm = FakeNpm::install();
    }

    private function connect(): void
    {
        Connection::save(FakeNpm::URL, 'npm@example.com', 'npm-secret', true);
    }

    private function sync(): NpmSync
    {
        $this->connect();

        return new NpmSync(NpmClient::fromStored());
    }

    /** @param list<array<string, mixed>> $plan */
    private function item(array $plan, string $key): array
    {
        foreach ($plan as $item) {
            if ($item['key'] === $key) {
                return $item;
            }
        }
        $this->fail("No plan item {$key}; got: ".implode(', ', array_column($plan, 'key')));
    }

    /** @param list<array<string, mixed>> $plan @return array<string, string> */
    private function select(array $plan, string ...$keys): array
    {
        return collect($plan)->whereIn('key', $keys)->pluck('fingerprint', 'key')->all();
    }

    // ------------------------------------------------------------ connection

    public function test_connecting_tests_the_login_and_stores_it_encrypted(): void
    {
        $this->actingAs($this->admin)->post('/npm/connection', [
            'url' => 'http://npm.test:81/', 'email' => 'npm@example.com', 'password' => 'npm-secret', 'verify_tls' => 1,
        ])->assertRedirect(route('npm.index'))->assertSessionHas('status');

        $stored = Connection::get();
        $this->assertSame(['url' => 'http://npm.test:81', 'email' => 'npm@example.com', 'password' => 'npm-secret', 'verify_tls' => true], $stored);

        // Encrypted at rest: neither the e-mail nor the password appears in the database.
        $raw = Setting::query()->pluck('value', 'key')->only(['npm.email', 'npm.password']);
        foreach ($raw as $value) {
            $this->assertStringNotContainsString('npm-secret', $value);
            $this->assertStringNotContainsString('npm@example.com', $value);
        }

        // ...and never shown, exported or listed.
        $this->actingAs($this->admin)->get('/npm')->assertOk()->assertSee('npm@example.com')->assertDontSee('npm-secret');
        $this->assertStringNotContainsString('npm', YamlConfig::export());
        $this->assertArrayNotHasKey('npm.password', \App\Support\Settings::all());
        $this->assertArrayNotHasKey('password', Connection::summary());
    }

    public function test_bad_addresses_and_logins_are_rejected_and_nothing_is_saved(): void
    {
        $this->actingAs($this->admin);
        $good = ['url' => 'http://npm.test:81', 'email' => 'npm@example.com', 'password' => 'npm-secret'];

        foreach (['ftp://npm.test:81', 'http://user:pw@npm.test:81', 'npm.test', 'http://bad host:81', 'javascript:alert(1)'] as $url) {
            $this->post('/npm/connection', ['url' => $url] + $good)->assertSessionHasErrors('url');
        }

        $this->post('/npm/connection', ['password' => 'wrong'] + $good)->assertSessionHasErrors(['url' => 'NPM rejected the login (Invalid email or password).']);

        $this->npm->twoFactor = true;
        $this->post('/npm/connection', $good)->assertSessionHasErrors('url');
        $this->assertStringContainsString('two-factor', session('errors')->first('url'));

        $this->assertFalse(Connection::exists());
    }

    public function test_an_unreachable_npm_gives_a_helpful_message(): void
    {
        \Illuminate\Support\Facades\Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 7: Failed to connect to npm.test port 81: Connection refused'));

        $this->actingAs($this->admin)->post('/npm/connection', ['url' => 'http://npm.test:81', 'email' => 'a@example.com', 'password' => 'pw'])
            ->assertSessionHasErrors('url');
        $this->assertStringContainsString('connection refused', session('errors')->first('url'));
    }

    public function test_login_sends_only_identity_and_secret(): void
    {
        // Real NPM 2.17 rejects extra fields such as "expiry" with HTTP 400.
        $this->connect();
        NpmClient::fromStored()->proxyHosts();

        $login = collect($this->npm->requests)->firstWhere('path', '/api/tokens');
        $this->assertSame(['identity', 'secret'], array_keys($login['body']));
    }

    public function test_disconnecting_deletes_the_saved_login(): void
    {
        $this->connect();

        $this->actingAs($this->admin)->delete('/npm/connection')->assertRedirect(route('npm.index'));

        $this->assertFalse(Connection::exists());
        $this->assertSame(0, Setting::where('key', 'like', 'npm.%')->count());
    }

    public function test_the_saved_login_is_unreadable_after_the_app_key_changes(): void
    {
        $this->connect();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        app()->forgetInstance('encrypter');
        \Illuminate\Support\Facades\Crypt::clearResolvedInstances();

        $this->assertNull(Connection::get());
        $this->expectException(NpmException::class);
        NpmClient::fromStored();
    }

    public function test_every_npm_route_is_admin_only(): void
    {
        $routes = [['get', '/npm'], ['post', '/npm/connection'], ['delete', '/npm/connection'], ['get', '/npm/import'], ['post', '/npm/import'], ['get', '/npm/push'], ['post', '/npm/push']];

        foreach ($routes as [$method, $uri]) {
            $this->{$method}($uri)->assertRedirect(route('login'));
        }
        $this->actingAs($this->user());
        foreach ($routes as [$method, $uri]) {
            $this->{$method}($uri)->assertForbidden();
        }
        $this->assertSame([], $this->npm->requests, 'a refused request must never reach NPM');
    }

    // ---------------------------------------------------------- import (plan)

    public function test_import_plan_classifies_every_proxy_host(): void
    {
        Server::create(['name' => 'docker-1', 'address' => '192.0.2.20']);
        Service::create(['name' => 'Grafana', 'domain' => 'grafana.example.com', 'address' => '192.0.2.99', 'port' => 3000, 'scheme' => 'http', 'path' => '/']);

        $new = $this->npm->addHost(['domain_names' => ['wiki.example.com'], 'forward_host' => '192.0.2.20', 'forward_port' => 3000]);
        $existing = $this->npm->addHost(['domain_names' => ['Grafana.Example.com'], 'forward_host' => '192.0.2.30', 'forward_port' => 3001, 'allow_websocket_upgrade' => true]);
        $same = $this->npm->addHost(['domain_names' => ['synced.example.com'], 'forward_scheme' => 'https', 'forward_host' => '192.0.2.60', 'forward_port' => 8080, 'allow_websocket_upgrade' => true]);
        // Already linked and identical to its proxy host: nothing to do.
        Service::create(['name' => 'Synced', 'domain' => 'synced.example.com', 'address' => '192.0.2.60', 'port' => 8080, 'scheme' => 'https', 'path' => '/', 'websockets' => true, 'npm_host_id' => $same]);
        $wild = $this->npm->addHost(['domain_names' => ['*.example.com']]);
        $bad = $this->npm->addHost(['domain_names' => ['bad.example.com'], 'forward_host' => 'not a host!']);
        $multi = $this->npm->addHost(['domain_names' => ['a.example.com', 'b.example.com'], 'enabled' => false, 'forward_host' => '192.0.2.70']);

        $plan = $this->sync()->importPlan();

        $create = $this->item($plan, "host:{$new}");
        $this->assertSame('create', $create['action']);
        $this->assertSame('Wiki', $create['data']['input']['name']);
        $this->assertSame('wiki.example.com', $create['data']['input']['domain']);
        $this->assertSame(3000, $create['data']['input']['port']);
        $this->assertNotNull($create['data']['input']['server_id'], 'a forward host equal to a known server links to it');
        $this->assertNull($create['data']['input']['address']);

        $update = $this->item($plan, "host:{$existing}");
        $this->assertSame('update', $update['action']);
        $this->assertSame(['link', 'port', 'backend', 'websockets'], array_column($update['changes'], 'field'));
        $this->assertStringContainsString('Grafana', $update['title']);

        $this->assertSame('unchanged', $this->item($plan, "host:{$same}")['action']);
        $this->assertSame('skip', $this->item($plan, "host:{$wild}")['action']);
        $this->assertStringContainsString('wildcard', strtolower($this->item($plan, "host:{$wild}")['warnings'][0]));
        $this->assertSame('skip', $this->item($plan, "host:{$bad}")['action']);

        $multiItem = $this->item($plan, "host:{$multi}");
        $this->assertSame('create', $multiItem['action']);
        $this->assertCount(2, $multiItem['warnings']);
        $this->assertFalse($multiItem['data']['input']['public_check'], 'disabled hosts import with the public check off');

        $this->assertSame([], $this->npm->writes(), 'planning never writes');
    }

    public function test_service_names_are_made_unique(): void
    {
        Service::create(['name' => 'Wiki', 'address' => '192.0.2.5', 'scheme' => 'http', 'path' => '/']);
        $a = $this->npm->addHost(['domain_names' => ['wiki.example.com']]);
        $b = $this->npm->addHost(['domain_names' => ['wiki.example.org']]);

        $plan = $this->sync()->importPlan();

        $this->assertSame('wiki.example.com', $this->item($plan, "host:{$a}")['data']['input']['name']);
        $this->assertSame('wiki.example.org', $this->item($plan, "host:{$b}")['data']['input']['name']);
    }

    // --------------------------------------------------------- import (apply)

    public function test_applying_an_import_creates_and_updates_only_what_was_ticked(): void
    {
        Service::create(['name' => 'Grafana', 'domain' => 'grafana.example.com', 'address' => '192.0.2.99', 'scheme' => 'http', 'path' => '/', 'notes' => 'keep me', 'group' => 'Monitoring']);
        $new = $this->npm->addHost(['domain_names' => ['wiki.example.com'], 'forward_scheme' => 'https', 'forward_host' => '192.0.2.20', 'forward_port' => 8443]);
        $existing = $this->npm->addHost(['domain_names' => ['grafana.example.com'], 'forward_host' => '192.0.2.30', 'forward_port' => 3001]);
        $skipped = $this->npm->addHost(['domain_names' => ['unticked.example.com']]);

        $sync = $this->sync();
        $plan = $sync->importPlan();
        $result = $sync->applyImport($this->select($plan, "host:{$new}", "host:{$existing}"));

        $this->assertSame(2, $result['applied']);

        $wiki = Service::where('name', 'Wiki')->firstOrFail();
        $this->assertSame($new, $wiki->npm_host_id);
        $this->assertSame('https', $wiki->scheme);
        $this->assertSame(8443, $wiki->port);
        $this->assertSame('192.0.2.20', $wiki->address);
        $this->assertSame('wiki.example.com', $wiki->domain);

        $grafana = Service::where('name', 'Grafana')->firstOrFail();
        $this->assertSame($existing, $grafana->npm_host_id);
        $this->assertSame('192.0.2.30', $grafana->address);
        $this->assertSame(3001, $grafana->port);
        $this->assertSame('keep me', $grafana->notes, 'fields NPM does not own are untouched');
        $this->assertSame('Monitoring', $grafana->group);

        $this->assertNull(Service::where('domain', 'unticked.example.com')->first());
        $this->assertSame([], $this->npm->writes(), 'an import never writes to NPM');

        // Re-running shows everything in sync.
        $again = $sync->importPlan();
        $this->assertSame('unchanged', $this->item($again, "host:{$new}")['action']);
        $this->assertSame('unchanged', $this->item($again, "host:{$existing}")['action']);
    }

    public function test_a_stale_preview_is_refused_and_applies_nothing(): void
    {
        $id = $this->npm->addHost(['domain_names' => ['wiki.example.com'], 'forward_host' => '192.0.2.20', 'forward_port' => 3000]);
        $sync = $this->sync();
        $selected = $this->select($sync->importPlan(), "host:{$id}");

        // Someone edits the proxy host in NPM after the preview was shown.
        $this->npm->hosts[$id]['forward_port'] = 4000;

        try {
            $sync->applyImport($selected);
            $this->fail('Expected a stale-preview refusal');
        } catch (NpmException $e) {
            $this->assertStringContainsString('changed since the preview', $e->getMessage());
        }

        $this->assertSame(0, Service::count());
    }

    public function test_unknown_keys_and_forged_fingerprints_are_refused(): void
    {
        $id = $this->npm->addHost(['domain_names' => ['wiki.example.com']]);
        $sync = $this->sync();

        foreach ([['host:9999' => str_repeat('a', 40)], ["host:{$id}" => str_repeat('0', 40)], ["host:{$id}" => '']] as $selected) {
            try {
                $sync->applyImport($selected);
                $this->fail('Expected a refusal');
            } catch (NpmException) {
                $this->assertSame(0, Service::count());
            }
        }
    }

    public function test_skipped_and_unchanged_items_cannot_be_applied(): void
    {
        $wild = $this->npm->addHost(['domain_names' => ['*.example.com']]);
        $sync = $this->sync();
        $plan = $sync->importPlan();

        $this->expectException(NpmException::class);
        $sync->applyImport($this->select($plan, "host:{$wild}"));
    }

    // ------------------------------------------------------------- push (plan)

    public function test_push_plan_diffs_updates_creations_and_skips(): void
    {
        $server = Server::create(['name' => 'docker-1', 'address' => '192.0.2.20']);
        $update = Service::create(['name' => 'Wiki', 'domain' => 'wiki.example.com', 'server_id' => $server->id, 'port' => 3000, 'scheme' => 'http', 'path' => '/', 'websockets' => true]);
        $same = Service::create(['name' => 'Same', 'domain' => 'same.example.com', 'address' => '192.0.2.60', 'port' => 8080, 'scheme' => 'https', 'path' => '/']);
        $create = Service::create(['name' => 'Brand New', 'domain' => 'new.example.com', 'address' => '192.0.2.61', 'scheme' => 'http', 'path' => '/']);
        $noAddress = Service::create(['name' => 'Orphan', 'domain' => 'orphan.example.com', 'scheme' => 'http', 'path' => '/']);
        $noDomain = Service::create(['name' => 'Internal', 'address' => '192.0.2.62', 'scheme' => 'http', 'path' => '/']);

        $this->npm->addHost(['domain_names' => ['wiki.example.com'], 'forward_host' => '192.0.2.99', 'forward_port' => 3001, 'allow_websocket_upgrade' => false]);
        $this->npm->addHost(['domain_names' => ['same.example.com'], 'forward_scheme' => 'https', 'forward_host' => '192.0.2.60', 'forward_port' => 8080]);

        $plan = $this->sync()->pushPlan();

        $u = $this->item($plan, "svc:{$update->id}");
        $this->assertSame('update', $u['action']);
        $this->assertSame(['forward_host', 'forward_port', 'allow_websocket_upgrade'], array_column($u['changes'], 'field'));
        $this->assertSame(['forward_host' => '192.0.2.20', 'forward_port' => 3000, 'allow_websocket_upgrade' => true], $u['data']['payload'], 'only the changed fields are sent');

        $this->assertSame('unchanged', $this->item($plan, "svc:{$same->id}")['action']);

        $c = $this->item($plan, "svc:{$create->id}");
        $this->assertSame('create', $c['action']);
        $this->assertSame(['new.example.com'], $c['data']['payload']['domain_names']);
        $this->assertSame(80, $c['data']['payload']['forward_port']);
        $this->assertSame(0, $c['data']['payload']['certificate_id']);
        $this->assertFalse($c['data']['payload']['ssl_forced']);
        $this->assertStringContainsString('without SSL', $c['warnings'][0]);

        $this->assertSame('skip', $this->item($plan, "svc:{$noAddress->id}")['action']);
        $this->assertNull(collect($plan)->firstWhere('key', "svc:{$noDomain->id}"), 'services without a domain are not part of a push');
        $this->assertSame([], $this->npm->writes(), 'planning never writes');
    }

    public function test_push_adds_the_domain_to_a_host_matched_by_id(): void
    {
        $id = $this->npm->addHost(['domain_names' => ['old.example.com'], 'forward_host' => '192.0.2.5', 'forward_port' => 80]);
        $service = Service::create(['name' => 'Moved', 'domain' => 'new.example.com', 'address' => '192.0.2.5', 'scheme' => 'http', 'path' => '/', 'npm_host_id' => $id]);

        $item = $this->item($this->sync()->pushPlan(), "svc:{$service->id}");

        $this->assertSame('update', $item['action']);
        $this->assertSame(['old.example.com', 'new.example.com'], $item['data']['payload']['domain_names'], 'existing names are kept, never dropped');
    }

    // ------------------------------------------------------------ push (apply)

    public function test_applying_a_push_writes_only_ticked_changes_and_never_deletes(): void
    {
        $update = Service::create(['name' => 'Wiki', 'domain' => 'wiki.example.com', 'address' => '192.0.2.20', 'port' => 3000, 'scheme' => 'http', 'path' => '/']);
        $create = Service::create(['name' => 'Fresh', 'domain' => 'fresh.example.com', 'address' => '192.0.2.61', 'port' => 9000, 'scheme' => 'https', 'path' => '/']);
        $unticked = Service::create(['name' => 'Left Alone', 'domain' => 'left.example.com', 'address' => '192.0.2.62', 'scheme' => 'http', 'path' => '/']);

        $hostId = $this->npm->addHost(['domain_names' => ['wiki.example.com'], 'forward_host' => '192.0.2.99', 'forward_port' => 3001, 'ssl_forced' => true, 'certificate_id' => 7, 'block_exploits' => true]);
        $otherId = $this->npm->addHost(['domain_names' => ['left.example.com'], 'forward_host' => '192.0.2.1', 'forward_port' => 80]);
        $before = $this->npm->hosts[$otherId];

        $sync = $this->sync();
        $plan = $sync->pushPlan();
        $result = $sync->applyPush($this->select($plan, "svc:{$update->id}", "svc:{$create->id}"));

        $this->assertSame(2, $result['applied']);

        // The existing host got a partial update: forwarding changed, SSL/certificate/other settings untouched.
        $host = $this->npm->hosts[$hostId];
        $this->assertSame('192.0.2.20', $host['forward_host']);
        $this->assertSame(3000, $host['forward_port']);
        $this->assertTrue($host['ssl_forced']);
        $this->assertSame(7, $host['certificate_id']);

        // The new proxy host was created and linked.
        $created = collect($this->npm->hosts)->first(fn ($h) => $h['domain_names'] === ['fresh.example.com']);
        $this->assertSame('https', $created['forward_scheme']);
        $this->assertSame(9000, $created['forward_port']);
        $this->assertSame($created['id'], $create->fresh()->npm_host_id);
        $this->assertSame($hostId, $update->fresh()->npm_host_id);

        $this->assertSame($before, $this->npm->hosts[$otherId], 'unticked hosts are untouched');
        $this->assertNull($unticked->fresh()->npm_host_id);

        // Exactly one PUT and one POST; no DELETE or PATCH anywhere, ever.
        $this->assertSame(['POST', 'PUT'], collect($this->npm->writes())->pluck('method')->sort()->values()->all());
        $this->assertNotContains('DELETE', $this->npm->methods());
        $this->assertNotContains('PATCH', $this->npm->methods());
    }

    public function test_a_push_is_refused_when_npm_changed_after_the_preview(): void
    {
        $service = Service::create(['name' => 'Wiki', 'domain' => 'wiki.example.com', 'address' => '192.0.2.20', 'port' => 3000, 'scheme' => 'http', 'path' => '/']);
        $hostId = $this->npm->addHost(['domain_names' => ['wiki.example.com'], 'forward_host' => '192.0.2.99', 'forward_port' => 3001]);

        $sync = $this->sync();
        $selected = $this->select($sync->pushPlan(), "svc:{$service->id}");

        $this->npm->hosts[$hostId]['forward_host'] = '192.0.2.150'; // edited in NPM meanwhile
        $this->npm->hosts[$hostId]['modified_on'] = '2026-07-01 00:00:00';

        try {
            $sync->applyPush($selected);
            $this->fail('Expected a stale-preview refusal');
        } catch (NpmException $e) {
            $this->assertStringContainsString('changed since the preview', $e->getMessage());
        }

        $this->assertSame([], $this->npm->writes(), 'nothing was written');
        $this->assertSame('192.0.2.150', $this->npm->hosts[$hostId]['forward_host']);
    }

    public function test_a_proxy_host_appearing_after_the_preview_refuses_the_apply(): void
    {
        $a = Service::create(['name' => 'Dup', 'domain' => 'dup.example.com', 'address' => '192.0.2.20', 'scheme' => 'http', 'path' => '/']);
        $sync = $this->sync();
        $selected = $this->select($sync->pushPlan(), "svc:{$a->id}");

        // The domain appears in NPM between preview and apply: the plan would now be an update, not the
        // previewed create, so the apply is refused instead of creating a clashing host.
        $this->npm->addHost(['domain_names' => ['dup.example.com']]);

        try {
            $sync->applyPush($selected);
            $this->fail('Expected a stale-preview refusal');
        } catch (NpmException) {
            $this->assertSame([], $this->npm->writes());
        }
    }

    // -------------------------------------------------------------- web screens

    public function test_the_preview_and_apply_screens_work_end_to_end(): void
    {
        $this->connect();
        $id = $this->npm->addHost(['domain_names' => ['wiki.example.com'], 'forward_host' => '192.0.2.20', 'forward_port' => 3000]);
        $this->npm->addHost(['domain_names' => ['*.example.org']]);

        $page = $this->actingAs($this->admin)->get('/npm/import')->assertOk()
            ->assertSee('wiki.example.com')->assertSee('new service')->assertSee('skipped')->assertSee('Apply ticked changes to LabMan');

        preg_match('/name="fp\[host:'.$id.'\]" value="([0-9a-f]{40})"/', $page->getContent(), $m);
        $this->assertNotEmpty($m);

        $this->post('/npm/import', ['apply' => ["host:{$id}"], 'fp' => ["host:{$id}" => $m[1]]])
            ->assertRedirect(route('npm.index'))->assertSessionHas('npm_results');
        $this->assertSame('Wiki', Service::first()->name);

        $this->get('/npm')->assertOk()->assertSee('Service created');

        // Nothing ticked is a validation error, not a silent no-op.
        $this->post('/npm/import', ['apply' => [], 'fp' => []])->assertSessionHasErrors('apply');
    }

    public function test_a_stale_apply_through_the_ui_shows_an_error(): void
    {
        $this->connect();
        $id = $this->npm->addHost(['domain_names' => ['wiki.example.com']]);

        $this->actingAs($this->admin)->post('/npm/import', ['apply' => ["host:{$id}"], 'fp' => ["host:{$id}" => str_repeat('a', 40)]])
            ->assertRedirect(route('npm.import'))->assertSessionHasErrors('npm');
        $this->assertSame(0, Service::count());
    }

    public function test_npm_errors_are_shown_not_thrown(): void
    {
        // Not connected at all.
        $this->actingAs($this->admin)->get('/npm/import')->assertRedirect(route('npm.index'))->assertSessionHasErrors('npm');

        $this->connect();
        $this->npm->password = 'changed-in-npm';
        $this->get('/npm/push')->assertRedirect(route('npm.index'))->assertSessionHasErrors('npm');
        $this->assertStringContainsString('rejected the login', session('errors')->first('npm'));
    }

    // ----------------------------------------------------------------- MCP tools

    public function test_the_mcp_npm_tools_follow_the_preview_then_apply_flow(): void
    {
        [, $token] = ApiToken::issue($this->admin, 'agent', 'admin');
        [, $readToken] = ApiToken::issue($this->admin, 'viewer', 'read');
        $call = function (string $tool, array $args = [], ?string $as = null) use ($token) {
            $r = $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => (object) $args]], ['Authorization' => 'Bearer '.($as ?? $token)])->json('result');

            return [$r['isError'], $r['structuredContent'] ?? $r['content'][0]['text']];
        };

        // Connect through MCP; the password is never echoed back.
        [$error, $out] = $call('npm_set_connection', ['url' => FakeNpm::URL, 'email' => 'npm@example.com', 'password' => 'npm-secret']);
        $this->assertFalse($error, json_encode($out));
        $this->assertSame('2.17.0', $out['npm_version']);
        $this->assertStringNotContainsString('npm-secret', json_encode($out));
        [, $summary] = $call('npm_get_connection');
        $this->assertStringNotContainsString('npm-secret', json_encode($summary));
        $this->assertTrue($summary['connected']);

        // Preview, then apply exactly what the preview returned.
        $id = $this->npm->addHost(['domain_names' => ['wiki.example.com'], 'forward_host' => '192.0.2.20', 'forward_port' => 3000]);
        [, $preview] = $call('npm_import_preview');
        $item = $preview['items'][0];
        $this->assertSame('create', $item['action']);
        $this->assertArrayNotHasKey('data', $item, 'internals are not exposed to the client');

        [$error, $applied] = $call('npm_import_apply', ['items' => [['key' => $item['key'], 'fingerprint' => $item['fingerprint']]]]);
        $this->assertFalse($error);
        $this->assertSame(1, $applied['applied']);
        $this->assertSame($id, Service::first()->npm_host_id);

        // A forged fingerprint is a tool error and changes nothing more.
        [$error, $text] = $call('npm_import_apply', ['items' => [['key' => "host:{$id}", 'fingerprint' => str_repeat('0', 40)]]]);
        $this->assertTrue($error);
        $this->assertStringContainsString('changed since the preview', $text);

        // Push: change the service in LabMan, preview, apply.
        Service::first()->update(['port' => 4000]);
        [, $push] = $call('npm_push_preview');
        $this->assertSame('update', $push['items'][0]['action']);
        $this->assertSame([], $this->npm->writes());
        [$error] = $call('npm_push_apply', ['items' => [['key' => $push['items'][0]['key'], 'fingerprint' => $push['items'][0]['fingerprint']]]]);
        $this->assertFalse($error);
        $this->assertSame(4000, $this->npm->hosts[$id]['forward_port']);

        // Read-scope tokens cannot use any of it.
        [$error, $text] = $call('npm_push_apply', ['items' => []], $readToken);
        $this->assertTrue($error);
        $this->assertStringContainsString("'read' scope", $text);

        [, $gone] = $call('npm_remove_connection');
        $this->assertFalse($gone['connected']);
        $this->assertFalse(Connection::exists());
    }
}
