<?php

namespace App\Icons;

use App\Models\Server;
use App\Models\Service;
use App\Support\Settings;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Service and server icons from the public Dashboard Icons library (https://github.com/homarr-labs/dashboard-icons,
 * Apache-2.0; the logos belong to their respective owners). Nothing is bundled: LabMan fetches an icon from the
 * jsDelivr CDN the first time it is needed and caches it under storage/app/icons, so visitors' browsers only ever
 * talk to LabMan. An icon is chosen by name: set one by hand (`icon`), or leave it blank and the service/server
 * name is matched against the library's index. "none" switches the icon off for one item.
 */
final class Icons
{
    public const BASE = 'https://cdn.jsdelivr.net/gh/homarr-labs/dashboard-icons';

    private const INDEX_TTL = 7 * 86400;
    private const MISS_TTL = 86400;

    /** Names people use that differ from the library's file names. Applied only when the target exists. */
    private const ALIASES = [
        'npm' => 'nginx-proxy-manager', 'nginxproxymanager' => 'nginx-proxy-manager',
        'semaphore' => 'semaphore-ui', 'semaphoreui' => 'semaphore-ui',
        'ha' => 'home-assistant', 'homeassistant' => 'home-assistant', 'hass' => 'home-assistant',
        'pve' => 'proxmox', 'proxmoxve' => 'proxmox',
        'adguard' => 'adguard-home', 'adguardhome' => 'adguard-home',
        'pihole' => 'pi-hole', 'uptimekuma' => 'uptime-kuma',
        'vscode' => 'visual-studio-code', 'codeserver' => 'code-server',
        'frigatenvr' => 'frigate', 'go2rtc' => 'go2rtc',
        'pikvm' => 'pikvm', 'octoprint' => 'octoprint', 'cups' => 'cups',
        'truenas' => 'truenas-scale', 'unifi' => 'unifi', 'unifinetwork' => 'unifi',
        'qbit' => 'qbittorrent', 'jellyseerr' => 'jellyseerr', 'transmission' => 'transmission',
    ];

    /** Words that are descriptions, not part of an app's name ("Plex Media Server" -> plex). */
    private const NOISE = ['server', 'app', 'web', 'ui', 'dashboard', 'admin', 'manager', 'service', 'media', 'the', 'my', 'lan', 'wifi', 'local'];

    /** @var array<string, string>|null normalised name => slug */
    private static ?array $index = null;

    public static function directory(): string
    {
        return (string) config('labman.icons.path');
    }

    public static function validSlug(string $slug): bool
    {
        return (bool) preg_match('/^[a-z0-9][a-z0-9._-]{0,79}$/', $slug);
    }

    // ------------------------------------------------------------------ index

    /**
     * The library's icon names, keyed by name without punctuation (so "home-assistant" is found as "homeassistant").
     * Reads the cache only; call refresh() to (re)download it.
     *
     * @return array<string, string>
     */
    public static function index(): array
    {
        if (self::$index !== null) {
            return self::$index;
        }

        $file = self::directory().'/index.json';
        $names = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        $index = [];
        foreach (is_array($names) ? $names : [] as $slug) {
            if (is_string($slug) && self::validSlug($slug)) {
                $index[self::normalise($slug)] = $index[self::normalise($slug)] ?? $slug;
            }
        }

        return self::$index = $index;
    }

    public static function forget(): void
    {
        self::$index = null;
    }

    /** Download the index when there is none or it is old. Returns false when it could not be fetched. */
    public static function refresh(bool $force = false): bool
    {
        if (! Settings::icons() || ! config('labman.icons.fetch')) {
            return false;
        }

        $file = self::directory().'/index.json';

        if (! $force && is_file($file) && filemtime($file) > time() - self::INDEX_TTL) {
            return true;
        }

        // Offline or blocked: do not retry on every round or page; try again in an hour.
        $failed = self::directory().'/index.failed';
        if (! $force && is_file($failed) && filemtime($failed) > time() - 3600) {
            return is_file($file);
        }

        try {
            $response = Http::timeout(10)->withUserAgent('LabMan icons')->get(self::BASE.'/tree.json');
            $tree = $response->successful() ? $response->json() : null;
        } catch (Throwable) {
            $tree = null;
        }

        $names = [];
        foreach (is_array($tree) ? $tree : [] as $list) {
            foreach (is_array($list) ? $list : [] as $entry) {
                if (is_string($entry) && preg_match('/^([A-Za-z0-9][A-Za-z0-9._-]*)\.(svg|png)$/', $entry, $m)) {
                    $names[strtolower($m[1])] = true;
                }
            }
        }

        if ($names === []) {
            self::ensureDirectory();
            @touch($failed);

            return false; // keep whatever is cached
        }

        self::ensureDirectory();
        @unlink($failed);
        file_put_contents($file, json_encode(array_keys($names)), LOCK_EX);
        self::forget();

        return true;
    }

    // ---------------------------------------------------------------- matching

    /** The slug to show for a service or server, or null for none. */
    public static function slugFor(Service|Server|\App\Models\Link $model): ?string
    {
        $icon = $model->icon;

        if ($icon === 'none') {
            return null;
        }

        // LabMan's own logo ships with the app: no library, no download, so it shows even with icons switched off.
        if ($icon === self::OWN || (blank($icon) && self::isLabman($model))) {
            return self::OWN;
        }

        if (! Settings::icons()) {
            return null;
        }

        if (filled($icon)) {
            return $icon;
        }

        return self::match($model->name, $model instanceof \App\Models\Link ? null : $model->domain);
    }

