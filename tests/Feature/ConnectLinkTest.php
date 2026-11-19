<?php

namespace Tests\Feature;

use App\Inventory\Connector;
use App\Inventory\GitRepo;
use App\Inventory\InventoryException;
use App\Inventory\RepoConfig;
use App\Inventory\UsernameGuess;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;
use Tests\Support\TempRepo;
use Tests\TestCase;

/** Pasting a link instead of filling in three fields, and not having to know the git user name. */
class ConnectLinkTest extends TestCase
{
    use RefreshDatabase;

    private TempRepo $remote;

    protected function setUp(): void
    {
        parent::setUp();

        $this->remote = TempRepo::create(['ansible/inventory/all_servers.ini' => "[web]\nweb1 ansible_host=192.0.2.11\n"]);
        $this->admin(['email' => 'root@example.com']);
    }

    protected function tearDown(): void
    {
        Connector::$repoFactory = null;
        $this->remote->cleanup();
        (new Process(['sh', '-c', 'rm -rf '.escapeshellarg(storage_path('app/inventory-repo')).'*']))->run();

        parent::tearDown();
    }

    // ------------------------------------------------------------ the parse endpoint

    public function test_the_form_script_asks_the_server_to_understand_a_pasted_link(): void
    {
        $url = 'https://github.com/example-org/lab/blob/main/ansible/inventory/all_servers.ini';

        $this->actingAs(User::where('role', 'admin')->first())->getJson('/inventory/parse-link?url='.urlencode($url))
            ->assertOk()
            ->assertJson([
                'ok' => true, 'repo_url' => 'https://github.com/example-org/lab.git', 'branch' => 'main',
                'path' => 'ansible/inventory/all_servers.ini', 'provider' => 'github', 'repo' => 'example-org/lab',
                'token_url' => 'https://github.com/settings/personal-access-tokens/new',
            ]);

        $this->getJson('/inventory/parse-link?url='.urlencode('not a link'))->assertOk()->assertExactJson(['ok' => false]);
        $this->getJson('/inventory/parse-link')->assertOk()->assertExactJson(['ok' => false]);
    }

    public function test_the_parse_endpoint_is_admin_only(): void
    {
        $this->getJson('/inventory/parse-link?url=x')->assertUnauthorized();
        $this->actingAs($this->user())->getJson('/inventory/parse-link?url=x')->assertForbidden();
    }

    public function test_the_form_has_one_link_box_and_hides_the_details(): void
    {
        $this->actingAs(User::where('role', 'admin')->first())->get('/inventory')->assertOk()
            ->assertSee('Link to your inventory file or repository')->assertSee('id="detected"', false)->assertSee('js/connect.js', false)
            ->assertSee('data-parse-url="'.route('inventory.parse').'"', false)
            ->assertSee('leave blank to detect it from the token')->assertSee('The account name is worked out from the token');
    }

    // ------------------------------------------------------------ connecting with less typing

    public function test_connecting_needs_no_user_name(): void
    {
        $result = Connector::connect(['repo_url' => $this->remote->remote, 'branch' => 'main', 'path' => 'ansible/inventory/all_servers.ini', 'token' => 'pat-123']);

        $this->assertSame(1, $result['hosts']);
        $this->assertSame('git', $result['username'], 'a fallback name is saved when the host has no convention');
        $this->assertSame('git', RepoConfig::get()['username']);
    }

    public function test_the_web_form_accepts_just_a_link_and_a_token_for_a_local_remote(): void
    {
        $this->actingAs(User::where('role', 'admin')->first())
            ->post('/inventory/connection', ['repo_url' => $this->remote->remote, 'branch' => 'main', 'path' => 'ansible/inventory/all_servers.ini', 'token' => 'pat-123'])
            ->assertSessionHasNoErrors()->assertRedirect(route('inventory.compare'));

        $this->assertStringContainsString('Connected as git. Read 1 host(s)', session('status'));
    }

    public function test_a_missing_file_asks_which_file_it_is(): void
    {
        $this->expectException(InventoryException::class);
        $this->expectExceptionMessage('Which file is the inventory?');

        Connector::connect(['repo_url' => $this->remote->remote, 'token' => 'pat-123']);
    }

