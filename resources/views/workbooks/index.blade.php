@extends('layouts.admin')

@section('title', 'Workbooks')

@php
    $pill = fn ($status) => match ($status) { 'success' => 'up', 'error' => 'down', 'stopped' => 'degraded', default => 'unknown' };
    $carried = $carry['hosts'] !== [] || $carry['groups'] !== [];
@endphp

@section('admin')
    <h1>Workbooks</h1>
    <p class="muted">
        Your Ansible playbooks (Semaphore templates). Pick one, choose which servers and groups it runs on, and review it before it starts.
        Every run needs an explicit confirmation. LabMan only starts, follows and stops runs.
    </p>

    @if ($error)
        <div class="errors" role="alert"><ul><li>{{ $error }}</li></ul></div>
    @endif

    @unless ($connection)
        <div class="card empty">Semaphore is not connected yet. <a href="{{ route('semaphore.index') }}">Connect it</a> to see your workbooks here.</div>
    @else
        @if ($carried)
            <div class="notice">
                <strong>Aimed at:</strong> {{ implode(', ', $carryLabels) }}.
                Choose a workbook to run on {{ count($carryLabels) === 1 ? 'it' : 'them' }}; you can still change the targets and review the run first.
                <a href="{{ route('workbooks.index', ['project' => $projectId]) }}">Clear</a>
            </div>
        @endif

        @if (count($projects) > 1)
            <form method="GET" action="{{ route('workbooks.index') }}" class="toolbar">
                @foreach ($carry['hosts'] as $h)<input type="hidden" name="hosts[]" value="{{ $h }}">@endforeach
                @foreach ($carry['groups'] as $g)<input type="hidden" name="groups[]" value="{{ $g }}">@endforeach
                <label for="project" class="muted">Project</label>
                <select id="project" name="project" class="inline-select">
                    @foreach ($projects as $p)
                        <option value="{{ $p['id'] }}" @selected($projectId === (int) $p['id'])>{{ $p['name'] }}</option>
                    @endforeach
                </select>
                <button type="submit">Show</button>
            </form>
        @endif

        @if ($projectId)
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
                                    <a class="btn btn-primary" href="{{ route('semaphore.run', ['project' => $projectId, 'template' => $t['id']] + $carry) }}">Run…</a>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="card empty">This project has no workbooks (templates).</div>
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
    @endunless
@endsection
