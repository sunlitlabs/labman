<?php

namespace Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * An in-memory stand-in for the Nginx Proxy Manager API, shaped from responses recorded
 * against a real NPM 2.17 container (token login, proxy-host list/create/partial update).
 */
final class FakeNpm
{
    public const URL = 'http://npm.test:81';

    /** @var array<int, array<string, mixed>> */
    public array $hosts = [];

    /** @var list<array{method: string, path: string, body: array}> every request seen */
    public array $requests = [];

    /** @var list<array<string, mixed>> */
    public array $certificates = [];

    public bool $twoFactor = false;

    public string $password = 'npm-secret';

    private int $nextId = 1;

    /** The address this instance answers on (tests with several proxies install one instance per address). */
    public string $url = self::URL;

    /** @var array<string, self> instances by address, so one Http fake can serve several proxies */
    private static array $all = [];

    /** Installing at the default address starts a fresh set; other addresses add a second proxy. */
    public static function install(string $url = self::URL): self
    {
        $npm = new self;
        $npm->url = $url;

        if ($url === self::URL) {
            self::$all = [];
        }
        self::$all[$url] = $npm;

        Http::fake(function (Request $request) {
            foreach (self::$all as $url => $instance) {
                if (str_starts_with($request->url(), $url)) {
                    return $instance->handle($request);
                }
            }

            return Http::response('not npm', 404);
        });

        return $npm;
    }

    /** @param array<string, mixed> $attributes */
    public function addHost(array $attributes): int
    {
        $id = $this->nextId++;
        $this->hosts[$id] = $attributes + [
            'id' => $id, 'created_on' => '2026-01-01 00:00:00', 'modified_on' => '2026-01-01 00:00:00', 'owner_user_id' => 1,
            'domain_names' => ['host'.$id.'.example.com'], 'forward_scheme' => 'http', 'forward_host' => '192.0.2.50', 'forward_port' => 80,
            'access_list_id' => 0, 'certificate_id' => 0, 'ssl_forced' => false, 'caching_enabled' => false, 'block_exploits' => true,
            'advanced_config' => '', 'allow_websocket_upgrade' => false, 'http2_support' => false, 'enabled' => true,
            'locations' => [], 'hsts_enabled' => false, 'hsts_subdomains' => false, 'meta' => ['nginx_online' => true],
        ];

        return $id;
    }

    /** @return list<array{method: string, path: string, body: array}> */
    public function writes(): array
    {
        return array_values(array_filter($this->requests, fn ($r) => in_array($r['method'], ['POST', 'PUT', 'DELETE', 'PATCH'], true) && $r['path'] !== '/api/tokens'));
    }

    /** @return list<string> */
    public function methods(): array
    {
        return array_unique(array_column($this->requests, 'method'));
    }

    public function handle(Request $request)
    {
        $path = parse_url($request->url(), PHP_URL_PATH);
        $method = $request->method();
        $body = $request->data();

        if (! str_starts_with($request->url(), $this->url)) {
            return Http::response('not npm', 404);
        }

        $this->requests[] = ['method' => $method, 'path' => $path, 'body' => $body];

        if ($path === '/api/' && $method === 'GET') {
            return Http::response(['status' => 'OK', 'setup' => true, 'version' => ['major' => 2, 'minor' => 17, 'revision' => 0]]);
        }

        if ($path === '/api/tokens' && $method === 'POST') {
            if (array_diff(array_keys($body), ['identity', 'secret'])) {
                return Http::response(['error' => ['code' => 400, 'message' => 'data must NOT have additional properties']], 400);
            }
            if (($body['secret'] ?? null) !== $this->password) {
                return Http::response(['error' => ['code' => 400, 'message' => 'Invalid email or password']], 400);
            }

            return Http::response($this->twoFactor ? ['requires_2fa' => true, 'challenge_token' => 'c'] : ['token' => 'fake-jwt', 'expires' => '2027-01-01T00:00:00.000Z']);
        }

        if (($request->header('Authorization')[0] ?? '') !== 'Bearer fake-jwt') {
            return Http::response(['error' => ['code' => 401, 'message' => 'Unauthorized']], 401);
        }

        if ($path === '/api/nginx/certificates' && $method === 'GET') {
            return Http::response($this->certificates);
        }

        if ($path === '/api/nginx/proxy-hosts' && $method === 'GET') {
            return Http::response(array_values($this->hosts));
        }

        if ($path === '/api/nginx/proxy-hosts' && $method === 'POST') {
            foreach ($this->hosts as $h) {
                if (array_intersect(array_map('strtolower', $h['domain_names']), array_map('strtolower', $body['domain_names'] ?? []))) {
                    return Http::response(['error' => ['code' => 400, 'message' => ($body['domain_names'][0] ?? '').' is already in use']], 400);
                }
            }
            $id = $this->addHost($body);

            return Http::response($this->hosts[$id], 201);
        }

        if (preg_match('#^/api/nginx/proxy-hosts/(\d+)$#', $path, $m) && $method === 'PUT') {
            $id = (int) $m[1];
            if (! isset($this->hosts[$id])) {
                return Http::response(['error' => ['code' => 404, 'message' => 'Not Found']], 404);
            }
            $this->hosts[$id] = array_merge($this->hosts[$id], $body, ['modified_on' => '2026-06-01 00:00:00']);

            return Http::response($this->hosts[$id]);
        }

        return Http::response(['error' => ['code' => 404, 'message' => 'Not Found']], 404);
    }
}
