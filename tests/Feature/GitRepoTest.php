<?php

namespace Tests\Feature;

use App\Inventory\GitRepo;
use App\Inventory\InventoryException;
use Symfony\Component\Process\Process;
use Tests\Support\TempRepo;
use Tests\TestCase;

class GitRepoTest extends TestCase
{
    private const YAML = "all:\n  hosts:\n    a:\n";

    private TempRepo $remote;

    protected function setUp(): void
    {
        parent::setUp();

        $this->remote = TempRepo::create(['inventory/hosts.yml' => self::YAML, 'README.md' => 'x', 'ansible/site.yml' => '---']);
    }

    protected function tearDown(): void
    {
        $this->remote->cleanup();

        parent::tearDown();
    }

    private function repo(array $overrides = []): GitRepo
    {
        return new GitRepo($this->remote->config('inventory/hosts.yml', $overrides), $this->remote->workDir());
    }

    private function gitStatus(): string
    {
        $p = new Process(['git', 'status', '--porcelain'], $this->remote->workDir(), ['GIT_CONFIG_GLOBAL' => '/dev/null']);
        $p->run();

        return trim($p->getOutput());
    }

    public function test_sync_clones_and_then_follows_the_remote(): void
    {
        $repo = $this->repo();

        $first = $repo->sync();
        $this->assertSame($this->remote->remoteHead(), $first);
        $this->assertSame(self::YAML, $repo->read('inventory/hosts.yml'));

        $moved = $this->remote->commitFromElsewhere(['inventory/hosts.yml' => "all:\n  hosts:\n    b:\n"], 'Edit');

        $this->assertSame($moved, $repo->sync());
        $this->assertStringContainsString('b:', $repo->read('inventory/hosts.yml'));
    }