    public function test_a_pasted_link_fills_in_branch_and_path_but_explicit_fields_win(): void
    {
        $seen = [];
        Connector::$repoFactory = function (array $config) use (&$seen) {
            $seen[] = $config;

            return new class($config, $this->remote->workDir()) extends GitRepo
            {
                public function sync(): string
                {
                    throw new InventoryException('stop here: the parsed values are what matters');
                }
            };
        };

        $link = 'https://github.com/example-org/lab/blob/dev/ansible/inventory/all_servers.ini';

        foreach ([['repo_url' => $link, 'token' => 't'], ['repo_url' => $link, 'branch' => 'release', 'path' => 'other/hosts.yml', 'token' => 't']] as $input) {
            try {
                Connector::connect($input);
            } catch (InventoryException) {
            }
        }

        $this->assertSame('https://github.com/example-org/lab.git', $seen[0]['url']);
        $this->assertSame(['dev', 'ansible/inventory/all_servers.ini'], [$seen[0]['branch'], $seen[0]['path']]);
        $this->assertSame(['release', 'other/hosts.yml'], [$seen[1]['branch'], $seen[1]['path']], 'what the admin typed in the details wins');
    }

    // ------------------------------------------------------------ user name detection

    public function test_each_host_has_its_conventional_user_name(): void
    {
        $this->assertSame(['x-access-token'], UsernameGuess::candidates('https://github.com/a/b.git'));
        $this->assertSame(['oauth2'], UsernameGuess::candidates('https://gitlab.com/a/b.git'));
        $this->assertSame(['oauth2'], UsernameGuess::candidates('https://gitlab.example.com/a/b.git'));
        $this->assertSame(['x-token-auth'], UsernameGuess::candidates('https://bitbucket.org/a/b.git'));
        $this->assertSame(['git'], UsernameGuess::candidates('http://git.example.com:3000/a/b.git'));
    }

    public function test_the_owner_of_the_token_is_asked_from_the_hosts_api(): void
    {
        Http::fake(function (Request $request) {
            return match (true) {
                $request->url() === 'https://api.github.com/user' && ($request->header('Authorization')[0] ?? '') === 'Bearer gh-token' => Http::response(['login' => 'octocat']),
                $request->url() === 'http://git.example.com:3000/api/v1/user' && ($request->header('Authorization')[0] ?? '') === 'token gt-token' => Http::response(['login' => 'labadmin']),
                $request->url() === 'https://gitlab.example.com/api/v4/user' && ($request->header('PRIVATE-TOKEN')[0] ?? '') === 'gl-token' => Http::response(['username' => 'jdoe']),
                default => Http::response('', 404),
            };
        });

        $this->assertSame('octocat', UsernameGuess::fromApi('https://github.com/a/b.git', 'gh-token'));
        $this->assertSame('labadmin', UsernameGuess::fromApi('http://git.example.com:3000/a/b.git', 'gt-token'));
        $this->assertSame('jdoe', UsernameGuess::fromApi('https://gitlab.example.com/a/b.git', 'gl-token'));
        $this->assertNull(UsernameGuess::fromApi('https://git.unknown.example/a/b.git', 'whatever'));
        $this->assertNull(UsernameGuess::fromApi('https://github.com/a/b.git', 'wrong'));
    }

    public function test_an_api_answer_that_is_not_a_plain_name_is_ignored(): void
    {
        Http::fake(['*' => Http::response(['login' => "evil\nname; rm -rf /"])]);

        $this->assertNull(UsernameGuess::fromApi('http://git.example.com:3000/a/b.git', 't'));
    }

