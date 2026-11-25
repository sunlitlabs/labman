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

    /** @return list<array{domain: string, answer: string, raw: string}> `domain` is lower-cased for matching; `raw` is the domain exactly as AdGuard holds it */
    public function rewrites(): array
    {
        $rows = $this->send('get', '/control/rewrite/list')->json();
        $out = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && is_string($row['domain'] ?? null) && is_string($row['answer'] ?? null)) {
                $out[] = ['domain' => mb_strtolower($row['domain']), 'answer' => $row['answer'], 'raw' => $row['domain']];
            }
        }

        return $out;
    }

    public function add(string $domain, string $answer): void
    {
        $this->send('post', '/control/rewrite/add', ['domain' => $domain, 'answer' => $answer]);
    }

    /**
     * Change an existing rewrite in place. AdGuard Home takes this as a PUT whose `target` is the rewrite exactly as it
     * holds it now and whose `update` is the new value. Versions without this endpoint are not worked around by deleting
     * and re-adding: LabMan has no delete, so the admin is told to edit that rewrite by hand.
     */
    public function change(string $targetDomain, string $targetAnswer, string $newDomain, string $newAnswer): void
    {
        try {
            $this->send('put', '/control/rewrite/update', [
                'target' => ['domain' => $targetDomain, 'answer' => $targetAnswer],
                'update' => ['domain' => $newDomain, 'answer' => $newAnswer],
            ]);
        } catch (AdguardException $e) {
            if ($e->status === 404) {
                throw new AdguardException("This AdGuard Home version has no rewrite update (PUT /control/rewrite/update answered 404), so {$targetDomain} was left as it is. LabMan never deletes rewrites: change {$targetDomain} to {$newAnswer} by hand in AdGuard Home (Filters > DNS rewrites), or upgrade AdGuard Home, then run the preview again.", 404);
            }

            throw $e;
        }
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

        $what = strtoupper($method).' '.$path;

        throw new AdguardException(match (true) {
            $response->status() === 401, $response->status() === 403 => 'AdGuard Home rejected the login (wrong user name or password).',
            $response->status() === 404 => "AdGuard Home answered 404 for {$what}. Is this the AdGuard Home address, and a version that supports it?",
            $response->status() === 405 => "AdGuard Home refused the request {$what} (HTTP 405".($detail !== '' ? ": {$detail}" : '').'). This LabMan and this AdGuard Home version disagree on how that call is made: upgrade LabMan, or make this change by hand in AdGuard Home.',
            default => "AdGuard Home answered HTTP {$response->status()} for {$what}".($detail !== '' ? ": {$detail}" : '.'),
        }, $response->status());
    }
}
