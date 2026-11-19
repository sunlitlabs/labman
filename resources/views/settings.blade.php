@extends('layouts.app')

@section('title', 'Settings')

@php
    $admin = auth()->user()?->isAdmin();
    $levels = ['everyone' => 'Everyone (including guests)', 'users' => 'Signed-in users', 'admins' => 'Admins only'];
    $v = fn ($key, $default = null) => old($key, $values[$key] ?? $default);
@endphp

@section('content')
    <h1>Settings</h1>
    @unless ($admin)
        <p class="muted">You can view these settings but only administrators can change them.</p>
    @endunless

    <form class="card" method="POST" action="{{ route('settings.update') }}">
        @csrf @method('PUT')
        <fieldset @disabled(! $admin) class="plain">
            <h2>General</h2>
            <div class="grid2">
                <div class="field">
                    <label for="title">Site title</label>
                    <input id="title" name="title" type="text" maxlength="60" required value="{{ $v('title') }}">
                </div>
                <div class="field">
                    <label for="registration_mode">Registration</label>
                    <select id="registration_mode" name="registration_mode">
                        @foreach (['off' => 'Off', 'open' => 'Open', 'approval' => 'Open, admin approval required'] as $mode => $label)
                            <option value="{{ $mode }}" @selected($v('registration_mode') === $mode)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <h2>Health checks</h2>
            <div class="grid2">
                <div class="field">
                    <label for="check_interval">Check interval (seconds) <span class="hint">min 5</span></label>
                    <input id="check_interval" name="check_interval" type="number" step="any" min="5" required value="{{ $v('check_interval') }}">
                </div>
                <div class="field">
                    <label for="timeout">Timeout (seconds) <span class="hint">0.5 to 60</span></label>
                    <input id="timeout" name="timeout" type="number" step="any" min="0.5" max="60" required value="{{ $v('timeout') }}">
                </div>
            </div>

            <h2>Copy SSH</h2>
            <div class="grid2">
                <div class="field">
                    <label for="ssh_user">Default SSH user <span class="hint">(optional)</span></label>
                    <input id="ssh_user" name="ssh_user" type="text" maxlength="32" autocomplete="off" value="{{ $v('ssh_user', '') }}">
                </div>
                <div class="field">
                    <label for="ssh_target">Connect to the server's</label>
                    <select id="ssh_target" name="ssh_target">
                        <option value="address" @selected($v('ssh_target') === 'address')>address</option>
                        <option value="domain" @selected($v('ssh_target') === 'domain')>domain (when it has one)</option>
                    </select>
                </div>
            </div>

            <h2>Who can see each screen</h2>
            <div class="grid2">
                @foreach (config('labman.screens') as $screen)
                    <div class="field">
                        <label for="vis-{{ $screen }}">{{ ucfirst($screen) }}</label>
                        <select id="vis-{{ $screen }}" name="visibility[{{ $screen }}]">
                            @foreach ($levels as $level => $label)
                                <option value="{{ $level }}" @selected(old('visibility.'.$screen, $values['visibility.'.$screen]) === $level)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                @endforeach
            </div>
            <p class="hint">Editing is always admin-only, whatever the visibility.</p>
        </fieldset>

        @if ($admin)
            <button class="primary" type="submit">Save settings</button>
        @endif
    </form>

    @if ($admin)
        <div class="card">
            <h2>MCP access (API tokens)</h2>
            <p class="muted">
                Let an AI assistant use LabMan through the Model Context Protocol at <code>{{ url('/mcp') }}</code>.
                @unless (config('labman.mcp.enabled')) <strong>MCP is currently disabled (LABMAN_MCP_ENABLED).</strong> @endunless
            </p>

            @if ($tokens->isNotEmpty())
                <table class="plain">
                    <thead><tr><th>Name</th><th>Scope</th><th>Token</th><th>Owner</th><th>Last used</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($tokens as $token)
                            <tr>
                                <td>{{ $token->name }}</td>
                                <td>{{ $token->scope }}</td>
                                <td class="mono">{{ $token->token_prefix }}…</td>
                                <td>{{ $token->user?->name }}</td>
                                <td>{{ $token->last_used_at?->diffForHumans() ?? 'never' }}</td>
                                <td>
                                    <form method="POST" action="{{ route('tokens.destroy', $token) }}">
                                        @csrf @method('DELETE')
                                        <button class="btn-danger" type="submit" data-confirm>Revoke</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            <form method="POST" action="{{ route('tokens.store') }}" class="grid2 token-form">
                @csrf
                <div class="field">
                    <label for="token_name">New token name</label>
                    <input id="token_name" name="name" type="text" maxlength="60" required placeholder="e.g. Claude on my laptop">
                </div>
                <div class="field">
                    <label for="token_scope">Scope</label>
                    <select id="token_scope" name="scope">
                        <option value="read">read: view services, servers, health, settings</option>
                        <option value="admin">admin: everything an admin can do (except tokens)</option>
                    </select>
                </div>
                <div><button class="primary" type="submit">Create token</button></div>
            </form>
        </div>

        <div class="card">
            <h2>Nginx Proxy Manager</h2>
            <p class="muted">Import proxy hosts as services and push edits back, with a preview of every change.</p>
            <a class="btn" href="{{ route('npm.index') }}">Open Nginx Proxy Manager</a>
        </div>

        <div class="card">
            <h2>YAML import / export</h2>
            <p class="muted">Import upserts servers and services by name. Export downloads the current configuration in the same format.</p>
            <form method="POST" action="{{ route('settings.import') }}" enctype="multipart/form-data">
                @csrf
                <div class="field">
                    <label for="yaml">Paste YAML</label>
                    <textarea id="yaml" name="yaml" spellcheck="false" placeholder="servers:&#10;  - name: nas&#10;    address: 192.0.2.10&#10;services: []">{{ old('yaml') }}</textarea>
                </div>
                <div class="field">
                    <label for="yaml_file">…or choose a file</label>
                    <input id="yaml_file" name="yaml_file" type="file" accept=".yml,.yaml,text/yaml,text/plain">
                </div>
                <label class="check"><input type="checkbox" name="replace" value="1"> Replace: delete servers and services that are not in the file</label>
                <div class="actions">
                    <button class="primary" type="submit">Import</button>
                    <a class="btn" href="{{ route('settings.export') }}">Export YAML</a>
                </div>
            </form>
        </div>
    @endif
@endsection
