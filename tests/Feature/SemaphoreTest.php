<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Server;
use App\Models\Setting;
use App\Models\User;
use App\Semaphore\Connection;
use App\Semaphore\RunOptions;
use App\Semaphore\SemaphoreException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeSemaphore;
use Tests\TestCase;

class SemaphoreTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private FakeSemaphore $sem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->admin(['email' => 'root@example.com']);
        $this->sem = FakeSemaphore::install();
    }

    private function connect(): void
    {
        Connection::save(FakeSemaphore::URL, 'sem-token', true);
    }

    // ------------------------------------------------------------- connection

    public function test_connecting_tests_the_token_and_stores_it_encrypted(): void
    {
        $this->actingAs($this->admin)->post('/semaphore/connection', ['url' => 'http://semaphore.test:3000/', 'token' => 'sem-token', 'verify_tls' => 1])
            ->assertRedirect(route('semaphore.index'));

        $this->assertSame(['url' => 'http://semaphore.test:3000', 'token' => 'sem-token', 'verify_tls' => true], Connection::get());
        $this->assertStringContainsString('v2.12.0', session('status'));

        $this->assertStringNotContainsString('sem-token', (string) Setting::whereKey('semaphore.token')->value('value'));
        $this->assertArrayNotHasKey('token', Connection::summary());
        $this->actingAs($this->admin)->get('/semaphore')->assertOk()->assertDontSee('sem-token');
        $this->assertStringNotContainsString('semaphore', \App\Config\YamlConfig::export());
    }

    public function test_a_wrong_token_or_address_is_rejected_and_not_saved(): void
    {
        $this->actingAs($this->admin);

        $this->post('/semaphore/connection', ['url' => 'http://semaphore.test:3000', 'token' => 'wrong'])->assertSessionHasErrors('url');
        $this->assertStringContainsString('rejected the API token', session('errors')->first('url'));

        foreach (['ftp://semaphore.test', 'semaphore.test:3000', 'http://u:p@semaphore.test', 'javascript:alert(1)', 'http://bad host/'] as $url) {
            $this->post('/semaphore/connection', ['url' => $url, 'token' => 'sem-token'])->assertSessionHasErrors('url');
        }

        $this->post('/semaphore/connection', ['url' => 'http://semaphore.test:3000'])->assertSessionHasErrors('url');
        $this->assertFalse(Connection::exists());
    }

    public function test_the_saved_token_is_never_reused_for_another_address(): void
    {
        $this->connect();
        $this->actingAs($this->admin);

        // Same address, blank token: allowed.
        $this->post('/semaphore/connection', ['url' => 'http://semaphore.test:3000', 'verify_tls' => 1])->assertSessionHasNoErrors();

        // Different address, blank token: refused (the saved token must not be sent elsewhere).
        $before = count($this->sem->requests);
        $this->post('/semaphore/connection', ['url' => 'http://other.example.com:3000'])->assertSessionHasErrors('url');
        $this->assertCount($before, $this->sem->requests, 'nothing was sent to the other address');
        $this->assertSame('http://semaphore.test:3000', Connection::get()['url']);
    }

    public function test_an_unreachable_semaphore_is_explained(): void
    {
        \Illuminate\Support\Facades\Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 7: Failed to connect: Connection refused'));

        $this->actingAs($this->admin)->post('/semaphore/connection', ['url' => 'http://semaphore.test:3000', 'token' => 'x'])->assertSessionHasErrors('url');
        $this->assertStringContainsString('connection refused', session('errors')->first('url'));
    }

    public function test_every_semaphore_route_is_admin_only(): void
    {
        $routes = [['get', '/semaphore'], ['post', '/semaphore/connection'], ['delete', '/semaphore/connection'], ['get', '/semaphore/run'], ['post', '/semaphore/run/confirm'],
            ['post', '/semaphore/run'], ['get', '/semaphore/tasks/1/100'], ['get', '/semaphore/tasks/1/100/status'], ['post', '/semaphore/tasks/1/100/stop']];

        foreach ($routes as [$method, $uri]) {
            $this->{$method}($uri)->assertRedirect(route('login'));
        }
        $this->actingAs($this->user());
        foreach ($routes as [$method, $uri]) {
            $this->{$method}($uri)->assertForbidden();
        }
        $this->assertSame([], $this->sem->requests, 'a refused request never reaches Semaphore');
    }

    // ------------------------------------------------------------------ screens

    public function test_the_index_lists_projects_templates_and_recent_runs(): void
    {
        $this->connect();
        $this->sem->templates[0]['name'] = '<script>alert(1)</script>';
        $this->sem->tasks[55] = ['id' => 55, 'status' => 'success', 'template_id' => 7, 'tpl_alias' => 'Update packages', 'created' => '2026-01-01T00:00:00Z', 'limit' => 'web1', 'message' => 'weekly'];

        $page = $this->actingAs($this->admin)->get('/semaphore')->assertOk()
            ->assertSee('Home Lab')->assertSee('Deploy app')->assertSee('playbooks/update.yml')->assertSee('Recent runs')->assertSee('weekly');

        $this->assertStringNotContainsString('<script>alert(1)</script>', $page->getContent());
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $page->getContent());
    }

    public function test_semaphore_errors_are_shown_not_thrown(): void
    {
        $this->connect();
        $this->sem->token = 'rotated';

        $this->actingAs($this->admin)->get('/semaphore')->assertOk()->assertSee('rejected the API token');
    }

    // ---------------------------------------------------------------- running

    private function runInput(array $overrides = []): array
    {
        return $overrides + ['project_id' => 1, 'template_id' => 7];
    }

    public function test_the_run_form_offers_labman_servers_and_groups_as_targets(): void
    {
        $this->connect();
        Server::create(['name' => 'Docker Host', 'address' => '192.0.2.20', 'group' => 'Home Lab', 'inventory_host' => 'docker-1']);
        Server::create(['name' => 'nas', 'address' => '192.0.2.10']);

        $this->actingAs($this->admin)->get('/semaphore/run?project=1&template=7')->assertOk()
            ->assertSee('Update packages')->assertSee('value="docker-1"', false)->assertSee('value="nas"', false)->assertSee('value="Home_Lab"', false)
            ->assertSee('Check mode');

        $this->get('/semaphore/run?project=1&template=999')->assertRedirect(route('semaphore.index'))->assertSessionHasErrors('semaphore');
    }

    public function test_confirming_shows_exactly_what_will_run_and_starts_nothing(): void
    {
        $this->connect();

        $this->actingAs($this->admin)->post('/semaphore/run/confirm', $this->runInput([
            'groups' => ['web'], 'hosts' => ['db1'], 'limit_extra' => 'node*', 'dry_run' => 1, 'extra_vars' => '{"version": "1.2"}', 'message' => 'weekly patching',
        ]))->assertOk()
            ->assertSee('Update packages')->assertSee('web,db1,node*')->assertSee('Check mode: nothing is changed')
            ->assertSee('{&quot;version&quot;:&quot;1.2&quot;}', false)->assertSee('weekly patching')->assertSee('Run now');

        $this->assertSame([], $this->sem->posts(), 'the confirmation step never calls Semaphore');
    }

    public function test_a_run_without_a_limit_is_called_out(): void
    {
        $this->connect();

        $this->actingAs($this->admin)->post('/semaphore/run/confirm', $this->runInput())->assertOk()
            ->assertSee('no limit')->assertSee('real changes on every host');
    }

    public function test_starting_needs_the_confirmation_and_then_follows_the_task(): void
    {
        $this->connect();
        $this->actingAs($this->admin);
        $fields = ['project_id' => 1, 'template_id' => 7, 'limit' => 'web1,db1', 'dry_run' => 1, 'diff' => 0, 'extra_vars' => '{"version":"1.2"}', 'message' => 'weekly'];

        // Not confirmed: nothing starts.
        $this->post('/semaphore/run', $fields)->assertSessionHasErrors('confirm');
        $this->assertSame([], $this->sem->posts());

        $this->post('/semaphore/run', $fields + ['confirm' => 1])->assertRedirect(route('semaphore.task', [1, 100]));

        $sent = $this->sem->posts()[0]['body'];
        $this->assertSame(['template_id' => 7, 'dry_run' => true, 'diff' => false, 'limit' => 'web1,db1', 'environment' => '{"version":"1.2"}', 'message' => 'weekly'], $sent);

        $this->sem->tasks[100]['status'] = 'running';
        $this->sem->output[100] = ['PLAY [all]', 'TASK [ping]'];
        $this->get('/semaphore/tasks/1/100')->assertOk()->assertSee('Run #100')->assertSee('running')->assertSee('PLAY [all]')->assertSee('Stop')->assertSee('data-task-url', false);

        $this->sem->output[100][] = 'ok: [web1]';
        $this->getJson('/semaphore/tasks/1/100/status?since=2')->assertOk()
            ->assertJsonPath('status', 'running')->assertJsonPath('finished', false)->assertJsonPath('total', 3)->assertExactJson(['status' => 'running', 'finished' => false, 'total' => 3, 'lines' => ['ok: [web1]']]);

        $this->sem->tasks[100]['status'] = 'success';
        $this->getJson('/semaphore/tasks/1/100/status?since=3')->assertJsonPath('finished', true)->assertJsonPath('lines', []);
        $this->get('/semaphore/tasks/1/100')->assertOk()->assertDontSee('id="stop-form"', false);
    }

    public function test_a_run_can_be_stopped(): void
    {
        $this->connect();
        $this->sem->tasks[100] = ['id' => 100, 'status' => 'running', 'template_id' => 7];

        $this->actingAs($this->admin)->post('/semaphore/tasks/1/100/stop')->assertRedirect(route('semaphore.task', [1, 100]));

        $this->assertSame('stopped', $this->sem->tasks[100]['status']);
    }

    public function test_task_output_is_escaped(): void
    {
        $this->connect();
        $this->sem->tasks[100] = ['id' => 100, 'status' => 'success', 'template_id' => 7];
        $this->sem->output[100] = ['<img src=x onerror=alert(1)>'];

        $page = $this->actingAs($this->admin)->get('/semaphore/tasks/1/100')->assertOk();

        $this->assertStringNotContainsString('<img src=x', $page->getContent());
        $this->assertStringContainsString('&lt;img src=x', $page->getContent());
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function badRuns(): array
    {
        return [
            'bad limit char' => [['limit' => 'web1; reboot'], "'web1; reboot' is not a valid host"],
            'limit with space' => [['limit' => 'web 1'], 'not a valid host'],
            'limit flag' => [['limit' => '--check'], 'not a valid host'],
            'limit too long' => [['limit' => str_repeat('a,', 300)], 'too long'],
            'vars not json' => [['extra_vars' => 'nope'], 'must be a JSON object'],
            'vars a list' => [['extra_vars' => '[1,2]'], 'not a list'],
            'vars bad name' => [['extra_vars' => '{"a b": 1}'], 'not a valid variable name'],
            'vars too big' => [['extra_vars' => json_encode(['a' => str_repeat('x', 11000)])], 'too large'],
            'message multiline' => [['message' => "a\nb"], 'one line'],
            'message long' => [['message' => str_repeat('m', 201)], 'one line'],
            'no project' => [['project_id' => 0], 'Choose a project'],
            'no template' => [['template_id' => 'abc'], 'Choose a template'],
        ];
    }

    #[DataProvider('badRuns')]
    public function test_run_options_are_validated(array $overrides, string $message): void
    {
        $this->expectException(SemaphoreException::class);
        $this->expectExceptionMessage($message);

        RunOptions::validate($overrides + ['project_id' => 1, 'template_id' => 7]);
    }

    public function test_bad_run_requests_never_reach_semaphore(): void
    {
        $this->connect();
        $this->actingAs($this->admin);

        $this->post('/semaphore/run/confirm', $this->runInput(['limit_extra' => 'a;b']))->assertRedirect()->assertSessionHasErrors('semaphore');
        $this->post('/semaphore/run', $this->runInput(['limit' => 'a;b', 'confirm' => 1]))->assertRedirect(route('semaphore.index'))->assertSessionHasErrors('semaphore');

        $this->assertSame([], $this->sem->posts());
    }

    public function test_valid_limits_and_options_are_normalised(): void
    {
        $o = RunOptions::validate(['project_id' => '1', 'template_id' => '7', 'limit' => ' web*, !web3 ,db[01:03],web*', 'dry_run' => 'on', 'diff' => '0', 'extra_vars' => ['a' => 1, 'b_c' => ['x' => 'y']]]);

        $this->assertSame('web*,!web3,db[01:03]', $o['limit']);
        $this->assertTrue($o['dry_run']);
        $this->assertFalse($o['diff']);
        $this->assertSame(['template_id' => 7, 'dry_run' => true, 'diff' => false, 'limit' => 'web*,!web3,db[01:03]', 'environment' => '{"a":1,"b_c":{"x":"y"}}'], RunOptions::payload($o));
        $this->assertSame([], array_diff_key(RunOptions::payload(RunOptions::validate(['project_id' => 1, 'template_id' => 7])), ['template_id' => 1, 'dry_run' => 1, 'diff' => 1]), 'no limit, vars or message when none are given');
    }


    // -------------------------------------------------------- survey variables

    public function test_survey_variables_are_read_from_the_template(): void
    {
        $declared = \App\Semaphore\SurveyVars::declared($this->sem->templates[0]);

        $this->assertSame(['version', 'count', 'color', 'api_key'], array_keys($declared));
        $this->assertSame('string', $declared['version']['type']);
        $this->assertSame('int', $declared['count']['type']);
        $this->assertSame(['red', 'blue'], array_column($declared['color']['values'], 'value'));
        $this->assertSame([], \App\Semaphore\SurveyVars::declared($this->sem->templates[1]));
    }

    public function test_the_run_form_offers_the_templates_variables_as_fields(): void
    {
        $this->connect();

        $this->actingAs($this->admin)->get('/semaphore/run?project=1&template=7')->assertOk()
            ->assertSee('App version')->assertSee('name="survey[version]"', false)
            ->assertSee('How many')->assertSee('type="number"', false)
            ->assertSee('name="survey[color]"', false)->assertSee('value="red"', false)->assertSee('Blue')
            ->assertDontSee('name="survey[api_key]"', false)
            ->assertSee('Extra variables as JSON (advanced)');

        $this->get('/semaphore/run?project=1&template=8')->assertOk()->assertSee('declares no variables');
    }

    public function test_variables_are_checked_against_the_template_before_anything_is_sent(): void
    {
        $this->connect();
        $this->actingAs($this->admin);
        $base = ['project_id' => 1, 'template_id' => 7];

        // Typed fields are merged, coerced and win over the JSON box.
        $this->post('/semaphore/run/confirm', $base + ['survey' => ['version' => '2.0', 'count' => '3', 'color' => 'blue'], 'extra_vars' => '{"version": "ignored"}'])
            ->assertOk()->assertSee('{&quot;version&quot;:&quot;2.0&quot;,&quot;count&quot;:3,&quot;color&quot;:&quot;blue&quot;}', false);

        $bad = [
            'undeclared name' => [['extra_vars' => '{"nope": 1}'], "'nope' is not a variable this template declares. It accepts: version, count, color, api_key."],
            'int not a number' => [['survey' => ['count' => 'many']], 'How many must be a whole number'],
            'int with decimals' => [['survey' => ['count' => '2.5']], 'must be a whole number'],
            'enum not offered' => [['survey' => ['color' => 'green']], 'Colour must be one of: red, blue'],
            'secret' => [['extra_vars' => '{"api_key": "x"}'], 'is a secret variable'],
        ];

        foreach ($bad as $label => [$input, $message]) {
            $this->post('/semaphore/run/confirm', $base + $input)->assertRedirect()->assertSessionHasErrors('semaphore');
            $this->assertStringContainsString($message, session('errors')->first('semaphore'), $label);
        }

        $this->assertSame([], $this->sem->posts(), 'nothing was sent to Semaphore');
    }

    public function test_required_variables_must_be_given(): void
    {
        $this->connect();
        $this->sem->templates[0]['survey_vars'][1]['required'] = true;

        $this->actingAs($this->admin)->post('/semaphore/run/confirm', ['project_id' => 1, 'template_id' => 7, 'survey' => ['version' => '1']])
            ->assertRedirect()->assertSessionHasErrors('semaphore');
        $this->assertStringContainsString("needs the variable 'count' (How many)", session('errors')->first('semaphore'));

        $this->post('/semaphore/run', ['project_id' => 1, 'template_id' => 7, 'confirm' => 1, 'extra_vars' => '{"version":"1"}'])
            ->assertRedirect(route('semaphore.index'))->assertSessionHasErrors('semaphore');
        $this->assertSame([], $this->sem->posts(), 'the start step checks again');
    }

    public function test_typed_variables_reach_semaphore_with_their_types(): void
    {
        $this->connect();

        $this->actingAs($this->admin)->post('/semaphore/run', ['project_id' => 1, 'template_id' => 7, 'confirm' => 1, 'extra_vars' => '{"version":"2.0","count":3}'])
            ->assertRedirect(route('semaphore.task', [1, 100]));

        $this->assertSame('{"version":"2.0","count":3}', $this->sem->posts()[0]['body']['environment']);
    }

    public function test_a_refusal_from_semaphore_is_explained(): void
    {
        $this->connect();

        // Template 8 declares nothing, so LabMan cannot pre-check; Semaphore itself refuses with a bare 400.
        $this->actingAs($this->admin)->post('/semaphore/run', ['project_id' => 1, 'template_id' => 8, 'confirm' => 1, 'extra_vars' => '{"anything":"1"}'])
            ->assertRedirect(route('semaphore.index'))->assertSessionHasErrors('semaphore');

        $this->assertStringContainsString('does not declare as a survey variable', session('errors')->first('semaphore'));
    }

    public function test_mcp_checks_variables_and_lists_them(): void
    {
        $this->connect();

        [, $templates] = $this->tool('semaphore_list_templates', ['project_id' => 1]);
        $vars = collect($templates['templates'][0]['variables'])->keyBy('name');
        $this->assertSame(['version', 'count', 'color', 'api_key'], $vars->keys()->all());
        $this->assertSame('int', $vars['count']['type']);
        $this->assertSame(['red', 'blue'], $vars['color']['choices']);
        $this->assertSame([], $templates['templates'][1]['variables']);

        [$error, $text] = $this->tool('semaphore_run_template', ['project_id' => 1, 'template_id' => 7, 'confirm' => true, 'extra_vars' => ['nope' => 'x']]);
        $this->assertTrue($error);
        $this->assertStringContainsString('is not a variable this template declares', $text);
        $this->assertSame([], $this->sem->posts());

        [$error] = $this->tool('semaphore_run_template', ['project_id' => 1, 'template_id' => 7, 'confirm' => true, 'extra_vars' => ['count' => '4']]);
        $this->assertFalse($error);
        $this->assertSame('{"count":4}', $this->sem->posts()[0]['body']['environment'], 'numbers are typed before they are sent');

        [$error, $text] = $this->tool('semaphore_run_template', ['project_id' => 1, 'template_id' => 99, 'confirm' => true]);
        $this->assertTrue($error);
        $this->assertStringContainsString('was not found', $text);
    }

    public function test_ansi_colours_are_stripped_from_output(): void
    {
        $this->connect();
        $this->sem->tasks[100] = ['id' => 100, 'status' => 'running', 'template_id' => 7];
        $this->sem->output[100] = ["\e[0;32mok: [localhost] => \e[0m", "\e[1;31mfatal: boom\e[0m\r", 'plain'];

        $page = $this->actingAs($this->admin)->get('/semaphore/tasks/1/100')->assertOk();
        $this->assertStringNotContainsString("\e", $page->getContent());
        $this->assertStringContainsString('ok: [localhost] =&gt;', $page->getContent());
        $this->assertStringNotContainsString('[0;32m', $page->getContent());

        $this->getJson('/semaphore/tasks/1/100/status?since=0')->assertJsonPath('lines', ['ok: [localhost] => ', 'fatal: boom', 'plain']);

        [, $task] = $this->tool('semaphore_get_task', ['project_id' => 1, 'task_id' => 100]);
        $this->assertSame(['ok: [localhost] => ', 'fatal: boom', 'plain'], $task['output_tail']);
    }
    // ---------------------------------------------------------------- MCP tools

    private function tool(string $name, array $args = [], ?string $token = null): array
    {
        $token ??= $this->adminToken();
        $r = $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => (object) $args]], ['Authorization' => 'Bearer '.$token])->json('result');

        return [$r['isError'], $r['structuredContent'] ?? $r['content'][0]['text']];
    }

    private ?string $cachedToken = null;

    private function adminToken(): string
    {
        return $this->cachedToken ??= ApiToken::issue($this->admin, 'agent', 'admin')[1];
    }

    public function test_mcp_connection_and_browsing_tools(): void
    {
        [$error, $out] = $this->tool('semaphore_set_connection', ['url' => FakeSemaphore::URL, 'token' => 'sem-token']);
        $this->assertFalse($error, json_encode($out));
        $this->assertSame('labman', $out['user']);
        $this->assertStringNotContainsString('sem-token', json_encode($out));

        [, $conn] = $this->tool('semaphore_get_connection');
        $this->assertTrue($conn['connected']);
        $this->assertStringNotContainsString('sem-token', json_encode($conn));

        [, $projects] = $this->tool('semaphore_list_projects');
        $this->assertSame(['Home Lab', 'Other'], array_column($projects['projects'], 'name'));

        [, $templates] = $this->tool('semaphore_list_templates', ['project_id' => 1]);
        $this->assertSame(['Update packages', 'Deploy app'], array_column($templates['templates'], 'name'));
        $this->assertSame('success', $templates['templates'][0]['last_task']['status']);

        [$error, $text] = $this->tool('semaphore_list_templates', ['project_id' => 99]);
        $this->assertTrue($error);
        $this->assertStringContainsString('404', $text);

        $this->tool('semaphore_remove_connection');
        $this->assertFalse(Connection::exists());
    }

    public function test_mcp_runs_need_explicit_confirmation(): void
    {
        $this->connect();

        // Missing or false confirm: nothing starts.
        [$error, $text] = $this->tool('semaphore_run_template', ['project_id' => 1, 'template_id' => 7, 'limit' => 'web1']);
        $this->assertTrue($error);
        $this->assertStringContainsString('Missing required', $text);

        [$error, $text] = $this->tool('semaphore_run_template', ['project_id' => 1, 'template_id' => 7, 'confirm' => false]);
        $this->assertTrue($error);
        $this->assertStringContainsString('approved this exact run', $text);
        $this->assertSame([], $this->sem->posts());

        // Invalid options are refused even when confirmed.
        [$error, $text] = $this->tool('semaphore_run_template', ['project_id' => 1, 'template_id' => 7, 'confirm' => true, 'limit' => 'a;b']);
        $this->assertTrue($error);
        $this->assertSame([], $this->sem->posts());

        [$error, $out] = $this->tool('semaphore_run_template', ['project_id' => 1, 'template_id' => 7, 'confirm' => true, 'limit' => 'web1', 'dry_run' => true, 'extra_vars' => ['version' => '1.2'], 'message' => 'via assistant']);
        $this->assertFalse($error, json_encode($out));
        $this->assertSame(100, $out['task_id']);
        $this->assertSame('only on web1, check mode (dry run)', $out['summary']);
        $this->assertSame(['template_id' => 7, 'dry_run' => true, 'diff' => false, 'limit' => 'web1', 'environment' => '{"version":"1.2"}', 'message' => 'via assistant'], $this->sem->posts()[0]['body']);
    }

    public function test_mcp_can_follow_and_stop_a_task(): void
    {
        $this->connect();
        $this->sem->tasks[100] = ['id' => 100, 'status' => 'running', 'template_id' => 7, 'limit' => 'web1', 'dry_run' => true];
        $this->sem->output[100] = array_map(fn ($i) => "line {$i}", range(1, 120));

        [, $task] = $this->tool('semaphore_get_task', ['project_id' => 1, 'task_id' => 100, 'tail' => 3]);
        $this->assertSame('running', $task['status']);
        $this->assertFalse($task['finished']);
        $this->assertSame(120, $task['output_lines_total']);
        $this->assertSame(['line 118', 'line 119', 'line 120'], $task['output_tail']);

        [, $default] = $this->tool('semaphore_get_task', ['project_id' => 1, 'task_id' => 100]);
        $this->assertCount(50, $default['output_tail']);

        [, $tasks] = $this->tool('semaphore_list_tasks', ['project_id' => 1]);
        $this->assertSame(100, $tasks['tasks'][0]['id']);

        [$error] = $this->tool('semaphore_stop_task', ['project_id' => 1, 'task_id' => 100]);
        $this->assertFalse($error);
        [, $after] = $this->tool('semaphore_get_task', ['project_id' => 1, 'task_id' => 100]);
        $this->assertTrue($after['finished']);
    }

    public function test_read_tokens_cannot_use_the_semaphore_tools(): void
    {
        $this->connect();
        [, $read] = ApiToken::issue($this->admin, 'viewer', 'read');

        $names = array_column($this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], ['Authorization' => 'Bearer '.$read])->json('result.tools'), 'name');
        $this->assertSame([], array_values(array_filter($names, fn ($n) => str_starts_with($n, 'semaphore_') || str_starts_with($n, 'inventory_'))));

        [$error, $text] = $this->tool('semaphore_run_template', ['project_id' => 1, 'template_id' => 7, 'confirm' => true], $read);
        $this->assertTrue($error);
        $this->assertStringContainsString("'read' scope", $text);
        $this->assertSame([], $this->sem->posts());
    }

    public function test_run_tools_are_marked_destructive_and_not_read_only(): void
    {
        $tools = collect($this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], ['Authorization' => 'Bearer '.$this->adminToken()])->json('result.tools'))->keyBy('name');

        $this->assertTrue($tools['semaphore_run_template']['annotations']['destructiveHint']);
        $this->assertFalse($tools['semaphore_run_template']['annotations']['readOnlyHint']);
        $this->assertTrue($tools['semaphore_list_templates']['annotations']['readOnlyHint']);
        $this->assertTrue($tools['inventory_apply']['annotations']['destructiveHint']);
        $this->assertTrue($tools['inventory_compare']['annotations']['readOnlyHint']);
        $this->assertTrue($tools['inventory_review']['annotations']['readOnlyHint']);
    }
}
