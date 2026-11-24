@extends('layouts.app')

@section('title', $server->exists ? 'Edit server' : 'Add server')

@section('content')
    <div class="wide">
        <h1>{{ $server->exists ? 'Edit '.$server->name : 'Add server' }}</h1>

        <form method="POST" action="{{ $server->exists ? route('servers.update', $server) : route('servers.store') }}">
            @csrf
            @if ($server->exists) @method('PUT') @endif

            <section class="formcard">
                <h2>The server</h2>
                <p class="sub">What it is called and where LabMan finds it.</p>
                <div class="grid2">
                    <div class="field">
                        <label for="name">Name</label>
                        <input id="name" name="name" type="text" required maxlength="100" value="{{ old('name', $server->name) }}">
                    </div>
                    <div class="field">
                        <label for="group">Group <span class="hint">optional heading</span></label>
                        <input id="group" name="group" type="text" maxlength="60" value="{{ old('group', $server->group) }}">
                    </div>
                    <div class="field">
                        <label for="address">Address <span class="hint">IP or host name that is checked</span></label>
                        <input id="address" name="address" type="text" required maxlength="253" placeholder="192.0.2.10" value="{{ old('address', $server->address) }}">
                    </div>
                    @if (\App\Support\Zones::multiple())
                        <div class="field">
                            <label for="zone_id">Zone <span class="hint">its trust level</span></label>
                            <select id="zone_id" name="zone_id">
                                @foreach (\App\Support\Zones::all() as $z)
                                    <option value="{{ $z->id }}" @selected((string) old('zone_id', $server->zone_id ?? \App\Support\Zones::defaultId()) === (string) $z->id)>{{ $z->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                    @php($names = \App\Support\Domains::namesOf($server))
                    @php($localDefault = \App\Support\Domains::defaultFor('local'))
                    <div class="field">
                        <label>Domains <span class="hint">optional; the first is primary (used by Copy SSH when set to connect by domain)</span></label>
                        @include('partials.listedit', ['name' => 'local_domains', 'values' => old('local_domains', $names['local']), 'placeholder' => 'nas.'.($localDefault ?? 'example.com'), 'addLabel' => 'Add domain', 'suffix' => $localDefault, 'slugFrom' => 'name'])
                    </div>
                </div>
            </section>

            <section class="formcard">
                <h2>Copy SSH</h2>
                <p class="sub">Clicking the server copies <code>ssh [-p port] [user@]host</code>.</p>
                <div class="grid2">
                    <div class="field">
                        <label for="ssh_user">User <span class="hint">blank = the default from Settings</span></label>
                        <input id="ssh_user" name="ssh_user" type="text" maxlength="32" autocomplete="off" value="{{ old('ssh_user', $server->ssh_user) }}">
                    </div>
                    <div class="field">
                        <label for="ssh_port">Port</label>
                        <input id="ssh_port" name="ssh_port" type="number" min="1" max="65535" required value="{{ old('ssh_port', $server->ssh_port ?? 22) }}">
                    </div>
                </div>
            </section>

            <section class="formcard">
                <h2>Health</h2>
                <p class="sub">A server is up when the ports below accept a connection.</p>
                <label class="check">
                    <input type="hidden" name="monitor" value="0">
                    <input type="checkbox" name="monitor" value="1" @checked((bool) old('monitor', $server->monitor))>
                    <span>Monitor this server <span class="hint">(untick to show "not monitored" instead of a status)</span></span>
                </label>
                <label class="check">
                    <input type="hidden" name="check_ssh" value="0">
                    <input type="checkbox" name="check_ssh" value="1" @checked((bool) old('check_ssh', $server->check_ssh))>
                    Count the SSH port as a check
                </label>
                <div class="field">
                    <label for="ports">Extra TCP ports <span class="hint">comma or space separated, e.g. 445, 8080</span></label>
                    <input id="ports" name="ports" type="text" placeholder="445, 8080" value="{{ old('ports', is_array(old('ports')) ? implode(', ', old('ports')) : implode(', ', $server->ports ?? [])) }}">
                </div>
            </section>

            <details class="formcard" @if (old('notes', $server->notes) || old('icon', $server->icon)) open @endif>
                <summary>Notes and icon</summary>
                <div class="grid2">
                    <div class="field">
                        <label for="notes">Notes <span class="hint">optional, shown on the Servers page</span></label>
                        <input id="notes" name="notes" type="text" maxlength="500" value="{{ old('notes', $server->notes) }}">
                    </div>
                    <div class="field">
                        <label>Icon <span class="hint">matched from the name unless you pick one</span></label>
                        @include('partials.iconpick', ['model' => $server, 'value' => old('icon', $server->icon)])
                    </div>
                </div>
            </details>

            <div class="savebar">
                <button class="primary" type="submit">Save</button>
                <a class="btn" href="{{ route('servers.index') }}">Cancel</a>
            </div>
        </form>

        @if ($server->exists)
            <form class="card danger-zone" method="POST" action="{{ route('servers.destroy', $server) }}">
                @csrf @method('DELETE')
                <p class="muted">Deleting a server leaves its services without an address until you point them elsewhere.</p>
                <button class="btn-danger" type="submit" data-confirm>Delete this server</button>
            </form>
        @endif
    </div>
@endsection
