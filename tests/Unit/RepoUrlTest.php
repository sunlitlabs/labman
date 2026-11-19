<?php

namespace Tests\Unit;

use App\Inventory\RepoUrl;
use App\Support\BaseUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RepoUrlTest extends TestCase
{
    /** @return array<string, array{0: string, 1: array<string, ?string>}> input => expected repo_url/branch/path/provider/repo */
    public static function links(): array
    {
        $gh = fn (?string $branch, ?string $path) => ['repo_url' => 'https://github.com/example-org/lab.git', 'branch' => $branch, 'path' => $path, 'provider' => 'github', 'repo' => 'example-org/lab'];

        return [
            'github blob link' => ['https://github.com/example-org/lab/blob/main/ansible/inventory/all_servers.ini', $gh('main', 'ansible/inventory/all_servers.ini')],
            'github blob with query and anchor' => ['https://github.com/example-org/lab/blob/main/ansible/hosts.yml?plain=1#L10', $gh('main', 'ansible/hosts.yml')],
            'github tree (folder)' => ['https://github.com/example-org/lab/tree/dev/ansible', $gh('dev', 'ansible')],
            'github raw link' => ['https://github.com/example-org/lab/raw/main/inventory.ini', $gh('main', 'inventory.ini')],
            'raw.githubusercontent' => ['https://raw.githubusercontent.com/example-org/lab/main/ansible/hosts.ini', $gh('main', 'ansible/hosts.ini')],
            'github repo only' => ['https://github.com/example-org/lab', $gh(null, null)],
            'github repo with .git' => ['https://github.com/example-org/lab.git', $gh(null, null)],
            'github repo trailing slash' => ['https://github.com/example-org/lab/', $gh(null, null)],
            'github without scheme' => ['github.com/example-org/lab/blob/main/hosts.ini', $gh('main', 'hosts.ini')],
            'github www' => ['https://www.github.com/example-org/lab/blob/main/hosts.ini', $gh('main', 'hosts.ini')],
            'git@ clone address' => ['git@github.com:example-org/lab.git', $gh(null, null)],
            'ssh url clone address' => ['ssh://git@github.com/example-org/lab.git', $gh(null, null)],
            'encoded path' => ['https://github.com/example-org/lab/blob/main/my%20files/hosts.ini', $gh('main', 'my files/hosts.ini')],

            'gitlab file' => ['https://gitlab.com/group/proj/-/blob/main/ansible/hosts.ini', ['repo_url' => 'https://gitlab.com/group/proj.git', 'branch' => 'main', 'path' => 'ansible/hosts.ini', 'provider' => 'gitlab', 'repo' => 'group/proj']],
            'gitlab subgroups' => ['https://gitlab.com/a/b/c/proj/-/blob/dev/x/hosts.yml', ['repo_url' => 'https://gitlab.com/a/b/c/proj.git', 'branch' => 'dev', 'path' => 'x/hosts.yml', 'provider' => 'gitlab', 'repo' => 'a/b/c/proj']],
            'self-hosted gitlab repo' => ['https://gitlab.example.com/team/lab', ['repo_url' => 'https://gitlab.example.com/team/lab.git', 'branch' => null, 'path' => null, 'provider' => 'gitlab', 'repo' => 'team/lab']],

            'gitea src branch' => ['http://git.example.com:3000/org/lab/src/branch/main/inventory/hosts.ini', ['repo_url' => 'http://git.example.com:3000/org/lab', 'branch' => 'main', 'path' => 'inventory/hosts.ini', 'provider' => 'gitea', 'repo' => 'org/lab']],
            'gitea raw' => ['http://git.example.com:3000/org/lab/raw/branch/dev/hosts.ini', ['repo_url' => 'http://git.example.com:3000/org/lab', 'branch' => 'dev', 'path' => 'hosts.ini', 'provider' => 'gitea', 'repo' => 'org/lab']],
            'codeberg file' => ['https://codeberg.org/user/lab/src/branch/main/hosts.ini', ['repo_url' => 'https://codeberg.org/user/lab', 'branch' => 'main', 'path' => 'hosts.ini', 'provider' => 'gitea', 'repo' => 'user/lab']],
            'bitbucket file' => ['https://bitbucket.org/team/lab/src/main/ansible/hosts.ini', ['repo_url' => 'https://bitbucket.org/team/lab.git', 'branch' => 'main', 'path' => 'ansible/hosts.ini', 'provider' => 'bitbucket', 'repo' => 'team/lab']],
        ];
    }

    #[DataProvider('links')]
    public function test_links_are_understood(string $input, array $expected): void
    {
        $parsed = RepoUrl::parse($input);

        $this->assertNotNull($parsed, $input);
        $this->assertSame($expected, array_intersect_key($parsed, $expected));
    }

    /** @return array<string, array{0: string}> */
    public static function rejected(): array
    {
        return [
            'empty' => [''], 'spaces' => ['https://github.com/a b/c'], 'no repo' => ['https://github.com/example-org'], 'host only' => ['https://github.com'],
            'credentials' => ['https://user:token@github.com/example-org/lab'], 'ftp' => ['ftp://github.com/a/b'], 'file scheme' => ['file:///tmp/x'],
            'javascript' => ['javascript:alert(1)'], 'angle brackets' => ['https://github.com/<script>/x'], 'too long' => ['https://github.com/'.str_repeat('a', 600)],
        ];
    }

    #[DataProvider('rejected')]
    public function test_nonsense_is_not_understood(string $input): void
    {
        $this->assertNull(RepoUrl::parse($input));
    }

    public function test_a_token_help_link_is_offered_for_known_hosts(): void
    {
        $this->assertSame('https://github.com/settings/personal-access-tokens/new', RepoUrl::parse('https://github.com/a/b')['token_url']);
        $this->assertSame('https://gitlab.example.com/-/user_settings/personal_access_tokens', RepoUrl::parse('https://gitlab.example.com/a/b')['token_url']);
        $this->assertSame('http://git.example.com:3000/user/settings/applications', RepoUrl::parse('http://git.example.com:3000/org/lab/src/branch/main/x')['token_url']);
        $this->assertNull(RepoUrl::parse('https://git.example.com/a/b')['token_url'], 'unknown hosts get no guessed link');
    }

    public function test_service_addresses_may_be_any_pasted_page(): void
    {
        $this->assertSame('http://192.0.2.5:81', BaseUrl::origin('http://192.0.2.5:81/nginx/proxy?x=1#top'));
        $this->assertSame('https://npm.example.com', BaseUrl::origin(' https://npm.example.com/login '));
        $this->assertSame('http://[2001:db8::1]:81', BaseUrl::origin('http://[2001:db8::1]:81/users'));

        foreach (['ftp://x.example.com/a', 'npm.example.com', 'http://user:pw@npm.example.com/x', 'javascript:alert(1)', 'http://bad host/x'] as $bad) {
            try {
                BaseUrl::origin($bad);
                $this->fail("Accepted {$bad}");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
