<?php

namespace App\Inventory;

use InvalidArgumentException;

/** Validates, tests and saves the inventory repository connection (shared by the web form and the MCP tool). */
final class Connector
{
    /**
     * @param  array<string, mixed>  $in  repo_url, branch, path, username, token (optional when the address is unchanged), author_name, author_email
     * @return array{hosts: int, path: string}
     *
     * @throws InventoryException
     */
    public static function connect(array $in): array
    {
        $existing = RepoConfig::get();

        try {
            $url = RepoConfig::cleanUrl((string) ($in['repo_url'] ?? ''));
            $values = [
                'url' => $url,
                'branch' => RepoConfig::cleanBranch((string) ($in['branch'] ?? 'main')),
                'path' => RepoConfig::cleanPath((string) ($in['path'] ?? '')),
                'username' => self::username((string) ($in['username'] ?? 'git')),
                'author_name' => RepoConfig::cleanIdentity((string) ($in['author_name'] ?? 'LabMan'), 'name'),
                'author_email' => RepoConfig::cleanIdentity((string) ($in['author_email'] ?? 'labman@localhost'), 'e-mail'),
            ];
        } catch (InvalidArgumentException $e) {
            throw new InventoryException($e->getMessage());
        }

        // A saved token is only reused for the same repository: never hand it to a different address.
        $token = filled($in['token'] ?? null) ? (string) $in['token'] : (($existing && $existing['url'] === $url) ? $existing['token'] : null);
        if ($token === null) {
            throw new InventoryException('Enter the access token for this repository.');
        }

        // Prove it works before saving: clone, find the file, read it.
        $repo = new GitRepo($values + ['token' => $token]);
        $repo->sync();
        $content = $repo->read($values['path']);

        if ($content === null) {
            $hint = $repo->candidates();
            throw new InventoryException("The file '{$values['path']}' does not exist on branch '{$values['branch']}'."
                .($hint ? ' Inventory-like files here: '.implode(', ', array_slice($hint, 0, 8)).'.' : ''));
        }

        $hosts = Inventory::parse($content, $values['path'])->hosts();

        RepoConfig::save($values, $token);

        return ['hosts' => count($hosts), 'path' => $values['path']];
    }

    /** @throws InventoryException */
    private static function username(string $username): string
    {
        $username = trim($username);

        if (! preg_match('/^[A-Za-z0-9_.@\-]{1,100}$/', $username)) {
            throw new InventoryException('The git user name may only contain letters, digits and _ . @ -.');
        }

        return $username;
    }
}
