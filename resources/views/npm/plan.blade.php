@extends('layouts.admin')

@php
    $isImport = $mode === 'import';
    $zp = $zp ?? [];
    $actionable = collect($items)->whereIn('action', ['create', 'update'])->filter(fn ($i) => empty($i['errors']));
@endphp

@section('title', $isImport ? 'Preview NPM import' : 'Preview NPM push')

@section('focus', '1')
@section('crumb', 'Nginx Proxy Manager')
@section('crumb_url', route('npm.index', $zp))

@section('admin')
    <h1>{{ $isImport ? 'Import from NPM' : 'Push to NPM' }}: preview <span class="muted">{{ $zp && isset($zone) ? '· '.$zone->name.' zone' : '' }}</span></h1>
    <p class="muted">
        @if ($isImport)
            Proxy hosts in Nginx Proxy Manager compared with your LabMan services. Ticked changes will be saved to LabMan; NPM is not touched.
        @else
            LabMan services with a domain compared with the proxy hosts in Nginx Proxy Manager. Ticked changes will be <strong>written to NPM</strong>.
        @endif
    </p>

    @php($byAction = collect($items)->countBy('action'))
    <div class="card check-result {{ $actionable->isEmpty() ? 'inv-ok' : '' }}" role="status">
        <p class="check-line"><strong>Checked {{ count($items) }} {{ $isImport ? 'proxy host' : 'service' }}{{ count($items) === 1 ? '' : 's' }}:</strong>
            {{ $byAction->get('unchanged', 0) }} in sync, {{ $byAction->get('create', 0) }} to create, {{ $byAction->get('update', 0) }} to update, {{ $byAction->get('skip', 0) }} skipped.</p>
        <p class="check-verdict">{{ $actionable->isEmpty() ? 'Nothing needs changing.' : $actionable->count().' '.($actionable->count() === 1 ? 'change is' : 'changes are').' ready to review below.' }}</p>
    </div>

    @if ($items === [])
        <div class="card empty">{{ $isImport ? 'NPM has no proxy hosts.' : 'No LabMan service has a domain, so there is nothing to push.' }}</div>
    @else
        <form method="POST" action="{{ route($isImport ? 'npm.import.apply' : 'npm.push.apply', $zp) }}">
            @csrf
            @include('npm._items', ['items' => $items, 'isImport' => $isImport, 'applyName' => 'apply[]', 'fpPrefix' => 'fp', 'zoneName' => isset($zone) ? $zone->name : null])

            <div class="savebar plan-actions">
                @if ($actionable->isNotEmpty())
                    <button class="primary" type="submit">{{ $isImport ? 'Apply ticked changes to LabMan' : 'Write ticked changes to NPM' }}</button>
                @else
                    <span class="muted">Nothing to apply.</span>
                @endif
                <a class="btn" href="{{ route('npm.index', $zp) }}">Back</a>
            </div>
        </form>
    @endif
@endsection
