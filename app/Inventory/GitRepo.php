<?php

namespace App\Inventory;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * A disposable working copy of the inventory repository, driven through the git CLI.
 * Authentication uses a personal access token handed to git through GIT_ASKPASS and the
 * environment of one process, so the token is never in a URL, argv, git config or on disk.
 * LabMan never keeps local edits: every operation starts by resetting to the remote branch.
 */
class GitRepo
{
    /** @param array{url: string, branch: string, username: string, token: string, author_name: string, author_email: string} $config */
    public function __construct(private readonly array $config, private readonly ?string $dir = null) {}

    public function dir(): string
    {
        return $this->dir ?? storage_path('app/inventory-repo');
    }

    /** Clone or update the working copy to the remote branch tip and return its commit id. @throws InventoryException */
    public function sync(): string
    {
        return $this->locked(function () {
            $this->ensureCloneMatchesConfig();

            if (! is_dir($this->dir().'/.git')) {
                @mkdir(dirname($this->dir()), 0775, true);
                $this->run(['clone', '--quiet', '--depth', '1', '--no-tags', '--branch', $this->config['branch'], '--single-branch', '--', $this->config['url'], $this->dir()], cwd: dirname($this->dir()), auth: true);
                file_put_contents($this->metaFile(), $this->fingerprint());
            } else {
                $this->run(['fetch', '--quiet', '--depth', '1', '--no-tags', 'origin', $this->config['branch']], auth: true);
                $this->run(['reset', '--quiet', '--hard', $this->remoteRef()]);
                $this->run(['clean', '-fdq']);
            }

            return $this->head();
        });
    }

    public function head(): string
    {
        return trim($this->run(['rev-parse', 'HEAD']));
    }

    /** Contents of a tracked file, or null if it does not exist. Symlinks are refused. @throws InventoryException */
    public function read(string $path): ?string
    {
        $full = $this->resolve($path);

        if (! is_file($full)) {
            return null;
        }

        return (string) file_get_contents($full);
    }

    /** Files that look like inventories, to help pick one. @return list<string> */
    public function candidates(): array
    {
        $files = array_filter(explode("\0", $this->run(['ls-files', '-z'], raw: true)));

        $hits = array_filter($files, function ($file) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

            return in_array($ext, ['ini', 'yml', 'yaml', 'cfg'], true) && preg_match('/(inventor|hosts)/i', $file)
                || preg_match('#(^|/)(hosts|inventory)$#i', $file);
        });

