<?php

namespace App\Http\Controllers;

use App\Inventory\Slug;
use App\Models\Server;
use App\Semaphore\Connection;
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

    public function index(Request $request)
    {
        $data = ['connection' => Connection::summary(), 'projects' => [], 'projectId' => null, 'templates' => [], 'recent' => [], 'error' => null];

        if ($data['connection']) {
            try {
                $client = SemaphoreClient::fromStored();
                $data['projects'] = $client->projects();
                $data['projectId'] = (int) ($request->query('project') ?: ($data['projects'][0]['id'] ?? 0)) ?: null;

                if ($data['projectId']) {
                    $data['templates'] = $client->templates($data['projectId']);
                    $data['recent'] = $client->recentTasks($data['projectId']);
                }
            } catch (SemaphoreException $e) {
                $data['error'] = $e->getMessage();
            }
        }

        return view('semaphore.index', $data);
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
            return redirect()->route('semaphore.index')->withErrors(['semaphore' => $e->getMessage()]);
        }

        return view('semaphore.run', [
            'project' => $project, 'template' => $found, 'survey' => SurveyVars::declared($found), 'targets' => $this->targets(), 'old' => session()->getOldInput(),
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
            return redirect()->route('semaphore.index')->withErrors(['semaphore' => $e->getMessage()]);
        }

        if (empty($task['id'])) {
            return redirect()->route('semaphore.index')->withErrors(['semaphore' => 'Semaphore did not return a task id.']);
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
            return redirect()->route('semaphore.index')->withErrors(['semaphore' => $e->getMessage()]);
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

    /** Names LabMan knows, for the limit picker. @return array{groups: list<string>, hosts: list<array{name: string, label: string}>} */
    private function targets(): array
    {
        $servers = Server::orderBy('name')->get();

        return [
            'groups' => $servers->pluck('group')->filter()->map(fn ($g) => Slug::group($g))->unique()->sort()->values()->all(),
            'hosts' => $servers->map(fn (Server $s) => ['name' => $s->inventory_host ?: Slug::host($s->name), 'label' => $s->name])->all(),
        ];
    }

    private function versionLabel(?string $version): string
    {
        return $version ? " {$version}" : '';
    }
}
