<?php

namespace App\Npm;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * A minimal client for the Nginx Proxy Manager REST API: log in, list proxy hosts,
 * update one, create one. It never deletes anything.
 */
final class NpmClient
{
    private ?string $token = null;

    /** @param array{url: string, email: string, password: string, verify_tls: bool} $connection */
    public function __construct(private readonly array $connection, private readonly float $timeout = 10.0) {}

    /** @throws NpmException when nothing is configured */
    public static function fromStored(?\App\Models\Zone $zone = null): self
    {
        $connection = Connection::get($zone);

        if ($connection === null) {
            $where = $zone !== null && \App\Support\Zones::multiple() ? " for the {$zone->name} zone" : '';

            throw new NpmException("Nginx Proxy Manager is not connected{$where}. Save its address and login first.");
        }

        return new self($connection);
    }

    /** Log in and return NPM's version string ("2.12.3"), proving the credentials work. */
    public function test(): string
    {
        $this->login();
        $info = $this->send('get', '/api/')->json();
        $v = $info['version'] ?? null;

        return is_array($v) ? ($v['major'] ?? '?').'.'.($v['minor'] ?? '?').'.'.($v['revision'] ?? '?') : 'unknown';
    }

    /** @return list<array<string, mixed>> */
    public function proxyHosts(): array
    {
        $hosts = $this->authed('get', '/api/nginx/proxy-hosts')->json();

        return is_array($hosts) ? array_values(array_filter($hosts, 'is_array')) : [];
    }

    /**
     * The certificates uploaded to NPM, reduced to what a picker needs.
     *
     * @return list<array{id: int, name: string, domains: list<string>, expires_on: ?string, provider: string}>
     */
    public function certificates(): array
    {
        $certs = $this->authed('get', '/api/nginx/certificates')->json();
        $out = [];

        foreach (is_array($certs) ? $certs : [] as $cert) {
            if (is_array($cert) && isset($cert['id'])) {
                $domains = array_values(array_filter((array) ($cert['domain_names'] ?? []), 'is_string'));
                $out[] = [
                    'id' => (int) $cert['id'], 'name' => (string) ($cert['nice_name'] ?? '') ?: ($domains[0] ?? 'certificate '.$cert['id']), 'domains' => $domains,
                    'expires_on' => is_string($cert['expires_on'] ?? null) ? $cert['expires_on'] : null, 'provider' => (string) ($cert['provider'] ?? ''),
                ];
            }
        }

        return $out;
    }

