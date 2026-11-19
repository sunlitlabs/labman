<?php

namespace App\Mcp\Tools;

use App\Mcp\Tool;
use App\Mcp\ToolException;
use App\Semaphore\Connection;
use App\Semaphore\RunOptions;
use App\Semaphore\SemaphoreClient;
use App\Semaphore\SemaphoreException;
use App\Semaphore\SurveyVars;
use Closure;

/**
 * Semaphore for MCP clients: browse projects and templates (playbooks), run a template, follow the
 * task, stop it. Running anything needs `confirm: true`, which an assistant must only set after the
 * user has seen the template, hosts and mode.
 */
final class SemaphoreTools
{
    private const FINISHED = ['success', 'error', 'stopped'];

    /** @return list<Tool> */
    public static function all(): array
    {
        $ids = ['project_id' => Tool::int('Semaphore project id (see semaphore_list_projects).')];

        return [
            new Tool('semaphore_get_connection', 'Show the Semaphore connection', 'Whether LabMan is connected to Semaphore and at which address. The token is never shown.',
                [], [], 'admin', true, false, fn () => ['connected' => Connection::exists(), 'connection' => Connection::summary()]),

            new Tool('semaphore_set_connection', 'Connect to Semaphore', 'Test and save the Semaphore address and API token (stored encrypted). The token passes through this conversation; entering it in the LabMan UI avoids that. The token may be omitted when only changing settings for the same address.',
                [
                    'url' => Tool::str('Semaphore address, e.g. http://192.0.2.7:3000'),
                    'token' => Tool::str('A Semaphore API token (the token acts as its user, so use a dedicated user).'),
                    'verify_tls' => Tool::bool('Verify the TLS certificate when the address is https (default true).'),
                ], ['url'], 'admin', false, false,
                fn (array $a) => self::guard(fn () => ['connected' => true] + Connection::connect((string) $a['url'], $a['token'] ?? null, (bool) ($a['verify_tls'] ?? true)) + ['connection' => Connection::summary()])),

            new Tool('semaphore_remove_connection', 'Disconnect from Semaphore', 'Delete the saved Semaphore address and token from LabMan. Nothing in Semaphore changes.',
                [], [], 'admin', false, true, function () {
                    Connection::forget();

                    return ['connected' => false];
                }),

            new Tool('semaphore_list_projects', 'List Semaphore projects', 'The projects the token can see.',
                [], [], 'admin', true, false, fn () => self::guard(fn () => ['projects' => array_map(
                    fn ($p) => ['id' => $p['id'] ?? null, 'name' => $p['name'] ?? null],
                    SemaphoreClient::fromStored()->projects(),
                )])),

            new Tool('semaphore_list_templates', 'List Semaphore templates', 'The templates (playbooks) of a project with their last run status.',
                $ids, ['project_id'], 'admin', true, false, fn (array $a) => self::guard(fn () => ['templates' => array_map(fn ($t) => [
                    'id' => $t['id'] ?? null, 'name' => $t['name'] ?? null, 'playbook' => $t['playbook'] ?? null,
                    'description' => $t['description'] ?? null, 'type' => $t['type'] ?? null,
                    'variables' => array_values(array_map(fn ($v) => ['name' => $v['name'], 'title' => $v['title'], 'type' => $v['type'], 'required' => $v['required'], 'choices' => array_column($v['values'], 'value')], SurveyVars::declared($t))),
                    'last_task' => isset($t['last_task']) ? ['id' => $t['last_task']['id'] ?? null, 'status' => $t['last_task']['status'] ?? null] : null,
                ], SemaphoreClient::fromStored()->templates((int) $a['project_id']))])),

            new Tool('semaphore_run_template', 'Run a Semaphore template', 'STARTS A PLAYBOOK RUN on real hosts. Before calling this, tell the user which template, which hosts (limit) and whether it is a dry run, and get their approval; only then pass confirm=true. Prefer a limit and dry_run=true first. Returns the task id; follow it with semaphore_get_task.',
                $ids + [
                    'template_id' => Tool::int('Template id (see semaphore_list_templates).'),
                    'limit' => Tool::str('Comma separated hosts/groups to limit the run to. Empty means every host the template targets.'),
                    'dry_run' => Tool::bool('Check mode: show what would change without changing it.'),
                    'diff' => Tool::bool('Show diffs of changed files.'),
                    'extra_vars' => ['type' => 'object', 'description' => 'Extra variables (name: value). Semaphore only accepts the variables the template declares: see "variables" in semaphore_list_templates.'],
                    'message' => Tool::str('One-line note for the run log.'),
                    'confirm' => Tool::bool('Must be true. Set it only after the user approved this exact run.'),
                ], ['project_id', 'template_id', 'confirm'], 'admin', false, true,
                function (array $a) {
                    if (($a['confirm'] ?? false) !== true) {
                        throw new ToolException('Not started: set confirm=true only after the user has approved this exact run (template, hosts and mode).');
                    }

                    return self::guard(function () use ($a) {
                        $options = RunOptions::validate($a);
                        $template = collect(SemaphoreClient::fromStored()->templates($options['project_id']))->firstWhere('id', $options['template_id'])
                            ?? throw new SemaphoreException("Template {$options['template_id']} was not found in project {$options['project_id']}.");
                        $options = SurveyVars::check($options, $template);
                        $task = SemaphoreClient::fromStored()->run($options['project_id'], RunOptions::payload($options));

                        return ['started' => true, 'task_id' => $task['id'] ?? null, 'status' => $task['status'] ?? null, 'summary' => RunOptions::describe($options)];
                    });
                }),

            new Tool('semaphore_get_task', 'Get a Semaphore run', 'Status of a run and the last lines of its output. Poll until finished is true.',
                $ids + ['task_id' => Tool::int('Task id.'), 'tail' => Tool::int('How many output lines to return from the end (default 50, max 500).')],
                ['project_id', 'task_id'], 'admin', true, false,
                fn (array $a) => self::guard(function () use ($a) {
                    $client = SemaphoreClient::fromStored();
                    $task = $client->task((int) $a['project_id'], (int) $a['task_id']);
                    $lines = array_column($client->output((int) $a['project_id'], (int) $a['task_id']), 'output');
                    $tail = max(1, min(500, (int) ($a['tail'] ?? 50)));

                    return [
                        'task_id' => $task['id'] ?? $a['task_id'], 'status' => $task['status'] ?? 'unknown',
                        'finished' => in_array($task['status'] ?? '', self::FINISHED, true),
                        'template_id' => $task['template_id'] ?? null, 'limit' => $task['limit'] ?? null, 'dry_run' => $task['dry_run'] ?? null,
                        'started' => $task['start'] ?? null, 'ended' => $task['end'] ?? null,
                        'output_lines_total' => count($lines), 'output_tail' => array_slice($lines, -$tail),
                    ];
                })),

            new Tool('semaphore_list_tasks', 'List recent Semaphore runs', 'The most recent runs of a project.',
                $ids, ['project_id'], 'admin', true, false, fn (array $a) => self::guard(fn () => ['tasks' => array_map(fn ($t) => [
                    'id' => $t['id'] ?? null, 'status' => $t['status'] ?? null, 'template' => $t['tpl_alias'] ?? ($t['template_id'] ?? null),
                    'limit' => $t['limit'] ?? null, 'message' => $t['message'] ?? null, 'created' => $t['created'] ?? null,
                ], SemaphoreClient::fromStored()->recentTasks((int) $a['project_id']))])),

            new Tool('semaphore_stop_task', 'Stop a Semaphore run', 'Ask Semaphore to stop a running task.',
                $ids + ['task_id' => Tool::int('Task id.')], ['project_id', 'task_id'], 'admin', false, true,
                fn (array $a) => self::guard(function () use ($a) {
                    SemaphoreClient::fromStored()->stop((int) $a['project_id'], (int) $a['task_id']);

                    return ['stop_requested' => true];
                })),
        ];
    }

    /** @param Closure(): array<string, mixed> $run */
    private static function guard(Closure $run): array
    {
        try {
            return $run();
        } catch (SemaphoreException $e) {
            throw new ToolException($e->getMessage());
        }
    }
}