    /** The slug of LabMan's own logo. */
    public const OWN = 'labman';

    /** Is this item LabMan itself: named LabMan ("LabMan", "LabMan itself") or served from a labman.* name? */
    public static function isLabman(Service|Server|\App\Models\Link $model): bool
    {
        if (str_starts_with(self::normalise((string) $model->name), 'labman')) {
            return true;
        }
        $domain = $model instanceof \App\Models\Link ? null : $model->domain;

        return $domain !== null && strtolower(explode('.', $domain)[0]) === 'labman';
    }

    /** Best library match for a display name (and optionally a domain), or null. */
    public static function match(string $name, ?string $domain = null): ?string
    {
        $index = self::index();

        if ($index === []) {
            return null;
        }

        $candidates = [self::normalise($name)];

        $words = array_values(array_filter(preg_split('/[^a-z0-9]+/', strtolower($name))));
        $meaningful = array_values(array_filter($words, fn ($w) => ! in_array($w, self::NOISE, true)));

        if ($meaningful !== []) {
            $candidates[] = implode('', $meaningful);
            foreach ($meaningful as $word) {
                $candidates[] = $word;
            }
        }
        if ($domain) {
            $candidates[] = self::normalise((string) strtok($domain, '.'));
        }

        foreach (array_unique(array_filter($candidates, fn ($c) => $c !== '')) as $candidate) {
            if (isset($index[$candidate])) {
                return $index[$candidate];
            }
            if (isset(self::ALIASES[$candidate])) {
                $alias = self::normalise(self::ALIASES[$candidate]);
                if (isset($index[$alias])) {
                    return $index[$alias];
                }
            }
        }

        // "Jellyfin Media Server" -> jellyfin, "Nextcloud AIO" -> nextcloud: a long library name at the start of the name.
        $joined = $candidates[1] ?? $candidates[0];
        $best = null;
        foreach ($index as $key => $slug) {
            if (strlen($key) >= 5 && str_starts_with($joined, $key) && ($best === null || strlen($key) > strlen($best))) {
                $best = $key;
            }
        }

        return $best === null ? null : $index[$best];
    }

    /** Library names that start with the typed text, for the form's suggestions. @return list<string> */
    public static function suggest(string $query, int $limit = 12): array
    {
        $needle = self::normalise($query);

        if ($needle === '') {
            return [];
        }

        $out = [];
        foreach (self::index() as $key => $slug) {
            if (str_contains($key, $needle)) {
                $out[] = $slug;
            }
        }

        usort($out, fn ($a, $b) => [! str_starts_with(self::normalise($a), $needle), strlen($a)] <=> [! str_starts_with(self::normalise($b), $needle), strlen($b)]);

        return array_slice($out, 0, $limit);
    }

    // ----------------------------------------------------------------- files

    /**
     * The cached image for a slug, downloading it on first use. Only names in the library's index are ever requested.
     *
     * @return array{body: string, type: string}|null
     */
    public static function image(string $slug): ?array
    {
        if ($slug === self::OWN && is_file(public_path('favicon.svg'))) {
            return ['body' => (string) file_get_contents(public_path('favicon.svg')), 'type' => 'image/svg+xml'];
        }

        if (! self::validSlug($slug) || ! Settings::icons()) {
            return null;
        }

        foreach (['svg' => 'image/svg+xml', 'png' => 'image/png'] as $ext => $type) {
            $file = self::directory()."/{$slug}.{$ext}";
            if (is_file($file)) {
                return ['body' => (string) file_get_contents($file), 'type' => $type];
            }
        }

        $miss = self::directory()."/{$slug}.miss";
        if (is_file($miss) && filemtime($miss) > time() - self::MISS_TTL) {
            return null;
        }

        self::refresh();
        if (! in_array($slug, self::index(), true)) {
            return null; // never fetch a name the library does not list
        }

        foreach (['svg' => 'image/svg+xml', 'png' => 'image/png'] as $ext => $type) {
            try {
                $response = Http::timeout(10)->withUserAgent('LabMan icons')->get(self::BASE."/{$ext}/{$slug}.{$ext}");
            } catch (Throwable) {
                continue;
            }

            $body = $response->successful() ? $response->body() : '';

            if ($body !== '' && strlen($body) < 400_000 && self::looksLike($ext, $body)) {
                self::ensureDirectory();
                file_put_contents(self::directory()."/{$slug}.{$ext}", $body, LOCK_EX);

                return ['body' => $body, 'type' => $type];
            }
        }

        self::ensureDirectory();
        @touch($miss);

        return null;
    }

    private static function looksLike(string $ext, string $body): bool
    {
        return $ext === 'png'
            ? str_starts_with($body, "\x89PNG")
            : str_contains(substr($body, 0, 1024), '<svg');
    }

    private static function ensureDirectory(): void
    {
        if (! is_dir(self::directory())) {
            @mkdir(self::directory(), 0775, true);
        }
    }

    private static function normalise(string $value): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', strtolower($value));
    }
}
