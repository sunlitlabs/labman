<?php

namespace App\Inventory;

use InvalidArgumentException;

/**
 * Validates, tests and saves the inventory repository connection (shared by the web form and the MCP tool).
 *
 * The repository can be given as a plain address or as a link to the file in a git web UI (it is parsed into
 * address, branch and path), and the git user name is optional: it is worked out from the token.
 */
final class Connector
{
    /** Test hook: builds the working copy used to reach the repository. @var (\Closure(array<string, string>): GitRepo)|null */
    public static ?\Closure $repoFactory = null;

    /**
     * @param  array<string, mixed>  $in  repo_url (address or file link), branch, path, username, token (optional when the address is unchanged), author_name, author_email
     * @return array{hosts: int, path: string, username: string}
     *
     * @throws InventoryException
     */
    public static function connect(array $in): array
    {
        $existing = RepoConfig::get();

        // A pasted link to a file (or repo, or ssh clone address) fills in what the other fields leave blank.
        $given = trim((string) ($in['repo_url'] ?? ''));
        $parsed = config('labman.git.allow_local') && str_starts_with($given, '/') ? null : RepoUrl::parse($given);
        $branchIn = trim((string) ($in['branch'] ?? ''));
        $pathIn = trim((string) ($in['path'] ?? ''));

        try {
            $url = RepoConfig::cleanUrl($parsed['repo_url'] ?? $given);
            $values = [
                'url' => $url,
                'branch' => RepoConfig::cleanBranch($branchIn !== '' ? $branchIn : ($parsed['branch'] ?? 'main')),
                'path' => RepoConfig::cleanPath($pathIn !== '' ? $pathIn : ($parsed['path'] ?? '')),
                'author_name' => RepoConfig::cleanIdentity((string) (($in['author_name'] ?? '') !== '' ? $in['author_name'] : 'LabMan'), 'name'),
                'author_email' => RepoConfig::cleanIdentity((string) (($in['author_email'] ?? '') !== '' ? $in['author_email'] : 'labman@localhost'), 'e-mail'),
            ];
        } catch (InvalidArgumentException $e) {
            throw new InventoryException($pathIn === '' && ($parsed['path'] ?? null) === null
                ? 'Which file is the inventory? Paste a link to it (for example the file in GitHub), or enter its path in the repository.'
                : $e->getMessage());
        }

        // A saved token is only reused for the same repository: never hand it to a different address.
        $token = filled($in['token'] ?? null) ? (string) $in['token'] : (($existing && $existing['url'] === $url) ? $existing['token'] : null);
        if ($token === null) {
            throw new InventoryException('Enter the access token for this repository.');
        }

        [$repo, $username] = self::reach($values, $token, trim((string) ($in['username'] ?? '')));

        $content = $repo->read($values['path']);
        if ($content === null) {
            $hint = $repo->candidates();
            throw new InventoryException("The file '{$values['path']}' does not exist on branch '{$values['branch']}'."
                .($hint ? ' Inventory-like files here: '.implode(', ', array_slice($hint, 0, 8)).'.' : ''));
        }

        $hosts = Inventory::parse($content, $values['path'])->hosts();

        RepoConfig::save($values + ['username' => $username], $token);

        return ['hosts' => count($hosts), 'path' => $values['path'], 'username' => $username];
    }

    /**
     * Clone with the right user name. Given explicitly it is used as is; otherwise the host's usual name is
     * tried, then the owner of the token as reported by the host's API.
     *
     * @param  array<string, string>  $values
     * @return array{0: GitRepo, 1: string} the working copy and the user name that worked
     *
     * @throws InventoryException
     */
    private static function reach(array $values, string $token, string $username): array
    {
        if ($username !== '') {
            if (! preg_match('/^[A-Za-z0-9_.@\-]{1,100}$/', $username)) {
                throw new InventoryException('The git user name may only contain letters, digits and _ . @ -.');
            }

            $repo = self::repo($values + ['username' => $username, 'token' => $token]);
            $repo->sync();

            return [$repo, $username];
        }

        $tried = [];
        $last = null;

        $try = function (string $name) use ($values, $token, &$tried, &$last): ?array {
            if (in_array($name, $tried, true)) {
                return null;
            }
            $tried[] = $name;

            try {
                $repo = self::repo($values + ['username' => $name, 'token' => $token]);
                $repo->sync();

                return [$repo, $name];
            } catch (InventoryException $e) {
                $last = $e;

                return null;
            }
        };

        foreach (UsernameGuess::candidates($values['url']) as $name) {
            if ($found = $try($name)) {
                return $found;
            }
            if (! self::isAuthProblem($last)) {
                throw $last; // not a credentials problem (host down, branch missing...): another name will not help
            }
        }

        $owner = UsernameGuess::fromApi($values['url'], $token);
        if ($owner !== null && ($found = $try($owner))) {
            return $found;
        }

        throw $last ?? new InventoryException('Could not reach the repository.');
    }

    /** @param array<string, string> $config */
    private static function repo(array $config): GitRepo
    {
        return (self::$repoFactory ?? fn (array $c) => new GitRepo($c))($config);
    }

    private static function isAuthProblem(?InventoryException $e): bool
    {
        return $e !== null && (str_contains($e->getMessage(), 'rejected the username') || str_contains($e->getMessage(), 'was not found'));
    }
}
