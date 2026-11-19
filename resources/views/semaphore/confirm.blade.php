@extends('layouts.admin')

@section('title', 'Confirm run')

@section('focus', '1')
@section('crumb', 'Workbooks')
@section('crumb_url', route('workbooks.index'))

@section('admin')
    <div class="wide">
        <h1>Confirm the run</h1>

        <div class="card confirm">
            <p class="big">Run <strong>{{ $template['name'] }}</strong> {{ $summary }}.</p>
            <ul class="changes">
                <li><span class="field">Playbook</span> <span class="mono">{{ $template['playbook'] ?? '?' }}</span></li>
                <li><span class="field">Hosts</span>
                    @if ($options['limit'] !== '')
                        <span class="mono">{{ $options['limit'] }}</span>
                    @else
                        <strong>no limit</strong>: every host in the template's inventory
                    @endif
                </li>
                <li><span class="field">Mode</span> {{ $options['dry_run'] ? 'Check mode: nothing is changed' : 'For real: changes will be made' }}</li>
                @if ($options['extra_vars'])
                    <li><span class="field">Extra variables</span> <span class="mono">{{ json_encode($options['extra_vars'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</span></li>
                @endif
                @if ($options['message'] !== '') <li><span class="field">Note</span> {{ $options['message'] }}</li> @endif
            </ul>
            @if ($options['limit'] === '' && ! $options['dry_run'])
                <p class="warn">This will make real changes on every host the template targets.</p>
            @endif
        </div>

        <form method="POST" action="{{ route('semaphore.run.start') }}" class="actions">
            @csrf
            <input type="hidden" name="project_id" value="{{ $options['project_id'] }}">
            <input type="hidden" name="template_id" value="{{ $options['template_id'] }}">
            <input type="hidden" name="limit" value="{{ $options['limit'] }}">
            <input type="hidden" name="dry_run" value="{{ $options['dry_run'] ? 1 : 0 }}">
            <input type="hidden" name="diff" value="{{ $options['diff'] ? 1 : 0 }}">
            <input type="hidden" name="extra_vars" value="{{ $options['extra_vars'] ? json_encode($options['extra_vars'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '' }}">
            <input type="hidden" name="message" value="{{ $options['message'] }}">
            <label class="check"><input type="checkbox" name="confirm" value="1" required> I have checked the hosts and mode above</label>
            <button class="primary" type="submit">Run now</button>
            <a class="btn" href="{{ route('semaphore.run', ['project' => $options['project_id'], 'template' => $options['template_id']]) }}">Back</a>
        </form>
    </div>
@endsection
