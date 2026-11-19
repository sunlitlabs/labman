<?php

namespace App\Semaphore;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * A small client for the Semaphore (Ansible Semaphore UI) REST API: list projects and templates,
 * start a template run, follow a task and its output, stop a task. It never creates or edits
 * Semaphore's own configuration (keys, inventories, templates).
 */
final class SemaphoreClient
{
    /** @param array{url: string, token: string, verify_tls: bool} $connection */
    public function __construct(private readonly array $connection, private readonly float $timeout = 15.0) {}

    /** @throws SemaphoreException when nothing is configured */
    public static function fromStored(): self
    {
        $connection = Connection::get();

        if ($connection === null) {
            throw new SemaphoreException('Semaphore is not connected. Save its address and API token first.');
        }

        return new self($connection);
    }

    /** Prove the address and token work. @return array{user: string, version: string|null} */
    public function test(): array
    {
        $this->send('get', '/api/ping');
        $user = $this->send('get', '/api/user')->json() ?? [];

        $version = null;
        try {
            $version = $this->send('get', '/api/info')->json('version');
        } catch (SemaphoreException) {
            // /api/info is optional.
        }

        return ['user' => (string) ($user['username'] ?? $user['name'] ?? 'unknown'), 'version' => is_string($version) ? $version : null];
    }

    /** @return list<array<string, mixed>> */
    public function projects(): array
    {
        return $this->list($this->send('get', '/api/projects')->json());
    }

    /** @return list<array<string, mixed>> */
    public function templates(int $project): array
    {
        return $this->list($this->send('get', "/api/project/{$project}/templates")->json());
    }

    /** The most recent tasks of a project. @return list<array<string, mixed>> */
    public function recentTasks(int $project): array
    {
        return array_slice($this->list($this->send('get', "/api/project/{$project}/tasks/last")->json()), 0, 15);
    }

    /** Start a run. @param array<string, mixed> $payload from RunOptions::payload() @return array<string, mixed> the new task */
    public function run(int $project, array $payload): array
    {
        return $this->send('post', "/api/project/{$project}/tasks", $payload)->json() ?? [];
    }

    /** @return array<string, mixed> */
    public function task(int $project, int $task): array
    {
        return $this->send('get', "/api/project/{$project}/tasks/{$task}")->json() ?? [];
    }

    /** All output lines so far. @return list<array{time: string|null, output: string}> */
    public function output(int $project, int $task): array
    {
        $rows = $this->list($this->send('get', "/api/project/{$project}/tasks/{$task}/output")->json());

        return array_map(fn ($r) => ['time' => isset($r['time']) ? (string) $r['time'] : null, 'output' => self::plain((string) ($r['output'] ?? ''))], $rows);
    }

    public function stop(int $project, int $task): void
    {
        $this->send('post', "/api/project/{$project}/tasks/{$task}/stop", new \stdClass);
    }

    /** Ansible colours its output with ANSI escape codes; show plain text (and no stray control characters). */
    public static function plain(string $line): string
    {
        $line = preg_replace('/\x1b\[[0-9;?]*[ -\/]*[@-~]/', '', $line) ?? $line;

        return preg_replace('/[\x00-\x08\x0b-\x1f\x7f]/', '', $line) ?? $line;
    }

    // ----------------------------------------------------------- internals

    /** @return list<array<string, mixed>> */
    private function list(mixed $json): array
    {
        return is_array($json) ? array_values(array_filter($json, 'is_array')) : [];
    }

    private function send(string $method, string $path, array|\stdClass|null $body = null): Response
    {
        $request = Http::baseUrl($this->connection['url'])
            ->acceptJson()
            ->asJson()
            ->withToken($this->connection['token'])
            ->timeout($this->timeout)
            ->connectTimeout($this->timeout)
            ->withoutRedirecting()
            ->withUserAgent('LabMan/1.0')
            ->withOptions(['verify' => $this->connection['verify_tls']]);

        try {
            $response = $body === null ? $request->{$method}($path) : $request->{$method}($path, $body);
        } catch (ConnectionException $e) {
            throw new SemaphoreException('Cannot reach Semaphore at '.$this->connection['url'].': '.$this->describe($e->getMessage()));
        }

        if ($response->successful()) {
            return $response;
        }

        $message = $response->json('error') ?? $response->json('message');
        if (! is_string($message)) {
            $message = trim(strip_tags(mb_substr($response->body(), 0, 200)));
        }

        throw new SemaphoreException(match (true) {
            in_array($response->status(), [401, 403], true) => 'Semaphore rejected the API token (it may be wrong, revoked, or lack access to this project).',
            $response->status() === 404 => "Semaphore answered 404 for {$path}. Check the address, and the project and template ids.",
            $response->redirect() => 'Semaphore redirected the request. Use its direct address (http(s)://host:port).',
            $response->status() === 400 && $message === '' && str_ends_with($path, '/tasks') => 'Semaphore refused the run (HTTP 400, no details). The usual cause is an extra variable the template does not declare as a survey variable, or a limit/option the template does not allow.',
            default => "Semaphore answered HTTP {$response->status()} for {$path}".($message !== '' ? ": {$message}" : '.'),
        });
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
