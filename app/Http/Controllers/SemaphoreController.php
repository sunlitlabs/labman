<?php

namespace App\Http\Controllers;

use App\Semaphore\Connection;
use App\Semaphore\HostSync;
use App\Semaphore\Targets;
use App\Semaphore\RunOptions;
use App\Semaphore\SemaphoreClient;
use App\Semaphore\SemaphoreException;
use App\Semaphore\SurveyVars;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Admin-only Semaphore screen: connect, browse templates, run one with a confirmation step, follow the task. */
class SemaphoreController extends Controller
{
    private const FINISHED = ['success', 'error', 'stopped'];

    public function index()
    {
        $data = ['connection' => Connection::summary(), 'choices' => [], 'target' => HostSync::target(), 'error' => null];

        if ($data['connection']) {
            try {
                $data['choices'] = HostSync::choices(SemaphoreClient::fromStored());
            } catch (SemaphoreException $e) {
                $data['error'] = $e->getMessage();
            }
        }

        return view('semaphore.index', $data + [
            'last' => HostSync::lastSync(), 'known' => HostSync::known(), 'strangers' => HostSync::strangers(),
            'servers' => \App\Models\Server::count(),
        ]);
    }

    /** Choose the Semaphore inventory to keep up to date, and whether that happens on every server change. */
    public function inventorySave(Request $request): RedirectResponse
    {
        $data = $request->validate(['inventory' => ['required', 'regex:/^\d+:\d+$/']]);
        [$project, $id] = array_map('intval', explode(':', $data['inventory']));

        try {
            HostSync::load(SemaphoreClient::fromStored(), $project, $id); // refuses anything that is not a readable static inventory
        } catch (SemaphoreException $e) {
            throw ValidationException::withMessages(['inventory' => $e->getMessage()]);
        }

        HostSync::saveTarget($project, $id, $request->boolean('auto'));
        HostSync::watch(true);

        return redirect()->route('semaphore.index')->with('status', 'Saved. LabMan read the host list'.($request->boolean('auto') ? ' and will keep it up to date whenever your servers change.' : '. Use "Update Semaphore now" to write your servers into it.'));
    }

    public function inventoryClear(): RedirectResponse
    {
        HostSync::forgetTarget();

        return redirect()->route('semaphore.index')->with('status', 'LabMan no longer reads or updates a Semaphore inventory.');
    }

    /** Write the servers into the chosen inventory now (also the way to do it when automatic updates are off). */
    public function inventorySync(): RedirectResponse
    {
        try {
            $result = HostSync::sync();
        } catch (SemaphoreException $e) {
            return redirect()->route('semaphore.index')->withErrors(['semaphore' => $e->getMessage()]);
        }

        $parts = array_filter([$result['added'] ? $result['added'].' host(s) added' : null, $result['updated'] ? $result['updated'].' updated' : null, $result['removed'] ? $result['removed'].' removed' : null]);

        return redirect()->route('semaphore.index')->with('status', $parts ? 'Semaphore updated: '.implode(', ', $parts).'.' : 'Semaphore already matches your servers; nothing to change.');
    }

    /** Hosts in Semaphore that LabMan does not have, with a choice per host. */
    public function hosts()
    {
        $strangers = HostSync::strangers();
        $names = \App\Models\Server::pluck('name')->map(fn ($n) => mb_strtolower($n))->all();

        $rows = [];
        foreach ($strangers as $name => $info) {
            $address = \App\Inventory\HostMatcher::address($name, $info['vars']) ?? $name;
            $rows[] = ['host' => $name, 'address' => $address, 'group' => $info['groups'][0] ?? null, 'clash' => in_array(mb_strtolower($name), $names, true)];
        }

        return view('semaphore.hosts', ['rows' => $rows, 'known' => HostSync::known()]);
    }