    /**
     * Ask NPM to obtain a Let's Encrypt certificate. This is the only place a certificate is created, and callers
     * reach it only from an applied, previewed change. For the DNS challenge the provider settings of an existing
     * certificate are reused: they travel in this one request to NPM and are never stored or logged by LabMan.
     *
     * @param  list<string>  $domains
     * @return int the new certificate's id
     *
     * @throws NpmException
     */
    public function requestCertificate(array $domains, string $email, ?int $dnsTemplate = null): int
    {
        $meta = ['letsencrypt_email' => $email, 'letsencrypt_agree' => true];

        if ($dnsTemplate !== null) {
            $template = $this->authed('get', "/api/nginx/certificates/{$dnsTemplate}")->json()['meta'] ?? [];
            if (empty($template['dns_provider']) || empty($template['dns_provider_credentials'])) {
                throw new NpmException("Certificate #{$dnsTemplate} has no DNS provider settings that can be reused. Choose a Let's Encrypt DNS-challenge certificate made in NPM.");
            }
            $meta += ['dns_challenge' => true, 'dns_provider' => $template['dns_provider'], 'dns_provider_credentials' => $template['dns_provider_credentials']]
                + (isset($template['propagation_seconds']) ? ['propagation_seconds' => $template['propagation_seconds']] : []);
        }

        // Issuing takes a while (NPM waits for the challenge), so this one call may run much longer than the rest.
        $created = $this->authed('post', '/api/nginx/certificates', ['provider' => 'letsencrypt', 'domain_names' => array_values($domains), 'meta' => $meta], 180.0)->json();
        $id = (int) ($created['id'] ?? 0);
        if ($id < 1) {
            throw new NpmException('NPM did not return the new certificate.');
        }

        return $id;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function updateProxyHost(int $id, array $payload): array
    {
        return $this->authed('put', "/api/nginx/proxy-hosts/{$id}", $payload)->json() ?? [];
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function createProxyHost(array $payload): array
    {
        return $this->authed('post', '/api/nginx/proxy-hosts', $payload)->json() ?? [];
    }

    // --------------------------------------------------------------- internals

    private function login(): void
    {
        $response = $this->send('post', '/api/tokens', [
            'identity' => $this->connection['email'],
            'secret' => $this->connection['password'],
        ]);

        $body = $response->json() ?? [];

        if (! empty($body['requires_2fa'])) {
            throw new NpmException('This NPM account uses two-factor authentication, which LabMan cannot complete. Create a dedicated NPM user without 2FA for LabMan.');
        }

        if (empty($body['token']) || ! is_string($body['token'])) {
            throw new NpmException('NPM did not return a login token.');
        }

        $this->token = $body['token'];
    }

    /** @param array<string, mixed>|null $body */
    private function authed(string $method, string $path, ?array $body = null, ?float $timeout = null): Response
    {
        if ($this->token === null) {
            $this->login();
        }

        return $this->send($method, $path, $body, $this->token, $timeout);
    }

    /** @param array<string, mixed>|null $body */
    private function send(string $method, string $path, ?array $body = null, ?string $token = null, ?float $timeout = null): Response
    {
        $request = $this->request($token)->timeout($timeout ?? $this->timeout);

        try {
            $response = $body === null ? $request->{$method}($path) : $request->{$method}($path, $body);
        } catch (ConnectionException $e) {
            throw new NpmException('Cannot reach Nginx Proxy Manager at '.$this->connection['url'].': '.$this->describe($e->getMessage()));
        }

        if ($response->successful()) {
            return $response;
        }

        $message = $response->json('error.message') ?? $response->json('message');

        throw new NpmException(match (true) {
            // NPM answers a failed login with 400 ("Invalid email or password"), older versions with 401.
            $path === '/api/tokens' && in_array($response->status(), [400, 401], true) => 'NPM rejected the login'.($message ? " ({$message})" : ' (wrong e-mail or password, or the account is disabled)').'.',
            $response->status() === 401 => 'NPM rejected the login (wrong e-mail or password, or the account is disabled).',
            $response->status() === 403 => 'NPM refused: the account needs permission to manage proxy hosts.',
            $response->status() === 404 => 'NPM answered 404 for '.$path.'. Is this the NPM admin address (usually port 81)?',
            default => "NPM answered HTTP {$response->status()} for {$path}".($message ? ": {$message}" : '.'),
        });
    }

    private function request(?string $token): PendingRequest
    {
        $request = Http::baseUrl($this->connection['url'])
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout)
            ->connectTimeout($this->timeout)
            ->withoutRedirecting()
            ->withUserAgent('LabMan/1.0')
            ->withOptions(['verify' => $this->connection['verify_tls']]);

        return $token ? $request->withToken($token) : $request;
    }

    private function describe(string $message): string
    {
        $lower = strtolower($message);

        return match (true) {
            str_contains($lower, 'timed out') => 'timed out',
            str_contains($lower, 'resolve host') => 'could not resolve the host name',
            str_contains($lower, 'curl error 60'), str_contains($lower, 'ssl certificate') => 'certificate not trusted (untick "verify TLS" for a self-signed certificate)',
            str_contains($lower, 'refused'), str_contains($lower, 'curl error 7') => 'connection refused',
            default => mb_substr(trim(preg_replace('/\s*\(see https?:\/\/\S+\)/', '', $message) ?? $message), 0, 160),
        };
    }
}
