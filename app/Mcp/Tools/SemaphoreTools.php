<?php

namespace App\Mcp\Tools;

use App\Mcp\Tool;
use App\Mcp\ToolException;
use App\Domain\ServerInput;
use App\Inventory\HostMatcher;
use App\Models\Server;
use App\Semaphore\Connection;
use App\Semaphore\HostSync;
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

            new Tool('semaphore_get_host_sync', 'Show the Semaphore host list setting', 'Which Semaphore inventory (the host list) LabMan reads and updates, whether it is kept up to date automatically when servers change, and the result of the last update.',
                [], [], 'admin', true, false, fn () => ['target' => HostSync::target(), 'automatic' => HostSync::auto(), 'last_sync' => HostSync::lastSync()]),

            new Tool('semaphore_set_host_sync', 'Choose the Semaphore host list', 'Choose the static Semaphore inventory that LabMan reads and updates, and whether LabMan writes the server list into it automatically whenever servers change (ask the user before turning that on). LabMan first reads it and refuses anything that is not a readable static inventory.',
                $ids + ['inventory_id' => Tool::int('Semaphore inventory id.'), 'automatic' => Tool::bool('Update the inventory whenever servers change (default false).')], ['project_id', 'inventory_id'], 'admin', false, false,
                fn (array $a) => self::guard(function () use ($a) {
                    HostSync::load(SemaphoreClient::fromStored(), (int) $a['project_id'], (int) $a['inventory_id']);
                    HostSync::saveTarget((int) $a['project_id'], (int) $a['inventory_id'], (bool) ($a['automatic'] ?? false));
                    HostSync::watch(true);

                    return ['target' => HostSync::target(), 'automatic' => HostSync::auto()];
                })),

            new Tool('semaphore_clear_host_sync', 'Stop using a Semaphore host list', 'LabMan no longer reads or updates a Semaphore inventory. Nothing in Semaphore changes.',
                [], [], 'admin', false, true, function () {
                    HostSync::forgetTarget();

                    return ['target' => null];
                }),

            new Tool('semaphore_sync_hosts', 'Write the servers into the Semaphore host list', 'WRITES to Semaphore: adds and updates the hosts of the chosen inventory to match LabMan\'s servers (this is also the way to do it when automatic updates are off). It does not remove hosts.',
                [], [], 'admin', false, false, fn () => self::guard(fn () => HostSync::sync())),

            new Tool('semaphore_list_unknown_hosts', 'List Semaphore hosts LabMan does not have', 'Read-only. Hosts in the Semaphore inventory that no LabMan server matches (and nobody ignored), with the address LabMan would use. Use semaphore_adopt_hosts to add or ignore them.',
                [], [], 'admin', true, false, function () {
                    $names = Server::query()->pluck('name')->map(fn ($n) => mb_strtolower($n))->all();
                    $hosts = [];
                    foreach (HostSync::strangers() as $name => $info) {
                        $hosts[] = ['host' => $name, 'address' => HostMatcher::address($name, $info['vars']) ?? $name, 'group' => $info['groups'][0] ?? null, 'name_clash' => in_array(mb_strtolower($name), $names, true)];
                    }

                    return ['hosts' => $hosts];
                }),

            new Tool('semaphore_adopt_hosts', 'Add or ignore Semaphore hosts', 'For hosts from semaphore_list_unknown_hosts: add them to LabMan as servers (optionally under another name) or ignore them so they are not offered again. Nothing is written to Semaphore.',
                [
                    'add' => ['type' => 'array', 'description' => 'Hosts to add as servers.', 'items' => ['type' => 'object', 'properties' => ['host' => ['type' => 'string'], 'name' => ['type' => 'string', 'description' => 'Server name (default: the host name).']], 'required' => ['host'], 'additionalProperties' => false]],
                    'ignore' => Tool::strs('Host names to ignore.'),
                ], [], 'admin', false, false, function (array $a) {
                    $strangers = HostSync::strangers();
                    $added = [];
                    $problems = [];

                    foreach ($a['add'] ?? [] as $item) {
                        $host = (string) ($item['host'] ?? '');
                        if (! isset($strangers[$host])) {
                            $problems[] = "{$host}: not an unknown host (see semaphore_list_unknown_hosts)";

                            continue;
                        }
                        try {
                            $info = $strangers[$host];
                            $payload = ServerInput::validate([
                                'name' => trim((string) ($item['name'] ?? '')) ?: $host,
                                'address' => HostMatcher::address($host, $info['vars']) ?? $host,
                                'group' => $info['groups'][0] ?? null, 'ssh_port' => 22,
                            ]);
                            Server::create($payload + ['inventory_host' => $host]);
                            $added[] = $host;
                        } catch (\Illuminate\Validation\ValidationException $e) {
                            $problems[] = "{$host}: ".implode(' ', $e->validator->errors()->all());
                        }
                    }

                    $ignore = array_values(array_filter(array_map('strval', $a['ignore'] ?? []), fn ($h) => isset($strangers[$h])));
                    if ($ignore) {
                        HostSync::ignore($ignore);
                    }
                    HostSync::settleNotice();

                    return ['added' => $added, 'ignored' => $ignore, 'problems' => $problems];
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
