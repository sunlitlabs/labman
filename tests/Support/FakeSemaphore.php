<?php

namespace Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/** An in-memory stand-in for the Semaphore API (the endpoints LabMan uses). */
final class FakeSemaphore
{
    public const URL = 'http://semaphore.test:3000';

    public string $token = 'sem-token';

    /** @var list<array<string, mixed>> */
    public array $templates = [];

    /** @var array<int, array<string, mixed>> */
    public array $tasks = [];

    /** @var array<int, list<string>> */
    public array $output = [];

    /** @var list<array{method: string, path: string, body: array}> */
    public array $requests = [];

    private int $nextTask = 100;

    public static function install(): self
    {
        $fake = new self;
        $fake->templates = [
            ['id' => 7, 'name' => 'Update packages', 'playbook' => 'playbooks/update.yml', 'description' => 'apt upgrade', 'type' => '', 'last_task' => ['id' => 55, 'status' => 'success'],
                'survey_vars' => [
                    ['name' => 'version', 'title' => 'App version', 'description' => 'Version to report', 'type' => '', 'required' => false],
                    ['name' => 'count', 'title' => 'How many', 'type' => 'int', 'required' => false],
                    ['name' => 'color', 'title' => 'Colour', 'type' => 'enum', 'required' => false, 'values' => [['name' => 'Red', 'value' => 'red'], ['name' => 'Blue', 'value' => 'blue']]],
                    ['name' => 'api_key', 'title' => 'API key', 'type' => 'secret', 'required' => false],
                ]],
            ['id' => 8, 'name' => 'Deploy app', 'playbook' => 'playbooks/deploy.yml', 'description' => '', 'type' => 'deploy'],
        ];
        Http::fake(fn (Request $request) => $fake->handle($request));

        return $fake;
    }

    /** @return list<array{method: string, path: string, body: array}> */
    public function posts(): array
    {
        return array_values(array_filter($this->requests, fn ($r) => $r['method'] === 'POST'));
    }

    public function handle(Request $request)
    {
        if (! str_starts_with($request->url(), self::URL)) {
            return Http::response('not semaphore', 404);
        }

        $path = parse_url($request->url(), PHP_URL_PATH);
        $method = $request->method();
        $body = $request->data();
        $this->requests[] = ['method' => $method, 'path' => $path, 'body' => $body];

        if ($path === '/api/ping') {
            return Http::response('pong', 200, ['Content-Type' => 'text/plain']);
        }

        if (($request->header('Authorization')[0] ?? '') !== 'Bearer '.$this->token) {
            return Http::response('Unauthorized', 401);
        }

        return match (true) {
            $path === '/api/user' => Http::response(['id' => 1, 'name' => 'LabMan Bot', 'username' => 'labman']),
            $path === '/api/info' => Http::response(['version' => 'v2.12.0']),
            $path === '/api/projects' => Http::response([['id' => 1, 'name' => 'Home Lab'], ['id' => 2, 'name' => 'Other']]),
            (bool) preg_match('#^/api/project/(\d+)/#', $path, $m) && (int) $m[1] !== 1 && (int) $m[1] !== 2 => Http::response(['error' => 'not found'], 404),
            $path === '/api/project/1/templates' => Http::response($this->templates),
            $path === '/api/project/1/tasks/last' => Http::response(array_values(array_reverse($this->tasks))),
            $path === '/api/project/1/tasks' && $method === 'POST' => $this->start($body),
            (bool) preg_match('#^/api/project/1/tasks/(\d+)$#', $path, $m) => $this->task((int) $m[1]),
            (bool) preg_match('#^/api/project/1/tasks/(\d+)/output$#', $path, $m) => Http::response(array_map(
                fn ($line) => ['task_id' => (int) $m[1], 'time' => '2026-01-01T00:00:00Z', 'output' => $line], $this->output[(int) $m[1]] ?? [],
            )),
            (bool) preg_match('#^/api/project/1/tasks/(\d+)/stop$#', $path, $m) && $method === 'POST' => $this->stop((int) $m[1]),
            default => Http::response(['error' => 'not found'], 404),
        };
    }

    /** @param array<string, mixed> $body */
    private function start(array $body)
    {
        if (empty($body['template_id'])) {
            return Http::response(['error' => 'template_id is required'], 400);
        }

        // Like the real Semaphore: extra variables must be declared by the template as survey variables, else a bare 400.
        $template = collect($this->templates)->firstWhere('id', $body['template_id']);
        $vars = json_decode((string) ($body['environment'] ?? ''), true) ?: [];
        $declared = array_column($template['survey_vars'] ?? [], 'name');
        if ($vars && array_diff(array_keys($vars), $declared)) {
            return Http::response('', 400);
        }

        $id = $this->nextTask++;
        $this->tasks[$id] = [
            'id' => $id, 'template_id' => $body['template_id'], 'status' => 'waiting', 'created' => '2026-01-01T00:00:00Z',
            'limit' => $body['limit'] ?? '', 'dry_run' => $body['dry_run'] ?? false, 'environment' => $body['environment'] ?? '',
            'message' => $body['message'] ?? '', 'tpl_alias' => collect($this->templates)->firstWhere('id', $body['template_id'])['name'] ?? '',
        ];
        $this->output[$id] = [];

        return Http::response($this->tasks[$id], 201);
    }

    private function task(int $id)
    {
        return isset($this->tasks[$id]) ? Http::response($this->tasks[$id]) : Http::response(['error' => 'not found'], 404);
    }

    private function stop(int $id)
    {
        if (! isset($this->tasks[$id])) {
            return Http::response(['error' => 'not found'], 404);
        }
        $this->tasks[$id]['status'] = 'stopped';

        return Http::response('', 204);
    }
}
