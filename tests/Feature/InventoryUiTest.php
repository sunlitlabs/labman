<?php

namespace Tests\Feature;

use App\Inventory\ChoiceForm;
use App\Inventory\Inventory;
use App\Inventory\RepoConfig;
use App\Models\ApiToken;
use App\Models\Server;
use App\Models\Setting;
use App\Models\User;
use App\Support\Secrets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\Process\Process;
use Tests\Support\TempRepo;
use Tests\TestCase;

class InventoryUiTest extends TestCase
{
    use RefreshDatabase;

    private const YAML = "all:\n  children:\n    web:\n      hosts:\n        web1:\n          ansible_host: 192.0.2.11\n        web2.example.com:\n    db:\n      hosts:\n        db1:\n          ansible_host: 192.0.2.21\n";

    private User $admin;

    private TempRepo $remote;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->admin(['email' => 'root@example.com']);
        $this->remote = TempRepo::create(['inventory/hosts.yml' => self::YAML]);
    }

    protected function tearDown(): void
    {
        $this->remote->cleanup();
        (new Process(['sh', '-c', 'rm -rf '.escapeshellarg(storage_path('app/inventory-repo')).'*']))->run();

        parent::tearDown();
    }

    private function form(array $overrides = []): array
    {
        return $overrides + [
            'repo_url' => $this->remote->remote, 'branch' => 'main', 'path' => 'inventory/hosts.yml', 'username' => 'ci-user',
            'token' => 'pat-secret-456', 'author_name' => 'LabMan', 'author_email' => 'labman@localhost',
        ];
    }

    private function connect(): void
    {
        $this->actingAs($this->admin)->post('/inventory/connection', $this->form())->assertSessionHasNoErrors();
    }

    // -------------------------------------------------------------- connection

    public function test_connecting_clones_the_repository_and_stores_the_token_encrypted(): void
    {
        $this->actingAs($this->admin)->post('/inventory/connection', $this->form())
            ->assertRedirect(route('inventory.compare'));

        $this->assertStringContainsString('Read 3 host(s)', session('status'));

        $config = RepoConfig::get();
        $this->assertSame('pat-secret-456', $config['token']);
        $this->assertSame('ci-user', $config['username']);
        $this->assertSame('inventory/hosts.yml', $config['path']);

        $this->assertStringNotContainsString('pat-secret-456', (string) Setting::whereKey('inventory.token')->value('value'));
        $this->assertArrayNotHasKey('token', RepoConfig::summary());
        $this->actingAs($this->admin)->get('/inventory')->assertOk()->assertSee('Compare now')->assertDontSee('pat-secret-456');
        $this->assertStringNotContainsString('inventory', \App\Config\YamlConfig::export());
    }

    public function test_connection_problems_are_explained_and_nothing_is_saved(): void
    {
        $this->actingAs($this->admin);

        config(['labman.git.allow_local' => false]);
        foreach (['https://u:p@example.com/x.git', '/tmp/x', 'file:///tmp/x', 'https://example.com', 'ftp://example.com/x.git'] as $url) {
            $this->post('/inventory/connection', $this->form(['repo_url' => $url]))->assertSessionHasErrors('repo_url');
        }
        config(['labman.git.allow_local' => true]);

        $this->post('/inventory/connection', $this->form(['path' => '../etc/passwd']))->assertSessionHasErrors('path');
        $this->post('/inventory/connection', $this->form(['branch' => 'a..b']))->assertSessionHasErrors('repo_url');
        $this->post('/inventory/connection', $this->form(['token' => '']))->assertSessionHasErrors('token');
        $this->post('/inventory/connection', $this->form(['username' => 'bad user!']))->assertSessionHasErrors('username');
        $this->post('/inventory/connection', $this->form(['author_email' => "a\nb"]))->assertSessionHasErrors('repo_url');

        $this->post('/inventory/connection', $this->form(['path' => 'wrong/file.yml']))->assertSessionHasErrors('path');
        $this->assertStringContainsString("'wrong/file.yml' does not exist", session('errors')->first('path'));
        $this->assertStringContainsString('inventory/hosts.yml', session('errors')->first('path'), 'inventory-like files are suggested');

        $this->post('/inventory/connection', $this->form(['repo_url' => $this->remote->base.'/nope.git']))->assertSessionHasErrors('repo_url');

        $this->assertFalse(RepoConfig::exists());
    }

    public function test_a_broken_inventory_file_is_reported_at_connect_time(): void
    {
        $this->remote->commitFromElsewhere(['inventory/bad.yml' => "all: [unclosed\n"], 'bad');

        $this->actingAs($this->admin)->post('/inventory/connection', $this->form(['path' => 'inventory/bad.yml']))->assertSessionHasErrors('repo_url');
        $this->assertStringContainsString('not valid YAML', session('errors')->first('repo_url'));
    }

    public function test_the_saved_token_is_never_reused_for_another_repository(): void
    {
        $this->connect();
        $other = TempRepo::create(['inventory/hosts.yml' => "all:\n"]);

        $this->post('/inventory/connection', $this->form(['token' => '']))->assertSessionHasNoErrors(); // same repo: fine
        $this->post('/inventory/connection', $this->form(['token' => '', 'repo_url' => $other->remote]))->assertSessionHasErrors('token');
        $this->assertSame($this->remote->remote, RepoConfig::get()['url']);

        $other->cleanup();
    }

    public function test_disconnecting_deletes_the_token(): void
    {
        $this->connect();

        $this->delete('/inventory/connection')->assertRedirect(route('inventory.index'));

        $this->assertFalse(RepoConfig::exists());
        $this->assertSame(0, Setting::where('key', 'like', 'inventory.%')->count());
    }

    public function test_every_inventory_route_is_admin_only(): void
    {
        $routes = [['get', '/inventory'], ['post', '/inventory/connection'], ['delete', '/inventory/connection'], ['get', '/inventory/compare'], ['post', '/inventory/review'], ['post', '/inventory/apply']];

        foreach ($routes as [$method, $uri]) {
            $this->{$method}($uri)->assertRedirect(route('login'));
        }
        $this->actingAs($this->user());
        foreach ($routes as [$method, $uri]) {
            $this->{$method}($uri)->assertForbidden();
        }
    }

    // ------------------------------------------------------- compare / review / apply

    public function test_compare_without_a_connection_sends_you_to_connect(): void
    {
        $this->actingAs($this->admin)->get('/inventory/compare')->assertRedirect(route('inventory.index'))->assertSessionHasErrors('inventory');
    }

    public function test_the_compare_page_shows_the_four_groups_and_the_choices(): void
    {
        $this->connect();
        Server::create(['name' => 'web1', 'address' => '192.0.2.11', 'group' => 'web']);
        $db = Server::create(['name' => 'db1', 'address' => '192.0.2.99', 'group' => 'db']);
        $only = Server::create(['name' => 'Only Here', 'address' => '192.0.2.50']);

        $page = $this->get('/inventory/compare')->assertOk();

        $page->assertSee('1 in sync')->assertSee('1 differ')->assertSee('1 only in LabMan')->assertSee('1 only in the inventory')
            ->assertSee('Different')->assertSee('Only in LabMan')->assertSee('Only in the inventory')
            ->assertSee('name="fields[pair:'.$db->id.'][address]"', false)
            ->assertSee('name="action[srv:'.$only->id.']"', false)
            ->assertSee('value="add_inventory"', false)->assertSee('Add to inventory as')->assertSee('Only-Here')
            ->assertSee('name="action[host:web2.example.com]"', false)->assertSee('name="setup[host:web2.example.com][name]"', false)
            ->assertSee('Review changes')->assertSee('js/inventory.js', false);
    }

    public function test_the_compare_page_says_when_everything_lines_up(): void
    {
        $this->connect();
        Server::create(['name' => 'web1', 'address' => '192.0.2.11', 'group' => 'web']);
        Server::create(['name' => 'db1', 'address' => '192.0.2.21', 'group' => 'db']);
        Server::create(['name' => 'web2.example.com', 'address' => 'web2.example.com', 'group' => 'web']);

        $this->get('/inventory/compare')->assertOk()->assertSee('Everything lines up')->assertDontSee('Only in LabMan');
    }

    public function test_a_clean_compare_still_states_exactly_what_was_checked(): void
    {
        $this->connect();
        Server::create(['name' => 'web1', 'address' => '192.0.2.11', 'group' => 'web']);
        Server::create(['name' => 'db1', 'address' => '192.0.2.21', 'group' => 'db']);
        Server::create(['name' => 'web2.example.com', 'address' => 'web2.example.com', 'group' => 'web']);

        $page = $this->get('/inventory/compare')->assertOk()
            ->assertSee('Checked commit')->assertSee('3 in sync, 0 differences')
            ->assertSee('Compared 3 hosts with 3 servers')->assertSee('Nothing to do')
            ->assertSee('data-progress=', false)->assertSee('Pulling the latest commit');

        $head = substr($this->remote->remoteHead(), 0, 7);
        $page->assertSee($head);

        // The same sentence is kept for the connection page.
        $this->get('/inventory')->assertOk()->assertSee('Last check')->assertSee('3 in sync, 0 differences')->assertSee('data-progress=', false);
    }

    public function test_a_failed_compare_says_why_and_is_remembered(): void
    {
        $this->connect();
        RepoConfig::setPath('inventory/missing.yml');

        $this->get('/inventory/compare')->assertRedirect(route('inventory.index'))->assertSessionHasErrors('inventory');

        $page = $this->followingRedirects()->get('/inventory/compare')->assertOk();
        $page->assertSee('The check failed')->assertSee('inventory/missing.yml')->assertSee('Last check')->assertSee('failed');
    }

    public function test_the_check_sentence_counts_each_kind_of_difference(): void
    {
        $sentence = \App\Inventory\CheckStatus::sentence(['head' => 'abcdef0123', 'branch' => 'main', 'in_sync' => 11, 'differ' => 1, 'only_labman' => 2, 'only_inventory' => 0]);

        $this->assertSame('Checked commit abcdef0 on main: 11 in sync, 1 difference, 2 only in LabMan.', $sentence);
    }

    public function test_hostile_names_in_the_inventory_are_escaped(): void
    {
        $this->remote->commitFromElsewhere(['inventory/hosts.yml' => "all:\n  hosts:\n    '<script>alert(1)</script>':\n"], 'evil');
        $this->connect();

        $html = $this->get('/inventory/compare')->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function test_the_last_push_is_recorded_and_shown_and_the_yaml_can_be_created_on_its_own(): void
    {
        $remote = TempRepo::create(['inventory/hosts.ini' => "[web]\nweb1 ansible_host=192.0.2.11\n"]);
        $this->actingAs($this->admin)->post('/inventory/connection', $this->form(['repo_url' => $remote->remote, 'path' => 'inventory/hosts.ini']))->assertSessionHasNoErrors();

        $page = $this->post('/inventory/review', ['convert' => 1, 'delete_old' => 1])->assertOk()->assertSee('name="convert"', false)->assertSee('data-busy', false)->getContent();
        preg_match('/name="fingerprint" value="([0-9a-f]{40})"/', $page, $f);
        $this->post('/inventory/apply', ['choices' => '{}', 'fingerprint' => $f[1], 'convert' => 1, 'delete_old' => 1])->assertRedirect(route('inventory.index'));

        $this->assertNull($remote->remoteFile('inventory/hosts.ini'));
        $this->assertNotNull($remote->remoteFile('inventory/hosts.yml'));
        $this->get('/inventory')->assertSee('Last sync')->assertSee('pushed');

        $this->post('/inventory/apply', ['choices' => '{}', 'fingerprint' => str_repeat('a', 40)])->assertRedirect(route('inventory.compare'));
        $this->get('/inventory/compare')->assertSee('Last sync')->assertSee('failed');

        $remote->cleanup();
    }

    public function test_an_ini_inventory_shows_the_conversion_notice(): void
    {
        $remote = TempRepo::create(['inventory/hosts.ini' => "[web]\nweb1 ansible_host=192.0.2.11\n"]);
        $this->actingAs($this->admin)->post('/inventory/connection', $this->form(['repo_url' => $remote->remote, 'path' => 'inventory/hosts.ini']))->assertSessionHasNoErrors();

        $this->get('/inventory/compare')->assertOk()->assertSee('This inventory is INI')->assertSee('inventory/hosts.yml')->assertSee('name="delete_old"', false);

        $remote->cleanup();
    }

    public function test_the_full_flow_through_the_pages(): void
    {
        $this->connect();
        $web1 = Server::create(['name' => 'web1', 'address' => '192.0.2.11', 'group' => 'web']);
        $db = Server::create(['name' => 'db1', 'address' => '192.0.2.99', 'group' => 'db']);
        $only = Server::create(['name' => 'Only Here', 'address' => '192.0.2.50', 'ssh_user' => 'ops']);

        // Review: the form fields exactly as the compare page names them.
        $review = $this->post('/inventory/review', [
            'fields' => ['pair:'.$db->id => ['address' => 'inventory']],
            'action' => ['srv:'.$only->id => 'add_inventory', 'host:web2.example.com' => 'add_labman'],
            'setup' => ['host:web2.example.com' => ['name' => 'web2', 'address' => 'web2.example.com', 'group' => 'web', 'ssh_user' => '', 'ssh_port' => '22']],
        ])->assertOk();

        $review->assertSee('Review before anything is written')->assertSee('Add host Only-Here')->assertSee('Set address of db1 to 192.0.2.21')
            ->assertSee('Create server web2')->assertSee('Commit, push and apply')->assertSee('ansible_user: ops');

        // Apply with the hidden fields from the review page.
        preg_match('/name="choices" value="([^"]+)"/', $review->getContent(), $c);
        preg_match('/name="fingerprint" value="([0-9a-f]{40})"/', $review->getContent(), $f);
        $this->assertNotEmpty($c);
        $this->assertNotEmpty($f);

        $this->post('/inventory/apply', ['choices' => html_entity_decode($c[1]), 'fingerprint' => $f[1]])
            ->assertRedirect(route('inventory.index'));

        $this->assertStringContainsString('pushed commit', session('status'));
        $this->assertStringContainsString('LabMan change', session('status'));

        $hosts = Inventory::parse((string) $this->remote->remoteFile('inventory/hosts.yml'), 'x.yml')->hosts();
        $this->assertSame('ops', $hosts['Only-Here']['vars']['ansible_user']);
        $this->assertSame('192.0.2.21', $db->fresh()->address);
        $this->assertSame('web2', Server::where('name', 'web2')->value('name'));
        $this->assertSame('web2.example.com', Server::where('name', 'web2')->value('inventory_host'));
        $this->assertSame('Update inventory from LabMan', $this->remote->log()[0]);

        // And the comparison now shows no differences.
        $this->get('/inventory/compare')->assertOk()->assertSee('Everything lines up');
    }

    public function test_a_stale_review_is_refused_with_a_message_and_changes_nothing(): void
    {
        $this->connect();
        $only = Server::create(['name' => 'Only Here', 'address' => '192.0.2.50']);

        $review = $this->post('/inventory/review', ['action' => ['srv:'.$only->id => 'add_inventory']])->assertOk();
        preg_match('/name="choices" value="([^"]+)"/', $review->getContent(), $c);
        preg_match('/name="fingerprint" value="([0-9a-f]{40})"/', $review->getContent(), $f);

        $this->remote->commitFromElsewhere(['inventory/hosts.yml' => self::YAML."\n"], 'someone else');
        $head = $this->remote->remoteHead();

        $this->post('/inventory/apply', ['choices' => html_entity_decode($c[1]), 'fingerprint' => $f[1]])
            ->assertRedirect(route('inventory.compare'))->assertSessionHasErrors('inventory');

        $this->assertStringContainsString('changed since you reviewed', session('errors')->first('inventory'));
        $this->assertSame($head, $this->remote->remoteHead());
        $this->assertNull($only->fresh()->inventory_host);
    }

    public function test_a_bad_choice_goes_back_to_the_compare_page_with_the_error(): void
    {
        $this->connect();
        $this->actingAs($this->admin)->post('/inventory/review', ['action' => ['srv:9999' => 'delete_labman']])
            ->assertRedirect(route('inventory.compare'))->assertSessionHasErrors('inventory');
    }

    public function test_apply_rejects_forged_input(): void
    {
        $this->connect();
        $only = Server::create(['name' => 'Only Here', 'address' => '192.0.2.50']);
        $this->actingAs($this->admin);

        $this->post('/inventory/apply', ['choices' => '{"srv:'.$only->id.'":{"action":"delete_labman"}}', 'fingerprint' => str_repeat('a', 40)])->assertSessionHasErrors('inventory');
        $this->post('/inventory/apply', ['choices' => 'not json', 'fingerprint' => str_repeat('a', 40)])->assertSessionHasErrors('inventory');
        $this->post('/inventory/apply', ['choices' => '{}', 'fingerprint' => 'short'])->assertSessionHasErrors('fingerprint');

        $this->assertNotNull(Server::find($only->id));
    }

    public function test_form_input_is_normalised_into_choices(): void
    {
        $request = Request::create('/x', 'POST', [
            'action' => ['srv:1' => 'leave', 'srv:2' => 'add_inventory', 'host:a.b' => 'add_labman', 'host:c' => 'leave'],
            'fields' => ['pair:3' => ['address' => 'skip', 'ssh_port' => 'inventory'], 'pair:4' => ['group' => 'skip']],
            'setup' => ['host:a.b' => ['name' => ' a ', 'address' => '', 'ssh_port' => '2200'], 'host:c' => ['name' => 'ignored']],
        ]);

        $this->assertSame([
            'host:a.b' => ['action' => 'add_labman', 'setup' => ['name' => 'a', 'ssh_port' => '2200']],
            'pair:3' => ['fields' => ['ssh_port' => 'inventory']],
            'srv:2' => ['action' => 'add_inventory'],
        ], ChoiceForm::fromRequest($request));

        $this->assertSame(ChoiceForm::fromRequest($request), ChoiceForm::fromJson(ChoiceForm::toJson(ChoiceForm::fromRequest($request))));
        $this->assertSame([], ChoiceForm::fromJson('garbage'));
    }

    // ---------------------------------------------------------------- MCP tools

    private function tool(string $name, array $args = []): array
    {
        static $token = null;
        $token ??= ApiToken::issue($this->admin, 'agent', 'admin')[1];
        $r = $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => (object) $args]], ['Authorization' => 'Bearer '.$token])->json('result');

        return [$r['isError'], $r['structuredContent'] ?? $r['content'][0]['text']];
    }

    public function test_the_mcp_tools_follow_compare_review_apply(): void
    {
        // Each test gets its own token (the static above is per test class run, so reset it).
        [, $token] = ApiToken::issue($this->admin, 'agent', 'admin');
        $call = function (string $name, array $args = []) use ($token) {
            $r = $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => (object) $args]], ['Authorization' => 'Bearer '.$token])->json('result');

            return [$r['isError'], $r['structuredContent'] ?? $r['content'][0]['text']];
        };

        [$error, $out] = $call('inventory_set_connection', ['repo_url' => $this->remote->remote, 'path' => 'inventory/hosts.yml', 'username' => 'ci', 'token' => 'pat-secret-456']);
        $this->assertFalse($error, json_encode($out));
        $this->assertSame(3, $out['hosts']);
        $this->assertStringNotContainsString('pat-secret-456', json_encode($out));
        [, $conn] = $call('inventory_get_connection');
        $this->assertTrue($conn['connected']);
        $this->assertStringNotContainsString('pat-secret-456', json_encode($conn));

        Server::create(['name' => 'Only Here', 'address' => '192.0.2.50']);
        $db = Server::create(['name' => 'db1', 'address' => '192.0.2.99', 'group' => 'db']);

        [$error, $plan] = $call('inventory_compare');
        $this->assertFalse($error, json_encode($plan));
        $this->assertSame(['Only Here'], array_column($plan['only_labman'], 'name'));
        $this->assertSame(['web1', 'web2.example.com'], array_column($plan['only_inventory'], 'host'), 'unmatched hosts are listed');
        $this->assertArrayNotHasKey('candidates', $plan);

        $choices = ['srv:'.$plan['only_labman'][0]['server_id'] => ['action' => 'add_inventory'], 'pair:'.$db->id => ['fields' => ['address' => 'inventory']]];

        [$error, $review] = $call('inventory_review', ['choices' => $choices]);
        $this->assertFalse($error, json_encode($review));
        $this->assertStringContainsString('Only-Here', $review['diff']);
        $this->assertSame(40, strlen($review['fingerprint']));
        $this->assertSame([], array_diff(array_keys($review), ['fingerprint', 'diff', 'labman', 'inventory', 'commit_message', 'convert', 'changes', 'head']));

        // Wrong fingerprint refused; right one applies.
        [$error, $text] = $call('inventory_apply', ['choices' => $choices, 'fingerprint' => str_repeat('0', 40)]);
        $this->assertTrue($error);
        $this->assertStringContainsString('changed since you reviewed', $text);

        [$error, $applied] = $call('inventory_apply', ['choices' => $choices, 'fingerprint' => $review['fingerprint']]);
        $this->assertFalse($error, json_encode($applied));
        $this->assertTrue($applied['pushed']);
        $this->assertArrayHasKey('Only-Here', Inventory::parse((string) $this->remote->remoteFile('inventory/hosts.yml'), 'x.yml')->hosts());
        $this->assertSame('192.0.2.21', $db->fresh()->address);

        [, $gone] = $call('inventory_remove_connection');
        $this->assertFalse($gone['connected']);
        $this->assertFalse(RepoConfig::exists());
        $this->assertNull(Secrets::get('inventory.token'));
    }
}
