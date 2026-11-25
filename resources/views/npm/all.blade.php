@extends('layouts.admin')

@php($isImport = $mode === 'import')

@section('title', 'Preview all proxies')

@section('focus', '1')
@section('crumb', 'Nginx Proxy Manager')
@section('crumb_url', route('npm.index'))

@section('admin')
    <h1>{{ $isImport ? 'Import from every proxy' : 'Push to every proxy' }}: preview</h1>
    <p class="muted">
        One section per proxy. Each has its own switch, and only the changes you tick in switched-on sections are applied.
        @if ($isImport) Nothing in NPM is touched. @else Ticked changes are <strong>written to that zone's NPM</strong>; nothing is ever deleted there. @endif
        <a href="{{ route('npm.all', ['mode' => $isImport ? 'push' : 'import']) }}">Switch to {{ $isImport ? 'push' : 'import' }}</a>
    </p>

    <form method="POST" action="{{ route('npm.all.apply') }}">
        @csrf
        <input type="hidden" name="mode" value="{{ $mode }}">

        @foreach ($sections as $s)
            @php($zone = $s['zone'])
            @php($items = $s['items'])
            @php($actionable = collect($items)->whereIn('action', ['create', 'update'])->filter(fn ($i) => empty($i['errors'])))
            @php($byAction = collect($items)->countBy('action'))
            <section class="proxy-section" id="zone-{{ $zone->id }}">
                <div class="proxy-head">
                    <h2>{{ $zone->name }}
                        <span class="pill pill-{{ $s['error'] ? 'down' : ($actionable->isEmpty() ? 'up' : 'degraded') }}">{{ $s['error'] ? 'unreachable' : ($actionable->isEmpty() ? 'in sync' : $actionable->count().' to review') }}</span>
                    </h2>
                    @if (! $s['error'] && $actionable->isNotEmpty())
                        <label class="check"><input type="checkbox" name="zones[]" value="{{ $zone->id }}" checked> Apply this proxy's ticked changes</label>
                    @endif
                </div>

                @if ($s['error'])
                    <div class="card check-result check-failed" role="status">
                        <p class="check-line"><strong>{{ $zone->name }} could not be checked.</strong></p>
                        <p class="warn">{{ $s['error'] }}</p>
                        <p class="hint">The other proxies are not affected. <a href="{{ route('npm.proxy', $zone) }}">Check this connection</a></p>
                    </div>
                @else
                    <div class="card check-result {{ $actionable->isEmpty() ? 'inv-ok' : '' }}" role="status">
                        <p class="check-line"><strong>Checked {{ count($items) }} {{ $isImport ? 'proxy host' : 'service' }}{{ count($items) === 1 ? '' : 's' }}:</strong>
                            {{ $byAction->get('unchanged', 0) }} in sync, {{ $byAction->get('create', 0) }} to create, {{ $byAction->get('update', 0) }} to update, {{ $byAction->get('skip', 0) }} skipped.</p>
                        <p class="check-verdict">{{ $actionable->isEmpty() ? 'Nothing needs changing.' : $actionable->count().' '.($actionable->count() === 1 ? 'change is' : 'changes are').' ready to review below.' }}</p>
                    </div>
                    @if ($items !== [])
                        @include('npm._items', ['items' => $items, 'isImport' => $isImport, 'applyName' => 'apply['.$zone->id.'][]', 'fpPrefix' => 'fp['.$zone->id.']', 'zoneName' => $zone->name])
                    @endif
                @endif
            </section>
        @endforeach

        <div class="savebar plan-actions">
            <button class="primary" type="submit">{{ $isImport ? 'Apply ticked changes to LabMan' : 'Write ticked changes to the proxies' }}</button>
            <a class="btn" href="{{ route('npm.index') }}">Back</a>
        </div>
    </form>
@endsection
