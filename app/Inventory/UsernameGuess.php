<?php

namespace App\Inventory;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Works out the git user name that goes with an access token, so the admin only has to paste the token.
 * Hosted services have fixed conventions (GitHub accepts any name with a PAT, GitLab wants "oauth2",
 * Bitbucket "x-token-auth"); for self-hosted Gitea/Forgejo/GitLab the host's own API says who owns the token.
 */
final class UsernameGuess
{
    /** Names worth trying first, without any network call. @return list<string> */
    public static function candidates(string $repoUrl): array
    {
        $host = strtolower((string) parse_url($repoUrl, PHP_URL_HOST));

        return match (true) {
            $host === 'github.com' || $host === 'www.github.com' => ['x-access-token'],
            str_contains($host, 'gitlab') => ['oauth2'],
            $host === 'bitbucket.org' => ['x-token-auth'],
            default => ['git'],
        };
    }

    /**
     * Ask the host's API who owns the token. Tries GitHub, Gitea/Forgejo and GitLab shapes depending on the host.
     * Returns null when the host does not say.
     */
    public static function fromApi(string $repoUrl, string $token): ?string
    {
        $parts = parse_url($repoUrl);
        if (! $parts || empty($parts['host'])) {
            return null;
        }

        $host = strtolower($parts['host']);
        $origin = ($parts['scheme'] ?? 'https').'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        $attempts = $host === 'github.com' || $host === 'www.github.com'
            ? [['https://api.github.com/user', ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/vnd.github+json'], 'login']]
            : [
                [$origin.'/api/v1/user', ['Authorization' => 'token '.$token], 'login'],           // Gitea / Forgejo / Codeberg
                [$origin.'/api/v4/user', ['PRIVATE-TOKEN' => $token], 'username'],                  // GitLab
            ];

        foreach ($attempts as [$url, $headers, $field]) {
            try {
                $response = Http::withHeaders($headers)->timeout(6)->connectTimeout(4)->withoutRedirecting()->get($url);
                $name = $response->successful() ? $response->json($field) : null;

                if (is_string($name) && preg_match('/^[A-Za-z0-9_.@\-]{1,100}$/', $name)) {
                    return $name;
                }
            } catch (Throwable) {
                // not this kind of host, or unreachable: try the next shape
            }
        }

        return null;
    }
}
