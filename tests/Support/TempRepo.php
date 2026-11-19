<?php

namespace Tests\Support;

use Symfony\Component\Process\Process;

/**
 * A real git remote for tests: a bare repository in a temp directory, seeded with files, that
 * LabMan clones, commits to and pushes to just like it would a hosted repository.
 */
final class TempRepo
{
    public string $base;

    public string $remote;

    public string $branch = 'main';

    private function __construct() {}

    /** @param array<string, string> $files path => content */
    public static function create(array $files, string $branch = 'main'): self
    {
        $repo = new self;
        $repo->branch = $branch;
        $repo->base = sys_get_temp_dir().'/labman-test-'.bin2hex(random_bytes(6));
        $repo->remote = $repo->base.'/remote.git';
        mkdir($repo->base, 0775, true);

        $repo->git(['init', '--quiet', '--bare', '-b', $branch, $repo->remote], $repo->base);
        $repo->commitFromElsewhere($files, 'Seed');

        return $repo;
    }

    /** @return array{url: string, branch: string, path: string, username: string, token: string, author_name: string, author_email: string} */
    public function config(string $path = 'inventory/hosts.yml', array $overrides = []): array
    {
        return $overrides + [
            'url' => $this->remote, 'branch' => $this->branch, 'path' => $path, 'username' => 'git',
            'token' => 'secret-token-123', 'author_name' => 'LabMan', 'author_email' => 'labman@localhost',
        ];
    }

    public function workDir(string $name = 'work'): string
    {
        return $this->base.'/'.$name;
    }

    /** Simulate someone else committing and pushing (or the initial seed). @param array<string, string|null> $files null deletes */
    public function commitFromElsewhere(array $files, string $message): string
    {
        $seed = $this->base.'/seed';

        if (! is_dir($seed.'/.git')) {
            $this->git(['clone', '--quiet', $this->remote, $seed], $this->base);
            $this->git(['checkout', '--quiet', '-B', $this->branch], $seed);
        } else {
            $this->git(['pull', '--quiet', '--ff-only', 'origin', $this->branch], $seed, tolerate: true);
        }

        foreach ($files as $path => $content) {
            $full = $seed.'/'.$path;
            if ($content === null) {
                @unlink($full);

                continue;
            }
            @mkdir(dirname($full), 0775, true);
            file_put_contents($full, $content);
        }

        $this->git(['add', '-A'], $seed);
        $this->git(['-c', 'user.name=Someone', '-c', 'user.email=someone@example.com', 'commit', '--quiet', '--allow-empty', '-m', $message], $seed);
        $this->git(['push', '--quiet', 'origin', 'HEAD:refs/heads/'.$this->branch], $seed);

        return trim($this->git(['rev-parse', 'HEAD'], $seed));
    }

    /** Add a symlink to the remote (to prove LabMan refuses to follow it). */
    public function commitSymlink(string $path, string $target): void
    {
        $seed = $this->base.'/seed';
        $this->commitFromElsewhere([], 'noop');
        @mkdir(dirname($seed.'/'.$path), 0775, true);
        symlink($target, $seed.'/'.$path);
        $this->git(['add', '-A'], $seed);
        $this->git(['-c', 'user.name=Someone', '-c', 'user.email=someone@example.com', 'commit', '--quiet', '-m', 'symlink'], $seed);
        $this->git(['push', '--quiet', 'origin', 'HEAD:refs/heads/'.$this->branch], $seed);
    }

    public function remoteHead(): string
    {
        return trim($this->git(['--git-dir='.$this->remote, 'rev-parse', $this->branch], $this->base));
    }

    public function remoteFile(string $path): ?string
    {
        $process = new Process(['git', '--git-dir='.$this->remote, 'show', $this->branch.':'.$path], $this->base);
        $process->run();

        return $process->isSuccessful() ? $process->getOutput() : null;
    }

    /** @return list<string> subjects of the commits on the branch, newest first */
    public function log(): array
    {
        return array_values(array_filter(explode("\n", $this->git(['--git-dir='.$this->remote, 'log', '--format=%s', $this->branch], $this->base))));
    }

    public function lastAuthor(): string
    {
        return trim($this->git(['--git-dir='.$this->remote, 'log', '-1', '--format=%an <%ae>', $this->branch], $this->base));
    }

    public function cleanup(): void
    {
        (new Process(['rm', '-rf', '--', $this->base]))->run();
    }

    /** @param list<string> $args */
    private function git(array $args, string $cwd, bool $tolerate = false): string
    {
        $process = new Process(['git', ...$args], $cwd, ['HOME' => sys_get_temp_dir(), 'GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_NOSYSTEM' => '1', 'GIT_TERMINAL_PROMPT' => '0']);
        $process->run();

        if (! $process->isSuccessful() && ! $tolerate) {
            throw new \RuntimeException('git '.implode(' ', $args).' failed: '.$process->getErrorOutput().$process->getOutput());
        }

        return $process->getOutput();
    }
}