    public function hostsApply(Request $request): RedirectResponse
    {
        $data = $request->validate(['choice' => ['array'], 'choice.*' => ['in:add,ignore,leave'], 'name' => ['array'], 'name.*' => ['nullable', 'string', 'max:100']]);
        $strangers = HostSync::strangers();
        $added = 0;
        $ignored = [];
        $problems = [];

        foreach ($data['choice'] ?? [] as $host => $choice) {
            if (! isset($strangers[$host])) {
                continue;
            }

            if ($choice === 'ignore') {
                $ignored[] = $host;
            } elseif ($choice === 'add') {
                $info = $strangers[$host];
                try {
                    $payload = \App\Domain\ServerInput::validate([
                        'name' => trim((string) ($data['name'][$host] ?? '')) ?: $host,
                        'address' => \App\Inventory\HostMatcher::address($host, $info['vars']) ?? $host,
                        'group' => $info['groups'][0] ?? null, 'ssh_port' => 22,
                    ]);
                    \App\Models\Server::create($payload + ['inventory_host' => $host]);
                    $added++;
                } catch (ValidationException $e) {
                    $problems[] = "{$host}: ".implode(' ', $e->validator->errors()->all());
                }
            }
        }

        if ($ignored) {
            HostSync::ignore($ignored);
        }
        HostSync::settleNotice();

        $back = redirect()->route($problems ? 'semaphore.hosts' : 'semaphore.index')->with('status', "{$added} server(s) added to LabMan".($ignored ? ', '.count($ignored).' ignored' : '').'.');

        return $problems ? $back->withErrors(['semaphore' => implode(' ', $problems)]) : $back;
    }
    public function connect(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'url' => ['required', 'string', 'max:255'],
            'token' => ['nullable', 'string', 'max:500'],
            'verify_tls' => ['sometimes', 'boolean'],
        ]);

        try {
            $info = Connection::connect($data['url'], $data['token'] ?? null, $request->boolean('verify_tls'));
        } catch (SemaphoreException $e) {
            throw ValidationException::withMessages(['url' => $e->getMessage()]);
        }
        return redirect()->route('semaphore.index')->with('status', "Connected to Semaphore{$this->versionLabel($info['version'])} as {$info['user']}.");
    }

    public function disconnect(): RedirectResponse
    {
        Connection::forget();

        return redirect()->route('semaphore.index')->with('status', 'Disconnected. The saved Semaphore token was deleted.');
    }

    /** Step 1: choose how to run a template. */
    public function runForm(Request $request)
    {
        $project = (int) $request->query('project');
        $template = (int) $request->query('template');

        try {
            $found = $this->findTemplate($project, $template);
        } catch (SemaphoreException $e) {
            return redirect()->route('workbooks.index')->withErrors(['semaphore' => $e->getMessage()]);
        }

        return view('semaphore.run', [
            'project' => $project, 'template' => $found, 'survey' => SurveyVars::declared($found), 'targets' => Targets::all(), 'old' => session()->getOldInput() + ['hosts' => (array) $request->query('hosts', []), 'groups' => (array) $request->query('groups', []), 'message' => (string) $request->query('message', '')],
        ]);
    }

    /** Step 2: show exactly what will run and ask for confirmation. */
    public function runConfirm(Request $request)
    {
        $project = (int) $request->input('project_id');
        $template = (int) $request->input('template_id');

        try {
            $found = $this->findTemplate($project, $template);
            $options = SurveyVars::check(RunOptions::validate($this->optionsFrom($request)), $found);
        } catch (SemaphoreException $e) {
            return redirect()->route('semaphore.run', ['project' => $project, 'template' => $template])
                ->withErrors(['semaphore' => $e->getMessage()])->withInput($request->except('_token'));
        }

        return view('semaphore.confirm', [
            'options' => $options, 'template' => $found, 'summary' => RunOptions::describe($options),
            'hostCount' => count(array_filter(explode(',', $options['limit']))),
        ]);
    }

    /** Step 3: start the run. */
    public function runStart(Request $request): RedirectResponse
    {
        $request->validate(['confirm' => ['accepted']], ['confirm.accepted' => 'Confirm the run to start it.']);

        try {
            $options = RunOptions::validate($this->optionsFrom($request));
            $options = SurveyVars::check($options, $this->findTemplate($options['project_id'], $options['template_id']));
            $task = SemaphoreClient::fromStored()->run($options['project_id'], RunOptions::payload($options));
        } catch (SemaphoreException $e) {
            return redirect()->route('workbooks.index')->withErrors(['semaphore' => $e->getMessage()]);
        }

        if (empty($task['id'])) {
            return redirect()->route('workbooks.index')->withErrors(['semaphore' => 'Semaphore did not return a task id.']);
        }

        return redirect()->route('semaphore.task', ['project' => $options['project_id'], 'task' => (int) $task['id']])
            ->with('status', 'Run started.');
    }

    public function task(int $project, int $task)
    {
        try {
            $client = SemaphoreClient::fromStored();
            $info = $client->task($project, $task);
            $output = $client->output($project, $task);
        } catch (SemaphoreException $e) {
            return redirect()->route('workbooks.index')->withErrors(['semaphore' => $e->getMessage()]);
        }

        return view('semaphore.task', [
            'project' => $project, 'taskId' => $task, 'task' => $info, 'output' => $output,
            'finished' => in_array($info['status'] ?? '', self::FINISHED, true),
        ]);
    }

    /** Polled by the task page: the status and any output lines after `since`. */
    public function taskStatus(Request $request, int $project, int $task): JsonResponse
    {
        try {
            $client = SemaphoreClient::fromStored();
            $info = $client->task($project, $task);
            $output = $client->output($project, $task);
        } catch (SemaphoreException $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }

        $since = max(0, (int) $request->query('since', 0));

        return response()->json([
            'status' => $info['status'] ?? 'unknown',
            'finished' => in_array($info['status'] ?? '', self::FINISHED, true),
            'total' => count($output),
            'lines' => array_map(fn ($l) => $l['output'], array_slice($output, $since)),
        ])->header('Cache-Control', 'no-store');
    }

    public function taskStop(int $project, int $task): RedirectResponse
    {
        try {
            SemaphoreClient::fromStored()->stop($project, $task);
        } catch (SemaphoreException $e) {
            return redirect()->route('semaphore.task', [$project, $task])->withErrors(['semaphore' => $e->getMessage()]);
        }

        return redirect()->route('semaphore.task', [$project, $task])->with('status', 'Stop requested.');
    }

    // ------------------------------------------------------------- helpers

    /** @return array<string, mixed> */
    private function findTemplate(int $project, int $template): array
    {
        foreach (SemaphoreClient::fromStored()->templates($project) as $t) {
            if ((int) ($t['id'] ?? 0) === $template) {
                return $t;
            }
        }

        throw new SemaphoreException("Template {$template} was not found in project {$project}.");
    }

    /** Combine the tick-boxes and the free-text limit into RunOptions input. @return array<string, mixed> */
    private function optionsFrom(Request $request): array
    {
        $limit = $request->has('limit')
            ? (string) $request->input('limit')
            : implode(',', array_filter([...(array) $request->input('groups', []), ...(array) $request->input('hosts', []), (string) $request->input('limit_extra', '')]));

        return [
            'project_id' => $request->input('project_id'),
            'template_id' => $request->input('template_id'),
            'limit' => $limit,
            'dry_run' => $request->boolean('dry_run'),
            'diff' => $request->boolean('diff'),
            'extra_vars' => $request->input('extra_vars'),
            'survey' => (array) $request->input('survey', []),
            'message' => $request->input('message', ''),
        ];
    }

    private function versionLabel(?string $version): string
    {
        return $version ? " {$version}" : '';
    }
}
