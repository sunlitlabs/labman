<?php

namespace App\Inventory;

/**
 * Turns whatever an admin pastes (a link to a file in the web UI of GitHub, GitLab, Gitea/Forgejo/Codeberg
 * or Bitbucket, a raw-file link, a plain repository address, or an SSH-style clone address) into the
 * repository address, branch and file path LabMan needs.
 *
 * Limitation: in a web link the branch and the path are separated by a slash, so a branch name that itself
 * contains a slash (release/1.0) cannot be told apart from a folder. The first segment is taken as the branch;
 * enter such branches by hand.
 */
final class RepoUrl
{
    /**
     * @return array{repo_url: string, branch: ?string, path: ?string, provider: string, repo: string, host: string, token_url: ?string}|null
     */
    public static function parse(string $input): ?array
    {
        $input = trim($input);
        if ($input === '' || strlen($input) > 500 || preg_match('/[\s<>"\']/', $input)) {
            return null;
        }

        // git@github.com:owner/repo.git  ->  https://github.com/owner/repo.git
        if (preg_match('#^(?:ssh://)?git@([A-Za-z0-9.\-]+)[:/](.+?)(?:\.git)?/?$#', $input, $m)) {
            $input = 'https://'.$m[1].'/'.$m[2];
        }

        if (! preg_match('#^[a-z][a-z0-9+.\-]*://#i', $input)) {
            $input = 'https://'.$input;
        }

        $parts = parse_url($input);
        if ($parts === false || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $host = strtolower($parts['host']);
        $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        $segments = array_values(array_filter(explode('/', rawurldecode($parts['path'] ?? '')), fn ($s) => $s !== ''));

        // raw.githubusercontent.com/owner/repo/branch/path/to/file
        if ($host === 'raw.githubusercontent.com' && count($segments) >= 2) {
            return self::result('github', 'https://github.com', $segments[0], $segments[1], $segments[2] ?? null, array_slice($segments, 3));
        }

        $provider = self::provider($host, $segments);
        if ($provider === 'github') {
            $origin = 'https://github.com'; // www.github.com and github.com are the same place
        }

        if ($provider === 'gitlab') {
            // group/subgroup/project/-/blob/branch/path  (the "-" separates the project from the view)
            $dash = array_search('-', $segments, true);
            $repoSegments = $dash === false ? $segments : array_slice($segments, 0, $dash);
            $view = $dash === false ? [] : array_slice($segments, $dash + 1);

            if (count($repoSegments) < 2) {
                return null;
            }
            $branch = in_array($view[0] ?? '', ['blob', 'tree', 'raw', 'blame', 'edit'], true) ? ($view[1] ?? null) : null;
            $path = $branch !== null ? array_slice($view, 2) : [];

            return self::result('gitlab', $origin, implode('/', array_slice($repoSegments, 0, -1)), end($repoSegments), $branch, $path);
        }

        if (count($segments) < 2) {
            return null;
        }
        [$owner, $repo] = [$segments[0], $segments[1]];
        $rest = array_slice($segments, 2);
        $branch = null;
        $path = [];

        if ($provider === 'github' && in_array($rest[0] ?? '', ['blob', 'tree', 'raw', 'blame', 'edit'], true)) {
            $branch = $rest[1] ?? null;
            $path = array_slice($rest, 2);
        } elseif ($provider === 'gitea' && in_array($rest[0] ?? '', ['src', 'raw', 'blame', 'commits'], true) && in_array($rest[1] ?? '', ['branch', 'tag', 'commit'], true)) {
            $branch = $rest[2] ?? null;
            $path = array_slice($rest, 3);
        } elseif ($provider === 'bitbucket' && ($rest[0] ?? '') === 'src') {
            $branch = $rest[1] ?? null;
            $path = array_slice($rest, 2);
        } elseif ($provider === 'other' && in_array($rest[0] ?? '', ['blob', 'tree', 'raw'], true)) {
            $branch = $rest[1] ?? null;
            $path = array_slice($rest, 2);
        } elseif ($provider === 'other' && ($rest[0] ?? '') === 'src' && in_array($rest[1] ?? '', ['branch', 'tag'], true)) {
            $branch = $rest[2] ?? null;
            $path = array_slice($rest, 3);
        }

        return self::result($provider, $origin, $owner, $repo, $branch, $path);
    }

    /** @param list<string> $segments */
    private static function provider(string $host, array $segments): string
    {
        return match (true) {
            $host === 'github.com' || $host === 'www.github.com' => 'github',
            str_contains($host, 'gitlab') || in_array('-', $segments, true) => 'gitlab',
            $host === 'bitbucket.org' || str_ends_with($host, '.bitbucket.org') => 'bitbucket',
            $host === 'codeberg.org' || str_contains($host, 'gitea') || str_contains($host, 'forgejo') || self::looksLikeGitea($segments) => 'gitea',
            default => 'other',
        };
    }

    /** @param list<string> $segments */
    private static function looksLikeGitea(array $segments): bool
    {
        return in_array($segments[2] ?? '', ['src', 'raw', 'blame', 'commits'], true) && in_array($segments[3] ?? '', ['branch', 'tag', 'commit'], true);
    }

    /** @param list<string> $path */
    private static function result(string $provider, string $origin, string $owner, string $repo, ?string $branch, array $path): array
    {
        $repo = (string) preg_replace('/\.git$/i', '', $repo);
        $addsGit = in_array($provider, ['github', 'gitlab', 'bitbucket'], true);
        $host = (string) parse_url($origin, PHP_URL_HOST);

        return [
            'repo_url' => $origin.'/'.$owner.'/'.$repo.($addsGit ? '.git' : ''),
            'branch' => $branch !== null && $branch !== '' ? $branch : null,
            'path' => $path ? implode('/', $path) : null,
            'provider' => $provider,
            'repo' => $owner.'/'.$repo,
            'host' => $host,
            'token_url' => match ($provider) {
                'github' => 'https://github.com/settings/personal-access-tokens/new',
                'gitlab' => $origin.'/-/user_settings/personal_access_tokens',
                'gitea' => $origin.'/user/settings/applications',
                default => null,
            },
        ];
    }
}