        return array_slice(array_values($hits), 0, 50);
    }

    /**
     * The unified diff that applying these changes would produce (nothing is kept).
     *
     * @param  array<string, string>  $writes  path => new content
     * @param  list<string>  $deletes
     */
    public function preview(array $writes, array $deletes = []): string
    {
        return $this->locked(function () use ($writes, $deletes) {
            try {
                $this->stage($writes, $deletes);

                return $this->run(['diff', '--cached', '--no-color', '--no-ext-diff', '-U3'], raw: true);
            } finally {
                $this->discard();
            }
        });
    }

    /**
     * Commit the changes and push them to the branch. On any failure the working copy is reset and nothing is left behind.
     *
     * @param  array<string, string>  $writes
     * @param  list<string>  $deletes
     * @return string the new commit id
     *
     * @throws InventoryException
     */
    public function commitAndPush(array $writes, array $deletes, string $message): string
    {
        return $this->locked(function () use ($writes, $deletes, $message) {
            try {
                $this->stage($writes, $deletes);

                if (trim($this->run(['diff', '--cached', '--name-only'])) === '') {
                    throw new InventoryException('Nothing to commit: the inventory already has these contents.');
                }

                $this->run(['-c', 'user.name='.$this->config['author_name'], '-c', 'user.email='.$this->config['author_email'], 'commit', '--quiet', '-m', $message]);
                $this->run(['push', '--quiet', 'origin', 'HEAD:refs/heads/'.$this->config['branch']], auth: true);

                return $this->head();
            } catch (\Throwable $e) {
                $this->run(['reset', '--quiet', '--hard', $this->remoteRef()], tolerate: true);
                $this->run(['clean', '-fdq'], tolerate: true);

                throw $e;
            }
        });
    }

    // ============================================================== internals

    /** @param array<string, string> $writes @param list<string> $deletes */
    private function stage(array $writes, array $deletes): void
    {
        foreach ($writes as $path => $content) {
            $full = $this->resolve($path, forWrite: true);
            @mkdir(dirname($full), 0775, true);
            if (file_put_contents($full, $content) === false) {
                throw new InventoryException("Cannot write {$path} in the working copy.");
            }
        }

        foreach ($deletes as $path) {
            $full = $this->resolve($path);
            if (is_file($full)) {
                unlink($full);
            }
        }

        $this->run(['add', '-A']);
    }

    private function discard(): void
    {
        $this->run(['reset', '--quiet', '--hard', 'HEAD'], tolerate: true);
        $this->run(['clean', '-fdq'], tolerate: true);
    }

    /** Absolute path of a repo-relative file; refuses escapes, .git and symlinks. @throws InventoryException */
    private function resolve(string $path, bool $forWrite = false): string
    {
        try {
            $path = RepoConfig::cleanPath($path);
        } catch (\InvalidArgumentException $e) {
            throw new InventoryException($e->getMessage());
        }

        $full = $this->dir().'/'.$path;

        // Every existing component must be a real file/dir inside the repository, never a symlink.
        $check = $this->dir();
        foreach (explode('/', $path) as $part) {
            $check .= '/'.$part;
            if (is_link($check)) {
                throw new InventoryException("'{$path}' goes through a symbolic link, which LabMan will not follow.");
            }
        }

        return $full;
    }

    private function ensureCloneMatchesConfig(): void
    {
        if (is_dir($this->dir()) && (@file_get_contents($this->metaFile()) !== $this->fingerprint())) {
            $this->removeDir($this->dir());
        }
    }

    private function fingerprint(): string
    {
        return hash('sha256', $this->config['url']."\n".$this->config['branch']);
    }

    private function remoteRef(): string
    {
        return 'origin/'.$this->config['branch'];
    }

    private function metaFile(): string
    {
        return $this->dir().'.meta';
    }

    private function removeDir(string $dir): void
    {
        (new Process(['rm', '-rf', '--', $dir]))->run();
    }

    /** @template T @param \Closure(): T $work @return T */
    private function locked(\Closure $work): mixed
    {
        @mkdir(dirname($this->dir()), 0775, true);
        $handle = fopen($this->dir().'.lock', 'c');
        if ($handle === false || ! flock($handle, LOCK_EX)) {
            throw new InventoryException('Another inventory operation is running. Try again in a moment.');
        }

        try {
            return $work();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @param  list<string>  $args
     *
     * @throws InventoryException
     */
    private function run(array $args, ?string $cwd = null, bool $auth = false, bool $raw = false, bool $tolerate = false): string
    {
        $protocols = 'http:https'.(config('labman.git.allow_local') ? ':file' : '');

        // Start from an empty environment: in Symfony Process, a variable set to false is removed, so
        // nothing from LabMan's own environment (APP_KEY, database passwords, ...) reaches git.
        $env = array_map(fn () => false, getenv()) + [
            'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
            'HOME' => sys_get_temp_dir(),
            'LC_ALL' => 'C',
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_ALLOW_PROTOCOL' => $protocols,
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_CONFIG_GLOBAL' => '/dev/null',
        ];

        if ($auth) {
            $env += [
                'GIT_ASKPASS' => (string) config('labman.git.askpass'),
                'LABMAN_GIT_USERNAME' => $this->config['username'],
                'LABMAN_GIT_PASSWORD' => $this->config['token'],
            ];
        }

        $process = new Process(['git', '-c', 'core.hooksPath=/dev/null', '-c', 'core.fsmonitor=false', '-c', 'advice.detachedHead=false', ...$args], $cwd ?? $this->dir(), $env, null, (float) config('labman.git.timeout'));

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            if ($tolerate) {
                return '';
            }
            throw new InventoryException('git timed out talking to the repository.');
        }

        if (! $process->isSuccessful()) {
            if ($tolerate) {
                return '';
            }
            throw new InventoryException($this->explain($process->getErrorOutput().$process->getOutput()));
        }

        return $raw ? $process->getOutput() : trim($process->getOutput());
    }

    /** Make git's error readable and make sure the token can never appear in it. */
    private function explain(string $output): string
    {
        $output = trim(str_replace([$this->config['token'], base64_encode($this->config['username'].':'.$this->config['token'])], '***', $output));
        $lower = strtolower($output);

        return match (true) {
            str_contains($lower, 'authentication failed'), str_contains($lower, 'invalid username or password'), str_contains($lower, 'could not read username'),
            str_contains($lower, 'http basic: access denied'), str_contains($lower, 'error: 401'), str_contains($lower, 'returned error: 403') => 'The repository rejected the username or access token, or the token may not push to it.',
            str_contains($lower, 'remote branch') && str_contains($lower, 'not found') => "The branch '{$this->config['branch']}' does not exist in the repository.",
            str_contains($lower, 'not found'), str_contains($lower, 'does not exist'), str_contains($lower, 'does not appear to be a git repository') => 'The repository was not found, or the token cannot see it. Check the address.',
            str_contains($lower, 'could not resolve host') => 'Cannot resolve the repository host name.',
            str_contains($lower, 'connection refused'), str_contains($lower, 'failed to connect') => 'Cannot connect to the repository host.',
            str_contains($lower, 'ssl certificate'), str_contains($lower, 'certificate') => 'The repository certificate is not trusted.',
            str_contains($lower, 'non-fast-forward'), str_contains($lower, 'fetch first'), str_contains($lower, 'rejected') => 'The push was rejected, usually because someone pushed to the branch meanwhile. Compare again.',
            default => 'git failed: '.mb_substr($output, 0, 300),
        };
    }
}
