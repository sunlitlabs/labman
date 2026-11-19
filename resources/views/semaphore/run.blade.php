@extends('layouts.admin')

@section('title', 'Run template')

@php
    $oldHosts = (array) ($old['hosts'] ?? []);
    $oldGroups = (array) ($old['groups'] ?? []);
@endphp

@section('focus', '1')
@section('crumb', 'Semaphore')
@section('crumb_url', route('semaphore.index'))

@section('admin')
    <div class="wide">
        <h1>Run: {{ $template['name'] }}</h1>
        <p class="muted"><span class="mono">{{ $template['playbook'] ?? '' }}</span>@if (! empty($template['description'])) · {{ $template['description'] }}@endif</p>

        <form method="POST" action="{{ route('semaphore.run.confirm') }}">
            @csrf
            <input type="hidden" name="project_id" value="{{ $project }}">
            <input type="hidden" name="template_id" value="{{ $template['id'] }}">

            <div class="card">
                <h2>Where to run it</h2>
                <p class="muted">Leave everything unticked to run on every host the template's inventory targets. Tick groups and servers to limit the run.</p>

                @if ($targets['groups'])
                    <div class="field">
                        <label>Groups</label>
                        <div class="pick">
                            @foreach ($targets['groups'] as $g)
                                <label class="check"><input type="checkbox" name="groups[]" value="{{ $g }}" @checked(in_array($g, $oldGroups, true))> <span class="mono">{{ $g }}</span></label>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if ($targets['hosts'])
                    <div class="field">
                        <label>Servers</label>
                        <div class="pick">
                            @foreach ($targets['hosts'] as $h)
                                <label class="check"><input type="checkbox" name="hosts[]" value="{{ $h['name'] }}" @checked(in_array($h['name'], $oldHosts, true))> {{ $h['label'] }} @if ($h['label'] !== $h['name']) <span class="muted mono">{{ $h['name'] }}</span> @endif</label>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="field">
                    <label for="limit_extra">Other hosts or patterns <span class="hint">comma separated, e.g. web*,!web3</span></label>
                    <input id="limit_extra" name="limit_extra" type="text" maxlength="300" value="{{ $old['limit_extra'] ?? '' }}">
                </div>
            </div>

            <div class="card">
                <h2>How to run it</h2>
                <label class="check"><input type="checkbox" name="dry_run" value="1" @checked($old['dry_run'] ?? false)> Check mode (dry run): show what would change without changing it</label>
                <label class="check"><input type="checkbox" name="diff" value="1" @checked($old['diff'] ?? false)> Show diffs of changed files</label>
                @foreach ($survey as $var)
                    @continue($var['type'] === 'secret')
                    <div class="field">
                        <label for="survey-{{ $var['name'] }}">{{ $var['title'] }}
                            <span class="hint">{{ $var['name'] }}@if ($var['required']), required @endif @if ($var['description']) · {{ $var['description'] }} @endif</span></label>
                        @if ($var['type'] === 'enum')
                            <select id="survey-{{ $var['name'] }}" name="survey[{{ $var['name'] }}]" @required($var['required'])>
                                <option value="">{{ $var['required'] ? '— choose —' : '— not set —' }}</option>
                                @foreach ($var['values'] as $choice)
                                    <option value="{{ $choice['value'] }}" @selected(($old['survey'][$var['name']] ?? '') === $choice['value'])>{{ $choice['name'] }}</option>
                                @endforeach
                            </select>
                        @else
                            <input id="survey-{{ $var['name'] }}" name="survey[{{ $var['name'] }}]" type="{{ $var['type'] === 'int' ? 'number' : 'text' }}" @if ($var['type'] === 'int') step="1" @endif maxlength="200" @required($var['required']) value="{{ $old['survey'][$var['name']] ?? '' }}">
                        @endif
                    </div>
                @endforeach

                @if ($survey === [])
                    <p class="hint">This template declares no variables. Semaphore (recent versions) only accepts extra variables that a template declares as survey variables.</p>
                @endif

                <details>
                    <summary class="muted">Extra variables as JSON (advanced)</summary>
                    <div class="field">
                        <label for="extra_vars">Extra variables <span class="hint">optional JSON object, e.g. {"version": "1.2"}; values above win</span></label>
                        <textarea id="extra_vars" name="extra_vars" spellcheck="false" rows="3">{{ $old['extra_vars'] ?? '' }}</textarea>
                    </div>
                </details>
                <div class="field">
                    <label for="message">Note for the run log <span class="hint">optional</span></label>
                    <input id="message" name="message" type="text" maxlength="200" value="{{ $old['message'] ?? '' }}">
                </div>
            </div>

            <div class="savebar">
                <button class="primary" type="submit">Review the run</button>
                <a class="btn" href="{{ route('semaphore.index', ['project' => $project]) }}">Cancel</a>
            </div>
        </form>
    </div>
@endsection