    public function test_read_returns_null_for_missing_files_and_refuses_paths_outside_the_repo(): void
    {
        $repo = $this->repo();
        $repo->sync();

        $this->assertNull($repo->read('inventory/nope.yml'));

        foreach (['../outside.yml', '/etc/passwd', '.git/config', 'a/../../b'] as $bad) {
            try {
                $repo->read($bad);
                $this->fail("Read {$bad}");
            } catch (InventoryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_symbolic_links_are_never_followed(): void
    {
        $this->remote->commitSymlink('inventory/link.yml', '/etc/passwd');
        $this->remote->commitSymlink('linkdir', '/tmp');
        $repo = $this->repo();
        $repo->sync();

        $this->expectException(InventoryException::class);
        $this->expectExceptionMessage('symbolic link');
        try {
            $repo->read('inventory/link.yml');
        } finally {
            // Writing through a symlinked directory is refused too.
            try {
                $repo->preview(['linkdir/evil.yml' => 'x']);
                $this->fail('Wrote through a symlinked directory');
            } catch (InventoryException $e) {
                $this->assertStringContainsString('symbolic link', $e->getMessage());
            }
            $this->assertFileDoesNotExist('/tmp/evil.yml');
        }
    }

    public function test_preview_shows_the_diff_and_leaves_nothing_behind(): void
    {
        $repo = $this->repo();
        $repo->sync();

        $diff = $repo->preview(['inventory/hosts.yml' => "all:\n  hosts:\n    a:\n    c:\n", 'inventory/new.yml' => "all:\n"], ['README.md']);

        $this->assertStringContainsString('+    c:', $diff);
        $this->assertStringContainsString('new file', $diff);
        $this->assertStringContainsString('deleted file', $diff);
        $this->assertSame('', $this->gitStatus(), 'the working copy is clean again');
        $this->assertSame(self::YAML, $repo->read('inventory/hosts.yml'));
        $this->assertSame('x', $repo->read('README.md'));
    }

    public function test_commit_and_push_updates_the_remote_with_the_configured_author(): void
    {
        $repo = $this->repo(['author_name' => 'Lab Bot', 'author_email' => 'bot@example.com']);
        $repo->sync();

        $sha = $repo->commitAndPush(['inventory/hosts.yml' => "all:\n  hosts:\n    a:\n    c:\n"], [], "Update inventory from LabMan\n\n- add c");

        $this->assertSame($this->remote->remoteHead(), $sha);
        $this->assertStringContainsString('c:', (string) $this->remote->remoteFile('inventory/hosts.yml'));
        $this->assertSame('Update inventory from LabMan', $this->remote->log()[0]);
        $this->assertSame('Lab Bot <bot@example.com>', $this->remote->lastAuthor());
        $this->assertSame('', $this->gitStatus());
    }

    public function test_deleting_files_is_part_of_a_commit(): void
    {
        $repo = $this->repo();
        $repo->sync();

        $repo->commitAndPush(['inventory/hosts.yml' => "all:\n"], ['README.md'], 'x');

        $this->assertNull($this->remote->remoteFile('README.md'));
    }

    public function test_an_unchanged_commit_is_refused(): void
    {
        $repo = $this->repo();
        $repo->sync();

        $this->expectException(InventoryException::class);
        $this->expectExceptionMessage('Nothing to commit');
        $repo->commitAndPush(['inventory/hosts.yml' => self::YAML], [], 'noop');
    }

    public function test_a_push_rejected_because_the_remote_moved_is_reported_and_cleaned_up(): void
    {
        $repo = $this->repo();
        $repo->sync();
        $this->remote->commitFromElsewhere(['inventory/hosts.yml' => "all:\n  hosts:\n    other:\n"], 'Someone else');

        try {
            $repo->commitAndPush(['inventory/hosts.yml' => "all:\n  hosts:\n    mine:\n"], [], 'mine');
            $this->fail('Expected a rejection');
        } catch (InventoryException $e) {
            $this->assertStringContainsString('push was rejected', $e->getMessage());
        }

        $this->assertStringNotContainsString('mine:', (string) $this->remote->remoteFile('inventory/hosts.yml'));
        $this->assertSame('', $this->gitStatus());
        $this->assertSame(self::YAML, $repo->read('inventory/hosts.yml'), 'the failed local commit was discarded');

        // ...and the next sync simply picks up the other change.
        $repo->sync();
        $this->assertStringContainsString('other:', $repo->read('inventory/hosts.yml'));
    }

    public function test_changing_the_repository_settings_makes_a_fresh_clone(): void
    {
        $repo = $this->repo();
        $repo->sync();
        $this->assertDirectoryExists($this->remote->workDir().'/.git');

        $other = TempRepo::create(['inventory/hosts.yml' => "all:\n  hosts:\n    elsewhere:\n"]);
        $moved = new GitRepo($other->config(), $this->remote->workDir());
        $moved->sync();

        $this->assertStringContainsString('elsewhere:', $moved->read('inventory/hosts.yml'));
        $other->cleanup();
    }

    public function test_inventory_like_files_are_offered_as_candidates(): void
    {
        $this->remote->commitFromElsewhere(['hosts' => '', 'prod/inventory.ini' => '', 'notes.txt' => '', 'group_vars/all.yml' => ''], 'more');
        $repo = $this->repo();
        $repo->sync();

        $this->assertEqualsCanonicalizing(['inventory/hosts.yml', 'hosts', 'prod/inventory.ini'], $repo->candidates());
    }

    public function test_errors_are_friendly_and_never_contain_the_token(): void
    {
        $missing = new GitRepo($this->remote->config('inventory/hosts.yml', ['url' => $this->remote->base.'/does-not-exist.git']), $this->remote->workDir('w2'));
        try {
            $missing->sync();
            $this->fail('Expected a failure');
        } catch (InventoryException $e) {
            $this->assertStringContainsString('not found', strtolower($e->getMessage()));
            $this->assertStringNotContainsString('secret-token-123', $e->getMessage());
        }

        $refused = new GitRepo($this->remote->config('inventory/hosts.yml', ['url' => 'http://127.0.0.1:9/org/inv.git']), $this->remote->workDir('w3'));
        try {
            $refused->sync();
            $this->fail('Expected a failure');
        } catch (InventoryException $e) {
            $this->assertStringNotContainsString('secret-token-123', $e->getMessage());
            $this->assertMatchesRegularExpression('/cannot connect|resolve|timed out/i', $e->getMessage());
        }

        $badBranch = new GitRepo($this->remote->config('inventory/hosts.yml', ['branch' => 'nope']), $this->remote->workDir('w4'));
        $this->expectException(InventoryException::class);
        $this->expectExceptionMessage("branch 'nope' does not exist");
        $badBranch->sync();
    }

    public function test_the_token_reaches_git_only_through_the_askpass_environment(): void
    {
        $askpass = (string) config('labman.git.askpass');
        $this->assertFileExists($askpass);

        $run = function (string $prompt) use ($askpass) {
            $p = new Process(['sh', $askpass, $prompt], null, ['LABMAN_GIT_USERNAME' => 'ci-user', 'LABMAN_GIT_PASSWORD' => 'tok-123']);
            $p->run();

            return $p->getOutput();
        };

        $this->assertSame('ci-user', $run("Username for 'https://git.example.com': "));
        $this->assertSame('tok-123', $run("Password for 'https://ci-user@git.example.com': "));

        // And after a real clone the saved remote URL carries no credentials.
        $repo = $this->repo();
        $repo->sync();
        $p = new Process(['git', 'config', '--get', 'remote.origin.url'], $this->remote->workDir(), ['GIT_CONFIG_GLOBAL' => '/dev/null']);
        $p->run();
        $this->assertStringNotContainsString('secret-token-123', $p->getOutput());
        $this->assertStringNotContainsString('@', $p->getOutput());
    }
}
