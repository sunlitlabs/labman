@extends('layouts.admin')

@section('title', 'Run #'.$taskId)

@php
    $status = $task['status'] ?? 'unknown';
    $pill = fn ($s) => match ($s) { 'success' => 'up', 'error' => 'down', 'stopped' => 'degraded', default => 'unknown' };
@endphp

@section('body_attrs')data-task-url="{{ route('semaphore.task.status', [$project, $taskId]) }}" data-task-total="{{ count($output) }}" data-task-finished="{{ $finished ? '1' : '0' }}"@endsection

@section('focus', '1')
@section('crumb', 'Workbooks')
@section('crumb_url', route('workbooks.index'))

@section('admin')
    <div class="toolbar">
        <h1>Run #{{ $taskId }}</h1>
        <span id="task-status" class="pill pill-{{ $pill($status) }}">{{ $status }}</span>
        @unless ($finished)
            <form method="POST" action="{{ route('semaphore.task.stop', [$project, $taskId]) }}" id="stop-form">
                @csrf
                <button class="btn-danger" type="submit" data-confirm>Stop</button>
            </form>
        @endunless
        <a class="btn" href="{{ route('workbooks.index', ['project' => $project]) }}">Back to workbooks</a>
    </div>
    <p class="muted">
        @if (! empty($task['created'])) Started {{ $task['created'] }}. @endif
        @if (! empty($task['limit'])) Limit: <span class="mono">{{ $task['limit'] }}</span>. @endif
        @if (! empty($task['dry_run'])) Check mode. @endif
        <span id="task-live" @if ($finished) hidden @endif>Updating live…</span>
    </p>

    <pre id="task-output" class="taskout">@foreach ($output as $line){{ $line['output'] }}
@endforeach</pre>
    @if ($output === [])
        <p id="task-empty" class="muted">Waiting for output…</p>
    @endif
@endsection

@push('scripts')
    <script src="{{ asset('js/task.js') }}" defer></script>
@endpush
