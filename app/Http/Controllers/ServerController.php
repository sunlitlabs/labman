<?php

namespace App\Http\Controllers;

use App\Http\Requests\ServerRequest;
use App\Models\Server;
use App\Monitoring\StatusView;
use Illuminate\Http\RedirectResponse;

class ServerController extends Controller
{
    public function index()
    {
        $servers = Server::query()->orderBy('group')->orderBy('name')->get();

        return view('servers.index', [
            'servers' => $servers,
            'grouped' => $servers->groupBy(fn (Server $s) => $s->group ?: ''),
            'summary' => StatusView::summary($servers),
        ]);
    }

    public function create()
    {
        return view('servers.form', ['server' => new Server(['ssh_port' => 22])]);
    }

    public function store(ServerRequest $request): RedirectResponse
    {
        Server::create($request->payload());

        return redirect()->route('servers.index')->with('status', 'Server added. Its health appears after the next check round.');
    }

    public function edit(Server $server)
    {
        return view('servers.form', ['server' => $server]);
    }

    public function update(ServerRequest $request, Server $server): RedirectResponse
    {
        $server->update($request->payload());

        return redirect()->route('servers.index')->with('status', 'Server updated.');
    }

    public function destroy(Server $server): RedirectResponse
    {
        $server->delete();

        return redirect()->route('servers.index')->with('status', 'Server deleted.');
    }
}
