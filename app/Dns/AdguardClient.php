<?php

namespace App\Dns;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * A minimal client for the AdGuard Home REST API, limited to DNS rewrites: list, add, change. It has no way to
 * delete one, on purpose: LabMan never removes DNS records.
 */
final class AdguardClient
{
    /** @param array{url: string, username: string, password: string, verify_tls: bool} $connection */
    public function __construct(private readonly array $connection, private readonly float $timeout = 10.0) {}

    /** @throws AdguardException */
    public static function fromStored(): self
    {
        $connection = AdguardConnection::get();

        if ($connection === null) {
            throw new AdguardException('AdGuard Home is not connected. Save its address and login first.');
        }

        return new self($connection);
    }

    /** The AdGuard Home version, proving the address and login work. */
    public function test(): string
    {
        $status = $this->send('get', '/control/status')->json();

        return is_array($status) && is_string($status['version'] ?? null) ? $status['version'] : 'unknown';
    }

    /** @return list<array{domain: string, answer: string}> */
    public function rewrites(): array
    {
        $rows = $this->send('get', '/control/rewrite/list')->json();
        $out = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && is_string($row['domain'] ?? null) && is_string($row['answer'] ?? null)) {
                $out[] = ['domain' => mb_strtolower($row['domain']), 'answer' => $row['answer']];
            }
        }

        return $out;
    }

    public function add(string $domain, string $answer): void
    {
        $this->send('post', '/control/rewrite/add', ['domain' => $domain, 'answer' => $answer]);
    }

    /** Change the answer of an existing rewrite (needs AdGuard Home 0.107.30 or newer). */
    public function change(string $domain, string $oldAnswer, string $newAnswer): void
    {
        $this->send('post', '/control/rewrite/update', [
            'target' => ['domain' => $domain, 'answer' => $oldAnswer],
            'update' => ['domain' => $domain, 'answer' => $newAnswer],
        ]);
    }

    /** @param array<string, mixed>|null $body */
    private function send(string $method, string $path, ?array $body = null): Response
    {
        $request = Http::baseUrl($this->connection['url'])
            ->acceptJson()->asJson()
            ->timeout($this->timeout)->connectTimeout($this->timeout)
            ->withoutRedirecting()
            ->withUserAgent('LabMan/1.0')
            ->withOptions(['verify' => $this->connection['verify_tls']]);

        if ($this->connection['username'] !== '' || $this->connection['password'] !== '') {
            $request = $request->withBasicAuth($this->connection['username'], $this->connection['password']);
        }

        try {
            $response = $body === null ? $request->{$method}($path) : $request->{$method}($path, $body);
        } catch (ConnectionException $e) {
            throw new AdguardException('Cannot reach AdGuard Home at '.$this->connection['url'].': '.mb_substr(strtok($e->getMessage(), "\n") ?: 'no answer', 0, 200));
        }

        if ($response->successful()) {
            return $response;
        }

        $detail = trim(mb_substr((string) $response->body(), 0, 200));

        throw new AdguardException(match (true) {
            $response->status() === 401, $response->status() === 403 => 'AdGuard Home rejected the login (wrong user name or password).',
            $response->status() === 404 => 'AdGuard Home answered 404 for '.$path.'. Is this the AdGuard Home address, and a version that supports it?',
            default => "AdGuard Home answered HTTP {$response->status()} for {$path}".($detail !== '' ? ": {$detail}" : '.'),
        });
    }
}
