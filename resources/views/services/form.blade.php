@extends('layouts.app')

@section('title', $service->exists ? 'Edit service' : 'Add service')

@section('content')
    <div class="wide">
        <h1>{{ $service->exists ? 'Edit '.$service->name : 'Add service' }}</h1>

        <form method="POST" action="{{ $service->exists ? route('services.update', $service) : route('services.store') }}">
            @csrf
            @if ($service->exists) @method('PUT') @endif

            <section class="formcard">
                <h2>The service</h2>
                <p class="sub">What it is called and the address people use.</p>
                <div class="grid2">
                    <div class="field">
                        <label for="name">Name</label>
                        <input id="name" name="name" type="text" required maxlength="100" value="{{ old('name', $service->name) }}">
                    </div>
                    <div class="field">
                        <label for="group">Group <span class="hint">optional heading</span></label>
                        <input id="group" name="group" type="text" maxlength="60" value="{{ old('group', $service->group) }}">
                    </div>
                    <div class="field">
                        <label for="domain">Public domain <span class="hint">optional; clicking the service opens it</span></label>
                        <input id="domain" name="domain" type="text" maxlength="253" placeholder="wiki.example.com" value="{{ old('domain', $service->domain) }}">
                    </div>
                </div>
            </section>

            <section class="formcard">
                <h2>Where it runs</h2>
                <p class="sub">Pick a server, or give the service its own address (exactly one of the two).</p>
                <div class="grid2">
                    <div class="field">
                        <label for="server_id">Server</label>
                        <select id="server_id" name="server_id">
                            <option value="">none, use an address</option>
                            @foreach ($servers as $s)
                                <option value="{{ $s->id }}" @selected((string) old('server_id', $service->server_id) === (string) $s->id)>{{ $s->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="address">Its own address</label>
                        <input id="address" name="address" type="text" maxlength="253" placeholder="192.0.2.1" value="{{ old('address', $service->address) }}">
                    </div>
                    <div class="field">
                        <label for="scheme">Scheme</label>
                        <select id="scheme" name="scheme">
                            @foreach (['http', 'https'] as $scheme)
                                <option value="{{ $scheme }}" @selected(old('scheme', $service->scheme) === $scheme)>{{ $scheme }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="port">Port <span class="hint">blank = 80, or 443 for https</span></label>
                        <input id="port" name="port" type="number" min="1" max="65535" value="{{ old('port', $service->port) }}">
                    </div>
                    <div class="field">
                        <label for="path">Path</label>
                        <input id="path" name="path" type="text" maxlength="500" value="{{ old('path', $service->path ?: '/') }}">
                    </div>
                </div>
            </section>

            <section class="formcard">
                <h2>Health check</h2>
                <p class="sub">A service shows as up when <strong>any</strong> check passes: the web request, an open port, or its public address.</p>
                <label class="check">
                    <input type="hidden" name="monitor" value="0">
                    <input type="checkbox" name="monitor" value="1" @checked((bool) old('monitor', $service->monitor))>
                    <span>Monitor this service <span class="hint">(untick to show "not monitored" instead of a status)</span></span>
                </label>
                <label class="check">
                    <input type="hidden" name="check_tcp" value="0">
                    <input type="checkbox" name="check_tcp" value="1" @checked((bool) old('check_tcp', $service->check_tcp))>
                    An open port counts as up, even if the web request fails
                </label>
                <label class="check">
                    <input type="hidden" name="public_check" value="0">
                    <input type="checkbox" name="public_check" value="1" @checked((bool) old('public_check', $service->public_check))>
                    Also check the public domain over HTTPS
                </label>
                <div class="grid2">
                    <div class="field">
                        <label for="health_path">Health path <span class="hint">if different from Path, e.g. /health</span></label>
                        <input id="health_path" name="health_path" type="text" maxlength="500" placeholder="/health" value="{{ old('health_path', $service->health_path) }}">
                    </div>
                    <div class="field">
                        <label for="expect">Healthy status <span class="hint">e.g. 200, 200-499, 200-399,401</span></label>
                        <input id="expect" name="expect" type="text" maxlength="60" value="{{ old('expect', $service->expect ?: '200-499') }}">
                    </div>
                </div>
                @if ($service->exists)
                    <a class="btn" href="{{ route('services.diagnose', $service) }}">Diagnose this health check…</a>
                @endif
            </section>

            <details class="formcard" @if (old('notes', $service->notes) || old('icon', $service->icon) || ! old('verify_tls', $service->verify_tls) || old('websockets', $service->websockets)) open @endif>
                <summary>More: notes, icon, TLS, websockets</summary>
                <div class="grid2">
                    <div class="field">
                        <label for="notes">Notes <span class="hint">optional, shown on the Services page</span></label>
                        <input id="notes" name="notes" type="text" maxlength="500" value="{{ old('notes', $service->notes) }}">
                    </div>
                    <div class="field">
                        <label for="icon">Icon <span class="hint">blank = guess from the name, none = no icon</span></label>
                        <input id="icon" name="icon" type="text" maxlength="80" autocomplete="off" list="icon-list" data-suggest-url="{{ route('icons.search') }}" value="{{ old('icon', $service->icon) }}">
                        <datalist id="icon-list"></datalist>
                        @if ($service->exists && ! $service->icon && ($auto = \App\Icons\Icons::slugFor($service)))
                            <small class="hint">Currently matched from the name: {{ $auto }}</small>
                        @endif
                    </div>
                </div>
                @foreach (['verify_tls' => 'Verify the certificate of the public check', 'websockets' => 'Uses websockets (informational; synced with NPM)'] as $flag => $text)
                    <label class="check">
                        <input type="hidden" name="{{ $flag }}" value="0">
                        <input type="checkbox" name="{{ $flag }}" value="1" @checked((bool) old($flag, $service->{$flag}))>
                        {{ $text }}
                    </label>
                @endforeach
            </details>

            <div class="savebar">
                <button class="primary" type="submit">Save</button>
                <a class="btn" href="{{ route('services.index') }}">Cancel</a>
            </div>
        </form>

        @if ($service->exists && $service->domain && \App\Npm\Connection::summary())
            <div class="formcard">
                <h2>Nginx Proxy Manager</h2>
                <p class="sub">
                    {{ $service->npm_host_id ? 'Linked to NPM proxy host #'.$service->npm_host_id.'.' : 'Not linked to a proxy host yet.' }}
                </p>
                <a class="btn" href="{{ route('npm.wizard', ['service' => $service->id]) }}">Compare with NPM…</a>
            </div>
        @endif

        @if ($service->exists)
            <form class="card danger-zone" method="POST" action="{{ route('services.destroy', $service) }}">
                @csrf @method('DELETE')
                <button class="btn-danger" type="submit" data-confirm>Delete this service</button>
            </form>
        @endif
    </div>
@endsection
