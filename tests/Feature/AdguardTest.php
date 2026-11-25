<?php

namespace Tests\Feature;

use App\Dns\AdguardClient;
use App\Dns\AdguardConnection;
use App\Dns\AdguardException;
use App\Dns\AdguardSync;
use App\Mcp\McpServer;
use App\Models\ApiToken;
use App\Models\Server;
use App\Models\Setting;
use App\Models\Zone;
use App\Support\Settings;
use App\Support\Zones;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** AdGuard Home: connect, preview the inside records of the DNS plan as rewrites, apply what was approved, never delete. */
class AdguardTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'http://adguard.test:3000';

    /** @var list<array{domain: string, answer: string}> the rewrites "in AdGuard" */
    private array $rewrites = [];

    /** @var list<string> every request made, "METHOD path" */
    private array $calls = [];

    private bool $auth = true;

    private int $failAdds = 0;

    /** @var list<array<string, mixed>> the JSON bodies sent to the delete endpoint */
    private array $deleteBodies = [];

    private ?int $addStatus = null;

    /** @var int|null when set, /control/rewrite/update answers with this status (404 = an old AdGuard Home) */
    private ?int $updateStatus = null;

    /** @var list<array<string, mixed>> the JSON bodies sent to the update endpoint */
    private array $updateBodies = [];

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = $this->admin(['email' => 'root@example.com']);
        $this->actingAs($admin);
        [, $this->adminToken] = ApiToken::issue($admin, 'test admin', 'admin');

        Settings::set(['local_domains' => 'home.example.com']);
        Zones::default()->update(['name' => 'Internal', 'proxy_address' => '192.0.2.5']);
        Server::create(['name' => 'Nas', 'address' => '192.0.2.20', 'domain' => 'nas.home.example.com']);

        Http::fake(function (Request $request) {
            if (! str_starts_with($request->url(), self::URL)) {
                return null;
            }
            $path = parse_url($request->url(), PHP_URL_PATH);
            $this->calls[] = $request->method().' '.$path;

            if ($this->auth && $request->header('Authorization')[0] !== 'Basic '.base64_encode('lab:hunter2-test')) {
                return Http::response('Unauthorized', 401);
            }
            $body = $request->data();

            return match ($path) {
                '/control/status' => Http::response(['version' => 'v0.107.50', 'running' => true]),
                '/control/rewrite/list' => Http::response($this->rewrites),
                '/control/rewrite/add' => $request->method() === 'POST' ? $this->add($body) : Http::response('only method POST is allowed', 405),
                '/control/rewrite/delete' => $request->method() === 'POST' ? $this->remove($body) : Http::response('only method POST is allowed', 405),
                '/control/rewrite/update' => $request->method() === 'PUT' ? $this->change($body) : Http::response('only method PUT is allowed', 405),
                default => Http::response('not found', 404),
            };
        });
    }

    private function add(array $b)
    {
        if ($this->addStatus !== null) {
            return Http::response('only method PUT is allowed', $this->addStatus);
        }
        if ($this->failAdds > 0) {
            $this->failAdds--;

            return Http::response('rewrite already exists', 400);
        }
        $this->rewrites[] = ['domain' => $b['domain'], 'answer' => $b['answer']];

        return Http::response('');
    }

    private function remove(array $b)
    {
        $this->deleteBodies[] = $b;
        $this->rewrites = array_values(array_filter($this->rewrites, fn ($r) => ! ($r['domain'] === $b['domain'] && $r['answer'] === $b['answer'])));

        return Http::response('');
    }

    private function change(array $b)
    {
        $this->updateBodies[] = $b;
        if ($this->updateStatus !== null) {
            return Http::response('not found', $this->updateStatus);
        }
        foreach ($this->rewrites as $i => $r) {
            if ($r['domain'] === $b['target']['domain'] && $r['answer'] === $b['target']['answer']) {
                $this->rewrites[$i] = $b['update'];

                return Http::response('');
            }
        }

        return Http::response('no such rewrite', 400);
    }

    private function connect(): void
    {
        AdguardConnection::save(self::URL, 'lab', 'hunter2-test', true);
    }

    private function item(array $items, string $key): array
    {
        foreach ($items as $i) {
            if ($i['key'] === $key) {
                return $i;
            }
        }
        $this->fail("no preview item {$key}; have ".implode(', ', array_column($items, 'key')));
    }

    private function selection(array $items, string ...$keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $this->item($items, $key)['fingerprint'];
        }

        return $out;
    }

    // ------------------------------------------------------------------------- connection

    public function test_connecting_tests_the_login_and_stores_it_encrypted(): void
    {
        $this->post(route('dns.adguard.connect'), ['url' => self::URL.'/login.html', 'username' => 'lab', 'password' => 'hunter2-test', 'verify_tls' => '1'])
            ->assertRedirect(route('dns.adguard'))->assertSessionHas('status');

        $this->assertSame(['url' => self::URL, 'username' => 'lab', 'verify_tls' => true], AdguardConnection::summary());
        $this->assertSame('hunter2-test', AdguardConnection::get()['password']);
        $this->assertStringNotContainsString('hunter2-test', (string) Setting::query()->where('key', 'adguard.password')->value('value'));
    }

    public function test_a_wrong_login_is_refused_and_nothing_is_saved(): void
    {
        $this->from(route('dns.adguard'))->post(route('dns.adguard.connect'), ['url' => self::URL, 'username' => 'lab', 'password' => 'wrong', 'verify_tls' => '1'])
            ->assertSessionHasErrors('url');

        $this->assertNull(AdguardConnection::summary());
    }

    public function test_adguard_without_a_login_can_be_connected(): void
    {
        $this->auth = false;
        $this->post(route('dns.adguard.connect'), ['url' => self::URL, 'verify_tls' => '1'])->assertSessionHasNoErrors();

        $this->assertSame('', AdguardConnection::get()['username']);
        $this->assertSame('v0.107.50', AdguardClient::fromStored()->test());
    }

    public function test_the_pages_render_and_never_show_the_password(): void
    {
        $this->get(route('dns.adguard'))->assertOk()->assertSee('Connect');
        $this->connect();

        $this->get(route('dns.adguard'))->assertOk()->assertSee('AdGuard Home v0.107.50')->assertDontSee('hunter2-test');
        $this->get(route('dns.index'))->assertOk()->assertSee(route('dns.adguard'));
        $this->delete(route('dns.adguard.disconnect'))->assertRedirect();
        $this->assertFalse(AdguardConnection::exists());
    }

    public function test_it_is_listed_as_an_integration_with_its_status(): void
    {
        $this->get(route('admin.index'))->assertOk()->assertSee('AdGuard Home')->assertSee(route('dns.adguard'));
        $this->assertNotEmpty(collect(\App\Support\AdminNav::sections(auth()->user()))->firstWhere('title', 'Integrations')['items'] ?? []);

        $find = fn () => collect(\App\Support\AdminNav::sections(auth()->user()))->firstWhere('title', 'Integrations')['items'];
        $item = fn () => collect($find())->firstWhere('route', 'dns.adguard');
        $this->assertFalse($item()['status']);
        $this->connect();
        $this->assertTrue($item()['status']);
    }

    public function test_preview_without_a_connection_sends_you_to_connect(): void
    {
        $this->get(route('dns.adguard.preview'))->assertRedirect(route('dns.adguard'));
    }

    // ------------------------------------------------------------------------- preview

    public function test_preview_lists_what_is_missing_and_leaves_other_rewrites_alone(): void
    {
        $this->connect();
        $this->rewrites = [['domain' => 'unrelated.example.org', 'answer' => '192.0.2.99']];

        $plan = (new AdguardSync(AdguardClient::fromStored()))->plan();

        $wild = $this->item($plan['items'], '*.home.example.com|A');
        $this->assertSame('create', $wild['action']);
        $this->assertSame([['field' => 'A', 'from' => null, 'to' => '192.0.2.5']], $wild['changes']);
        $this->assertSame('create', $this->item($plan['items'], 'nas.home.example.com|A')['action']);
        $this->assertSame(1, $plan['others']);
        $this->assertSame([], array_filter($this->calls, fn ($c) => str_starts_with($c, 'POST')));
    }

    public function test_an_existing_rewrite_is_in_sync_or_a_change_of_answer(): void
    {
        $this->connect();
        $this->rewrites = [['domain' => '*.home.example.com', 'answer' => '192.0.2.5'], ['domain' => 'nas.home.example.com', 'answer' => '192.0.2.77']];

        $items = (new AdguardSync(AdguardClient::fromStored()))->plan()['items'];

        $this->assertSame('unchanged', $this->item($items, '*.home.example.com|A')['action']);
        $nas = $this->item($items, 'nas.home.example.com|A');
        $this->assertSame('update', $nas['action']);
        $this->assertSame([['field' => 'A', 'from' => '192.0.2.77', 'to' => '192.0.2.20']], $nas['changes']);
    }

    public function test_several_answers_for_one_name_are_not_guessed_at(): void
    {
        $this->connect();
        $this->rewrites = [['domain' => 'nas.home.example.com', 'answer' => '192.0.2.77'], ['domain' => 'nas.home.example.com', 'answer' => '192.0.2.78']];

        $nas = $this->item((new AdguardSync(AdguardClient::fromStored()))->plan()['items'], 'nas.home.example.com|A');

        $this->assertSame('skip', $nas['action']);
        $this->assertNotEmpty($nas['errors']);
    }

    public function test_a_cname_next_to_an_address_is_flagged(): void
    {
        $this->connect();
        $this->rewrites = [['domain' => 'nas.home.example.com', 'answer' => 'other.example.org']];

        $nas = $this->item((new AdguardSync(AdguardClient::fromStored()))->plan()['items'], 'nas.home.example.com|A');

        $this->assertSame('create', $nas['action']);
        $this->assertStringContainsString('CNAME', $nas['warnings'][0]);
    }

    // ------------------------------------------------------------------------- apply

    public function test_apply_writes_only_the_approved_items(): void
    {
        $this->connect();
        $this->rewrites = [['domain' => 'nas.home.example.com', 'answer' => '192.0.2.77']];
        $sync = new AdguardSync(AdguardClient::fromStored());
        $items = $sync->plan()['items'];

        $result = $sync->apply($this->selection($items, 'nas.home.example.com|A'));

        $this->assertSame(1, $result['applied']);
        $this->assertSame([['domain' => 'nas.home.example.com', 'answer' => '192.0.2.20']], $this->rewrites);
        $this->assertContains('PUT /control/rewrite/update', $this->calls);
        $this->assertNotContains('POST /control/rewrite/add', $this->calls);
    }

    public function test_apply_through_the_page_adds_and_reports(): void
    {
        $this->connect();
        $items = (new AdguardSync(AdguardClient::fromStored()))->plan()['items'];
        $key = '*.home.example.com|A';

        $this->get(route('dns.adguard.preview'))->assertOk()->assertSee('*.home.example.com')->assertSee('new rewrite');
        $this->post(route('dns.adguard.apply'), ['apply' => [$key], 'fp' => [$key => $this->item($items, $key)['fingerprint']]])
            ->assertRedirect(route('dns.adguard'))->assertSessionHas('adguard_results');

        $this->assertSame([['domain' => '*.home.example.com', 'answer' => '192.0.2.5']], $this->rewrites);
    }

    public function test_a_change_since_the_preview_refuses_everything(): void
    {
        $this->connect();
        $sync = new AdguardSync(AdguardClient::fromStored());
        $selection = $this->selection($sync->plan()['items'], 'nas.home.example.com|A');
        $this->rewrites[] = ['domain' => 'nas.home.example.com', 'answer' => '192.0.2.77']; // someone edited AdGuard meanwhile

        try {
            $sync->apply($selection);
            $this->fail('expected a refusal');
        } catch (AdguardException $e) {
            $this->assertStringContainsString('changed since the preview', $e->getMessage());
        }
        $this->assertCount(1, $this->rewrites);
    }

    public function test_an_item_that_is_already_in_sync_or_blocked_cannot_be_applied(): void
    {
        $this->connect();
        $this->rewrites = [['domain' => '*.home.example.com', 'answer' => '192.0.2.5']];
        $sync = new AdguardSync(AdguardClient::fromStored());
        $items = $sync->plan()['items'];

        $this->expectException(AdguardException::class);
        $sync->apply($this->selection($items, '*.home.example.com|A'));
    }

    public function test_one_failing_rewrite_does_not_stop_the_others(): void
    {
        $this->connect();
        $sync = new AdguardSync(AdguardClient::fromStored());
        $selection = $this->selection($sync->plan()['items'], '*.home.example.com|A', 'nas.home.example.com|A');
        $this->failAdds = 1;
        $result = $sync->apply($selection);

        $this->assertSame(1, $result['applied']);
        $this->assertContains('failed', array_column($result['results'], 'outcome'));
        $this->assertCount(1, $this->rewrites);
    }

    public function test_an_update_is_a_put_with_the_rewrite_as_adguard_holds_it_as_target(): void
    {
        $this->connect();
        $this->rewrites = [['domain' => 'Nas.Home.Example.com', 'answer' => '192.0.2.77']];
        $sync = new AdguardSync(AdguardClient::fromStored());

        $result = $sync->apply($this->selection($sync->plan()['items'], 'nas.home.example.com|A'));

        $this->assertSame(1, $result['applied'], json_encode($result));
        $this->assertContains('PUT /control/rewrite/update', $this->calls);
        $this->assertNotContains('POST /control/rewrite/update', $this->calls);
        $this->assertSame([[
            'target' => ['domain' => 'Nas.Home.Example.com', 'answer' => '192.0.2.77'],
            'update' => ['domain' => 'nas.home.example.com', 'answer' => '192.0.2.20'],
        ]], $this->updateBodies);
        $this->assertSame([['domain' => 'nas.home.example.com', 'answer' => '192.0.2.20']], $this->rewrites);
    }

    public function test_an_adguard_without_the_update_endpoint_leaves_the_rewrite_alone_and_says_what_to_do(): void
    {
        $this->connect();
        $this->rewrites = [['domain' => 'nas.home.example.com', 'answer' => '192.0.2.77']];
        $this->updateStatus = 404;
        $sync = new AdguardSync(AdguardClient::fromStored());

        $result = $sync->apply($this->selection($sync->plan()['items'], 'nas.home.example.com|A'));

        $this->assertSame(0, $result['applied']);
        $this->assertSame('failed', $result['results'][0]['outcome']);
        $this->assertStringContainsString('by hand', $result['results'][0]['message']);
        $this->assertStringContainsString('PUT /control/rewrite/update', $result['results'][0]['message']);
        $this->assertSame([['domain' => 'nas.home.example.com', 'answer' => '192.0.2.77']], $this->rewrites, 'nothing was deleted or added');
        $this->assertNotContains('POST /control/rewrite/delete', $this->calls);
        $this->assertNotContains('POST /control/rewrite/add', $this->calls);
    }

    public function test_a_failing_update_does_not_stop_the_creates_and_each_item_is_reported(): void
    {
        $this->connect();
        $this->rewrites = [['domain' => 'nas.home.example.com', 'answer' => '192.0.2.77'], ['domain' => 'unrelated.example.me', 'answer' => '192.0.2.99']];
        $this->updateStatus = 500;
        $sync = new AdguardSync(AdguardClient::fromStored());
        $selection = $this->selection($sync->plan()['items'], 'nas.home.example.com|A', '*.home.example.com|A');

        $result = $sync->apply($selection);
        $byKey = array_column($result['results'], 'outcome', 'key');

        $this->assertSame(1, $result['applied']);
        $this->assertSame('failed', $byKey['nas.home.example.com|A']);
        $this->assertSame('created', $byKey['*.home.example.com|A']);
        $this->assertContains(['domain' => '*.home.example.com', 'answer' => '192.0.2.5'], $this->rewrites);
        $this->assertContains(['domain' => 'unrelated.example.me', 'answer' => '192.0.2.99'], $this->rewrites, 'rewrites LabMan has no record for are untouched');
    }

    public function test_a_405_names_the_request_that_was_refused(): void
    {
        $this->connect();
        $this->addStatus = 405;

        try {
            AdguardClient::fromStored()->add('nas.home.example.com', '192.0.2.20');
            $this->fail('expected a failure');
        } catch (AdguardException $e) {
            $this->assertStringContainsString('POST /control/rewrite/add', $e->getMessage());
            $this->assertStringContainsString('405', $e->getMessage());
            $this->assertStringContainsString('by hand', $e->getMessage());
            $this->assertStringNotContainsString('hunter2-test', $e->getMessage());
        }
    }

    public function test_only_a_rewrite_labman_wrote_is_ever_offered_for_deletion(): void
    {
        $this->connect();
        // One rewrite written by someone else for a name the plan does not mention, one written by LabMan earlier.
        $this->rewrites = [['domain' => 'theirs.example.me', 'answer' => '192.0.2.88'], ['domain' => 'old.home.example.com', 'answer' => '192.0.2.66']];
        \App\Models\ManagedRewrite::create(['domain' => 'old.home.example.com', 'answer' => '192.0.2.66']);

        $plan = (new AdguardSync(AdguardClient::fromStored()))->plan();
        $deletes = array_values(array_filter($plan['items'], fn ($i) => $i['action'] === 'delete'));

        $this->assertSame(['delete|old.home.example.com|192.0.2.66'], array_column($deletes, 'key'));
        $this->assertSame(1, $plan['others'], "the other person's rewrite is only counted");
        $this->assertNotContains('POST /control/rewrite/delete', $this->calls, 'a preview deletes nothing');
    }

    public function test_a_ticked_delete_removes_exactly_that_rewrite_and_stops_tracking_it(): void
    {
        $this->connect();
        $this->rewrites = [['domain' => 'theirs.example.me', 'answer' => '192.0.2.88'], ['domain' => 'old.home.example.com', 'answer' => '192.0.2.66']];
        \App\Models\ManagedRewrite::create(['domain' => 'old.home.example.com', 'answer' => '192.0.2.66']);
        $sync = new AdguardSync(AdguardClient::fromStored());

        $result = $sync->apply($this->selection($sync->plan()['items'], 'delete|old.home.example.com|192.0.2.66'));

        $this->assertSame(1, $result['applied']);
        $this->assertSame('deleted', $result['results'][0]['outcome']);
        $this->assertSame([['domain' => 'theirs.example.me', 'answer' => '192.0.2.88']], $this->rewrites);
        $this->assertSame(0, \App\Models\ManagedRewrite::count());
        $this->assertSame([['domain' => 'old.home.example.com', 'answer' => '192.0.2.66']], $this->deleteBodies);
    }

    public function test_rewrites_added_or_changed_by_labman_become_its_own_and_a_delete_cannot_name_anything_else(): void
    {
        $this->connect();
        $this->rewrites = [['domain' => 'nas.home.example.com', 'answer' => '192.0.2.77']];
        $sync = new AdguardSync(AdguardClient::fromStored());
        $sync->apply($this->selection($sync->plan()['items'], '*.home.example.com|A', 'nas.home.example.com|A'));

        $this->assertEqualsCanonicalizing([['*.home.example.com', '192.0.2.5'], ['nas.home.example.com', '192.0.2.20']], \App\Models\ManagedRewrite::query()->get()->map(fn ($m) => [$m->domain, $m->answer])->all());

        // A forged key for a rewrite LabMan never wrote is refused.
        $this->rewrites[] = ['domain' => 'theirs.example.me', 'answer' => '192.0.2.88'];
        $this->expectException(AdguardException::class);
        $sync->apply(['delete|theirs.example.me|192.0.2.88' => str_repeat('a', 40)]);
    }

    public function test_the_automatic_and_default_paths_never_tick_a_delete(): void
    {
        $this->connect();
        $this->rewrites = [['domain' => 'old.home.example.com', 'answer' => '192.0.2.66']];
        \App\Models\ManagedRewrite::create(['domain' => 'old.home.example.com', 'answer' => '192.0.2.66']);

        $html = $this->get(route('dns.adguard.preview'))->assertOk()->assertSee('delete')->getContent();

        $this->assertDoesNotMatchRegularExpression('/name="apply\[\]" value="delete[^"]*" checked/', $html);
    }

    // ------------------------------------------------------------------------- MCP

    private function tool(string $tool, array $args = []): array
    {
        $result = $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => (object) $args]], ['Authorization' => 'Bearer '.$this->adminToken])->assertOk()->json('result');

        return [$result['isError'], $result['structuredContent'] ?? $result['content'][0]['text']];
    }

    public function test_mcp_connect_preview_apply_and_never_leak_the_password(): void
    {
        [$error, $out] = $this->tool('adguard_set_connection', ['url' => self::URL, 'username' => 'lab', 'password' => 'hunter2-test']);
        $this->assertFalse($error, json_encode($out));
        $this->assertStringNotContainsString('hunter2-test', json_encode($out));

        [, $got] = $this->tool('adguard_get_connection');
        $this->assertTrue($got['connected']);
        $this->assertStringNotContainsString('hunter2-test', json_encode($got));

        [$error, $preview] = $this->tool('adguard_preview');
        $this->assertFalse($error);
        $wild = $this->item($preview['items'], '*.home.example.com|A');

        [$error, $applied] = $this->tool('adguard_apply', ['items' => [['key' => $wild['key'], 'fingerprint' => $wild['fingerprint']]]]);
        $this->assertFalse($error, json_encode($applied));
        $this->assertSame(1, $applied['applied']);

        [$error, $stale] = $this->tool('adguard_apply', ['items' => [['key' => $wild['key'], 'fingerprint' => $wild['fingerprint']]]]);
        $this->assertTrue($error, 'the item is already in sync, so applying it again is refused');
    }

    public function test_mcp_tools_are_admin_only_and_honest(): void
    {
        $admin = $this->admin(['email' => 'second@example.com']);
        [, $readToken] = ApiToken::issue($admin, 'read', 'read');
        $names = array_column($this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], ['Authorization' => 'Bearer '.$readToken])->json('result.tools'), 'name');
        $this->assertSame([], array_filter($names, fn ($n) => str_starts_with($n, 'adguard_')));

        $byName = collect(McpServer::registry())->keyBy('name');
        $this->assertTrue($byName['adguard_preview']->readOnly);
        $this->assertFalse($byName['adguard_apply']->readOnly);
        $this->assertSame('admin', $byName['adguard_apply']->scope);
    }

    public function test_an_unconnected_tool_says_so(): void
    {
        [$error, $out] = $this->tool('adguard_preview');

        $this->assertTrue($error);
        $this->assertStringContainsString('not connected', (string) $out);
    }
}
