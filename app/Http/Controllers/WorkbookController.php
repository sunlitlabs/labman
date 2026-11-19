<?php

namespace App\Http\Controllers;

use App\Semaphore\Connection;
use App\Semaphore\SemaphoreClient;
use App\Semaphore\SemaphoreException;
use App\Semaphore\Targets;
use Illuminate\Http\Request;

/** The workbooks (Semaphore templates): browse them, see recent runs, start one, optionally already aimed at chosen servers. */
class WorkbookController extends Controller
{
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

        // Servers picked on the server list: carried to the run form so they are already ticked.
        $hosts = array_values(array_filter(array_map('strval', (array) $request->query('hosts', []))));
        $groups = array_values(array_filter(array_map('strval', (array) $request->query('groups', []))));
        $known = collect(Targets::all()['hosts'])->keyBy('name');

        return view('workbooks.index', $data + [
            'carry' => ['hosts' => $hosts, 'groups' => $groups],
            'carryLabels' => [...array_map(fn ($h) => ($known[$h]['label'] ?? $h), $hosts), ...array_map(fn ($g) => 'group '.$g, $groups)],
        ]);
    }
}
