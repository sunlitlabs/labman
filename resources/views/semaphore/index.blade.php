@extends('layouts.app')

@section('title', 'Semaphore')

@php
    $pill = fn ($status) => match ($status) { 'success' => 'up', 'error' => 'down', 'stopped' => 'degraded', default => 'unknown' };
@endphp

@section('content')
    <h1>Semaphore</h1>
    <p class="muted">
        Run your Ansible playbooks (Semaphore templates) from here. Every run is shown to you and needs an explicit
        confirmation first. LabMan only starts, follows and stops runs; Semaphore's own setup is not changed.
    </p>

    @if ($error)
        <div class="errors" role="alert"><ul><li>{{ $error }}</li></ul></div>
    @endif

    @if ($connection)
        <div class="card">
            <div class="toolbar">
                <div>
                    <h2>Connected</h2>
                    <span class="mono">{{ $connection['url'] }}</span>
                    @unless ($connection['verify_tls']) <span class="muted">(TLS certificate not verified)</span> @endunless
                </div>
                <form method="POST" action="{{ route('semaphore.disconnect') }}">
                    @csrf @method('DELETE')
                    <button class="btn-danger" type="submit" data-confirm>Disconnect</button>
                </form>
            </div>

            @if ($projects)
                <form method="GET" action="{{ route('semaphore.index') }}" class="toolbar">
                    <label for="project" class="muted">Project</label>
                    <select id="project" name="project" class="inline-select">
                        @foreach ($projects as $p)
                            <option value="{{ $p['id'] }}" @selected($projectId === (int) $p['id'])>{{ $p['name'] }}</option>
                        @endforeach
                    </select>
                    <button type="submit">Show</button>
                </form>
            @endif
        </div>

        @if ($projectId)
            <h2>Templates</h2>
            @if ($templates)
                <div class="rows">
                    @foreach ($templates as $t)
                        @php($last = $t['last_task'] ?? null)
                        <div class="plan-item">
                            <div class="plan-head">
                                <div>
                                    <strong>{{ $t['name'] }}</strong>
                                    <span class="mono muted">{{ $t['playbook'] ?? '' }}</span>
                                    @if (! empty($t['description'])) <div class="muted">{{ $t['description'] }}</div> @endif
                                </div>
                                <div class="actions">
                                    @if ($last)
                                        <a class="pill pill-{{ $pill($last['status'] ?? '') }}" href="{{ route('semaphore.task', [$projectId, $last['id']]) }}" title="Last run">{{ $last['status'] ?? '?' }}</a>
                                    @endif
                                    <a class="btn btn-primary" href="{{ route('semaphore.run', ['project' => $projectId, 'template' => $t['id']]) }}">Run…</a>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="card empty">This project has no templates.</div>
            @endif

            @if ($recent)
                <h2>Recent runs</h2>
                <div class="rows">
                    @foreach ($recent as $r)
                        <div class="plan-item">
                            <div class="plan-head">
                                <div>
                                    <a href="{{ route('semaphore.task', [$projectId, $r['id']]) }}"><strong>#{{ $r['id'] }}</strong> {{ $r['tpl_alias'] ?? ('template '.($r['template_id'] ?? '?')) }}</a>
                                    <span class="muted">{{ $r['message'] ?? '' }}</span>
                                    <div class="muted">{{ $r['created'] ?? '' }}@if (! empty($r['limit'])) · limit {{ $r['limit'] }}@endif</div>
                                </div>
                                <span class="pill pill-{{ $pill($r['status'] ?? '') }}">{{ $r['status'] ?? '?' }}</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        @endif
    @endif

    <form class="card" method="POST" action="{{ route('semaphore.connect') }}">
        @csrf
        <h2>{{ $connection ? 'Change connection' : 'Connect' }}</h2>
        <div class="grid2">
            <div class="field">
                <label for="url">Semaphore address <span class="hint">usually port 3000</span></label>
                <input id="url" name="url" type="text" required placeholder="http://192.0.2.7:3000" value="{{ old('url', $connection['url'] ?? '') }}">
            </div>
            <div class="field">
                <label for="token">API token <span class="hint">{{ $connection ? 'leave blank to keep the saved one (same address only)' : 'create one in Semaphore under your user menu' }}</span></label>
                <input id="token" name="token" type="password" autocomplete="new-password" @required(! $connection)>
            </div>
        </div>
        <label class="check">
            <input type="hidden" name="verify_tls" value="0">
            <input type="checkbox" name="verify_tls" value="1" @checked(old('verify_tls', $connection['verify_tls'] ?? true))>
            Verify the TLS certificate (untick for a self-signed https address)
        </label>
        <p class="hint">The token acts as the Semaphore user who made it, so use a dedicated user that can only run the templates you want available here. It is stored encrypted.</p>
        <button class="primary" type="submit">Test and save</button>
    </form>
@endsection
