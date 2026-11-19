@extends('layouts.app')

@php
    $isImport = $mode === 'import';
    $actionable = collect($items)->whereIn('action', ['create', 'update']);
@endphp

@section('title', $isImport ? 'Preview NPM import' : 'Preview NPM push')

@section('content')
    <h1>{{ $isImport ? 'Import from NPM' : 'Push to NPM' }}: preview</h1>
    <p class="muted">
        @if ($isImport)
            Proxy hosts in Nginx Proxy Manager compared with your LabMan services. Ticked changes will be saved to LabMan; NPM is not touched.
        @else
            LabMan services with a domain compared with the proxy hosts in Nginx Proxy Manager. Ticked changes will be <strong>written to NPM</strong>.
        @endif
    </p>

    @if ($items === [])
        <div class="card empty">{{ $isImport ? 'NPM has no proxy hosts.' : 'No LabMan service has a domain, so there is nothing to push.' }}</div>
    @else
        <form method="POST" action="{{ route($isImport ? 'npm.import.apply' : 'npm.push.apply') }}">
            @csrf
            <div class="rows plan">
                @foreach ($items as $item)
                    @php($can = in_array($item['action'], ['create', 'update']))
                    <div class="plan-item plan-{{ $item['action'] }}">
                        <div class="plan-head">
                            @if ($can)
                                <label class="check">
                                    <input type="checkbox" name="apply[]" value="{{ $item['key'] }}" @checked($isImport || $item['action'] === 'update')>
                                    <strong>{{ $item['title'] }}</strong>
                                </label>
                                <input type="hidden" name="fp[{{ $item['key'] }}]" value="{{ $item['fingerprint'] }}">
                            @else
                                <strong>{{ $item['title'] }}</strong>
                            @endif
                            <span class="pill pill-{{ ['create' => 'degraded', 'update' => 'degraded', 'unchanged' => 'up', 'skip' => 'unknown'][$item['action']] }}">
                                {{ ['create' => $isImport ? 'new service' : 'new proxy host', 'update' => 'update', 'unchanged' => 'in sync', 'skip' => 'skipped'][$item['action']] }}
                            </span>
                        </div>

                        @if ($item['changes'])
                            <ul class="changes">
                                @foreach ($item['changes'] as $c)
                                    <li>
                                        <span class="field">{{ $c['field'] }}</span>
                                        @if ($c['from'] !== null && $c['from'] !== '') <span class="from">{{ $c['from'] }}</span> → @endif
                                        <span class="to">{{ $c['to'] }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        @foreach ($item['warnings'] as $w)
                            <p class="warn">{{ $w }}</p>
                        @endforeach
                    </div>
                @endforeach
            </div>

            <div class="actions plan-actions">
                @if ($actionable->isNotEmpty())
                    <button class="primary" type="submit">{{ $isImport ? 'Apply ticked changes to LabMan' : 'Write ticked changes to NPM' }}</button>
                @else
                    <span class="muted">Nothing to apply.</span>
                @endif
                <a class="btn" href="{{ route('npm.index') }}">Back</a>
            </div>
        </form>
    @endif
@endsection
