<?php

namespace App\Monitoring;

/**
 * What is known about checking common self-hosted apps: the URL path that is the reliable "is it alive"
 * endpoint, and when the app is normally reached over https. Matched from the service name (and domain).
 * These are only suggestions: nothing changes until an admin applies one.
 */
final class HealthHints
{
    /**
     * keyword (letters and digits only) => [label, health path, scheme, note]
     *
     * @var array<string, array{0: string, 1: ?string, 2: ?string, 3: ?string}>
     */
    private const APPS = [
        'jellyfin' => ['Jellyfin', '/health', null, null],
        'plex' => ['Plex', '/identity', null, 'Plex answers 401 on / without a token; /identity is open.'],
        'sonarr' => ['Sonarr', '/ping', null, null],
        'radarr' => ['Radarr', '/ping', null, null],
        'lidarr' => ['Lidarr', '/ping', null, null],
        'readarr' => ['Readarr', '/ping', null, null],
        'prowlarr' => ['Prowlarr', '/ping', null, null],
        'jellyseerr' => ['Jellyseerr', '/api/v1/status', null, null],
        'overseerr' => ['Overseerr', '/api/v1/status', null, null],
        'tautulli' => ['Tautulli', '/status', null, null],
        'grafana' => ['Grafana', '/api/health', null, null],
        'prometheus' => ['Prometheus', '/-/healthy', null, null],
        'alertmanager' => ['Alertmanager', '/-/healthy', null, null],
        'loki' => ['Loki', '/ready', null, null],
        'influxdb' => ['InfluxDB', '/health', null, null],
        'minio' => ['MinIO', '/minio/health/live', null, null],
        'portainer' => ['Portainer', '/api/system/status', null, null],
        'nextcloud' => ['Nextcloud', '/status.php', null, 'Nextcloud rejects unknown host names; the Host header LabMan sends for services with a domain handles that.'],
        'vaultwarden' => ['Vaultwarden', '/alive', null, null],
        'gitea' => ['Gitea', '/api/healthz', null, null],
        'forgejo' => ['Forgejo', '/api/healthz', null, null],
        'syncthing' => ['Syncthing', '/rest/noauth/health', null, null],
        'immich' => ['Immich', '/api/server/ping', null, null],
        'mealie' => ['Mealie', '/api/app/about', null, null],
        'navidrome' => ['Navidrome', '/ping', null, null],
        'audiobookshelf' => ['Audiobookshelf', '/healthcheck', null, null],
        'codeserver' => ['code-server', '/healthz', null, 'code-server listens on 127.0.0.1 by default: bind it to 0.0.0.0 to reach it from LabMan.'],
        'gotify' => ['Gotify', '/health', null, null],
        'ntfy' => ['ntfy', '/v1/health', null, null],
        'semaphore' => ['Semaphore', '/api/ping', null, null],
        'nginxproxymanager' => ['Nginx Proxy Manager', '/api/', null, 'The admin UI is on port 81.'],
        'emqx' => ['EMQX', '/status', null, 'The dashboard is on port 18083.'],
        'frigate' => ['Frigate', '/api/version', null, null],
        'homeassistant' => ['Home Assistant', '/', null, 'Home Assistant can take several seconds to answer while it starts.'],
        'pihole' => ['Pi-hole', '/admin/', null, null],
        'adguard' => ['AdGuard Home', '/', null, null],
        'uptimekuma' => ['Uptime Kuma', '/', null, null],
        'qbittorrent' => ['qBittorrent', '/', null, null],
        'sabnzbd' => ['SABnzbd', '/', null, null],
        'homepage' => ['Homepage', '/api/healthcheck', null, null],
        'proxmox' => ['Proxmox', '/', 'https', 'Proxmox serves https on port 8006 with a self-signed certificate (LabMan does not verify backend certificates).'],
        'pikvm' => ['PiKVM', '/', 'https', 'PiKVM serves https on port 443.'],
        'unifi' => ['UniFi', '/', 'https', 'The UniFi controller serves https, usually on 8443.'],
        'truenas' => ['TrueNAS', '/', null, null],
        'cups' => ['CUPS', '/', null, 'CUPS listens on port 631 and may redirect plain http to https.'],
        'octoprint' => ['OctoPrint', '/api/version', null, null],
        'fluidd' => ['Fluidd', '/', null, null],
        'mainsail' => ['Mainsail', '/', null, null],
    ];

    /** Generic endpoints worth trying when nothing is known about an app. */
    public const COMMON_PATHS = ['/', '/health', '/healthz', '/ping', '/api/health', '/status', '/login'];

    /** @return array{app: string, health_path: ?string, scheme: ?string, note: ?string}|null */
    public static function find(string $name, ?string $domain = null): ?array
    {
        $haystacks = array_filter([self::normalize($name), self::normalize((string) strtok((string) $domain, '.'))]);

        // Exact keyword first, then the longest keyword contained in the name ("Jellyfin Media" -> jellyfin).
        foreach ($haystacks as $text) {
            if (isset(self::APPS[$text])) {
                return self::result($text);
            }
        }

        $best = null;
        foreach ($haystacks as $text) {
            foreach (array_keys(self::APPS) as $keyword) {
                if (strlen($keyword) >= 4 && str_contains($text, $keyword) && ($best === null || strlen($keyword) > strlen($best))) {
                    $best = $keyword;
                }
            }
        }

        return $best === null ? null : self::result($best);
    }

    /** @return array{app: string, health_path: ?string, scheme: ?string, note: ?string} */
    private static function result(string $keyword): array
    {
        [$app, $path, $scheme, $note] = self::APPS[$keyword];

        return ['app' => $app, 'health_path' => $path, 'scheme' => $scheme, 'note' => $note];
    }

    private static function normalize(string $value): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', strtolower($value));
    }
}