    public function test_detection_tries_the_conventional_name_then_the_owner_of_the_token(): void
    {
        $tried = [];
        Http::fake(['http://git.example.com:3000/api/v1/user' => Http::response(['login' => 'labadmin'])]);

        // The address presented is a Gitea-like host (so the user name is guessed for it), while the clone itself
        // is served from the local test repository.
        $config = $this->remote->config('ansible/inventory/all_servers.ini', ['url' => 'http://git.example.com:3000/org/lab.git']);
        $reflection = new \ReflectionMethod(Connector::class, 'reach');
        $reflection->setAccessible(true);

        Connector::$repoFactory = function (array $c) use (&$tried, $config) {
            $tried[] = $c['username'];

            return new class($c + ['url' => $config['url']], $this->remote->workDir(), $this->remote->remote) extends GitRepo
            {
                public function __construct(private array $cfg, ?string $dir, private string $real)
                {
                    parent::__construct(array_merge($cfg, ['url' => $real]), $dir);
                }

                public function sync(): string
                {
                    if ($this->cfg['username'] !== 'labadmin') {
                        throw new InventoryException('The repository rejected the username or access token, or the token may not push to it.');
                    }

                    return parent::sync();
                }
            };
        };

        // As in real use, the values handed over carry no user name or token of their own.
        [, $name] = $reflection->invoke(null, array_diff_key($config, ['username' => 1, 'token' => 1]), 'gt-token', '');

        $this->assertSame('labadmin', $name);
        $this->assertSame(['git', 'labadmin'], $tried);
    }

    public function test_an_explicit_user_name_is_used_as_is_and_never_second_guessed(): void
    {
        $tried = [];
        Connector::$repoFactory = function (array $config) use (&$tried) {
            $tried[] = $config['username'];

            return new class($config, $this->remote->workDir()) extends GitRepo
            {
                public function sync(): string
                {
                    throw new InventoryException('The repository rejected the username or access token, or the token may not push to it.');
                }
            };
        };

        try {
            Connector::connect(['repo_url' => $this->remote->remote, 'branch' => 'main', 'path' => 'ansible/inventory/all_servers.ini', 'token' => 't', 'username' => 'me']);
            $this->fail('Expected a failure');
        } catch (InventoryException $e) {
            $this->assertStringContainsString('rejected the username', $e->getMessage());
        }

        $this->assertSame(['me'], $tried);
    }

    public function test_a_failure_that_is_not_about_credentials_stops_the_guessing(): void
    {
        $tried = [];
        Connector::$repoFactory = function (array $config) use (&$tried) {
            $tried[] = $config['username'];

            return new class($config, $this->remote->workDir()) extends GitRepo
            {
                public function sync(): string
                {
                    throw new InventoryException('Cannot connect to the repository host.');
                }
            };
        };
        Http::fake();

        try {
            Connector::connect(['repo_url' => 'https://git.example.com/org/lab.git', 'branch' => 'main', 'path' => 'x.ini', 'token' => 't']);
            $this->fail('Expected a failure');
        } catch (InventoryException $e) {
            $this->assertStringContainsString('Cannot connect', $e->getMessage());
        }

        $this->assertSame(['git'], $tried, 'no point trying other names when the host is unreachable');
        Http::assertNothingSent();
    }

    public function test_the_detected_name_is_saved_but_the_token_is_not_in_plain_text(): void
    {
        Connector::connect(['repo_url' => $this->remote->remote, 'branch' => 'main', 'path' => 'ansible/inventory/all_servers.ini', 'token' => 'pat-secret-xyz']);

        $this->assertSame('git', Setting::whereKey('inventory.username')->value('value'));
        $this->assertStringNotContainsString('pat-secret-xyz', (string) Setting::whereKey('inventory.token')->value('value'));
    }

    // ------------------------------------------------------------ other services

    public function test_semaphore_and_npm_accept_any_pasted_page(): void
    {
        $this->assertSame('http://192.0.2.7:3000', \App\Semaphore\Connection::cleanUrl('http://192.0.2.7:3000/project/1/templates'));
        $this->assertSame('http://192.0.2.7:3000', \App\Semaphore\Connection::cleanUrl('http://192.0.2.7:3000/project/1/history?x=1#a'));
        $this->assertSame('http://192.0.2.7:3000', \App\Semaphore\Connection::cleanUrl('http://192.0.2.7:3000/auth/login'));
        $this->assertSame('https://ci.example.com/semaphore', \App\Semaphore\Connection::cleanUrl('https://ci.example.com/semaphore/project/2/templates'), 'a sub-path install is kept');
        $this->assertSame('http://192.0.2.7:3000', \App\Semaphore\Connection::cleanUrl('http://192.0.2.7:3000/'));

        $this->assertSame('http://192.0.2.5:81', \App\Npm\Connection::cleanUrl('http://192.0.2.5:81/nginx/proxy'));
        $this->assertSame('https://npm.example.com', \App\Npm\Connection::cleanUrl('https://npm.example.com/users?x=1'));
    }
}
