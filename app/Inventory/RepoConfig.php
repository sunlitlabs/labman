<?php

namespace App\Inventory;

use App\Support\BaseUrl;
use App\Support\Secrets;
use InvalidArgumentException;

/**
 * The git repository (the one Semaphore reads its inventory from) and the access token for it.
 * The token is stored encrypted and never shown, exported or returned by an MCP tool.
 */
final class RepoConfig
{
    private const PLAIN = ['url' => 'inventory.repo_url', 'branch' => 'inventory.branch', 'path' => 'inventory.path',
        'username' => 'inventory.username', 'author_name' => 'inventory.author_name', 'author_email' => 'inventory.author_email'];

    private const TOKEN = 'inventory.token';

    public static function exists(): bool
    {
        return self::get() !== null;
    }

    /** @return array{url: string, branch: string, path: string, username: string, token: string, author_name: string, author_email: string}|null */
    public static function get(): ?array
    {
        $values = array_map(fn ($key) => Secrets::plain($key), self::PLAIN);
        $token = Secrets::get(self::TOKEN);

        if ($values['url'] === null || $values['path'] === null || $token === null) {
            return null;
        }

        return [
            'url' => $values['url'],
            'branch' => $values['branch'] ?: 'main',
            'path' => $values['path'],
            'username' => $values['username'] ?: 'git',
            'token' => $token,
            'author_name' => $values['author_name'] ?: 'LabMan',
            'author_email' => $values['author_email'] ?: 'labman@localhost',
        ];
    }

    /** What is safe to show: everything except the token. @return array<string, string>|null */
    public static function summary(): ?array
    {
        $c = self::get();

        return $c ? array_diff_key($c, ['token' => 1]) : null;
    }

    /** @param array{url: string, branch: string, path: string, username: string, author_name: string, author_email: string} $values */
    public static function save(array $values, string $token): void
    {
        foreach (self::PLAIN as $field => $key) {
            Secrets::putPlain($key, $values[$field]);
        }
        Secrets::put(self::TOKEN, $token);
    }

    public static function setPath(string $path): void
    {
        Secrets::putPlain(self::PLAIN['path'], $path);
    }

    public static function forget(): void
    {
        Secrets::forget([...array_values(self::PLAIN), self::TOKEN]);
    }

    // ---------------------------------------------------------- validation

    /** @throws InvalidArgumentException */
    public static function cleanUrl(string $url): string
    {
        $url = trim($url);

        if (config('labman.git.allow_local') && str_starts_with($url, '/')) {
            return $url; // tests only: a local bare repository
        }

        $clean = BaseUrl::clean($url, 'https://git.example.com/org/inventory.git', allowPath: true);

        if (! preg_match('#^https?://[^/]+/.+#', $clean)) {
            throw new InvalidArgumentException('Enter the full repository address, for example https://git.example.com/org/inventory.git.');
        }

        return $clean;
    }

    /** @throws InvalidArgumentException */
    public static function cleanBranch(string $branch): string
    {
        $branch = trim($branch);

        if (! preg_match('#^[A-Za-z0-9][A-Za-z0-9._/\-]{0,99}$#', $branch) || str_contains($branch, '..') || str_ends_with($branch, '/') || str_ends_with($branch, '.lock')) {
            throw new InvalidArgumentException('The branch name may only contain letters, digits and . _ - /.');
        }

        return $branch;
    }

    /** A path inside the repository: no leading slash, no "..", no .git, safe characters only. @throws InvalidArgumentException */
    public static function cleanPath(string $path): string
    {
        $path = trim($path);

        if ($path === '' || ! preg_match('#^[A-Za-z0-9_.][A-Za-z0-9_./\-]{0,198}$#', $path) || str_contains($path, '..') || str_contains($path, '//')
            || str_ends_with($path, '/') || preg_match('#(^|/)\.git(/|$)#i', $path)) {
            throw new InvalidArgumentException('The inventory file path must be relative to the repository, for example inventory/hosts.yml.');
        }

        return $path;
    }

    /** A git user name / e-mail for commits: single line, no angle brackets. @throws InvalidArgumentException */
    public static function cleanIdentity(string $value, string $what): string
    {
        $value = trim($value);

        if ($value === '' || strlen($value) > 100 || preg_match('/[<>\r\n\x00]/', $value)) {
            throw new InvalidArgumentException("The commit {$what} must be 1-100 characters without < > or line breaks.");
        }

        return $value;
    }
}
