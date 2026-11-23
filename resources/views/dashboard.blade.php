@extends('layouts.app')

@section('title', 'Dashboard')
{{-- Block form on purpose: an inline @section('x', '...') HTML-escapes its content. --}}
@section('body_attrs')data-status-url="{{ route('status') }}" data-interval="{{ \App\Support\Settings::interval() }}"@endsection

@section('content')
    @php
        $totals = ['up' => 0, 'degraded' => 0, 'down' => 0, 'unknown' => 0];
        foreach ($summary as $counts) {
            foreach ($counts as $state => $n) { $totals[$state] += $n; }
        }
        $trouble = $totals['down'] + $totals['degraded'];
        $watched = array_sum($totals);
        $headline = $watched === 0 ? 'Nothing is being monitored yet' : ($trouble === 0 ? 'All systems operational' : ($trouble === 1 ? '1 item needs attention' : $trouble.' items need attention'));
        $tone = $watched === 0 || $totals['unknown'] === $watched ? 'unknown' : ($totals['down'] > 0 ? 'down' : ($totals['degraded'] > 0 ? 'degraded' : 'up'));
    @endphp

    <section class="hero hero-{{ $tone }}" id="hero" aria-live="polite">
        <div>
            <h1 id="headline">{{ $headline }}</h1>
            <p class="muted">Click a service to open it, or a server to copy its SSH command.</p>
        </div>
        <div class="hero-counts" aria-label="Status summary">
            @foreach (['up', 'degraded', 'down'] as $state)
                <span class="pill pill-{{ $state }}"><span data-total="{{ $state }}">{{ $totals[$state] }}</span> {{ $state }}</span>
            @endforeach
            @if ($totals['unknown'] > 0)
                <span class="pill pill-unknown"><span data-total="unknown">{{ $totals['unknown'] }}</span> unknown</span>
            @endif
        </div>
    </section>

    <div class="toolbar">
        <div class="filter">
            <label class="sr-only" for="filter">Search</label>
            <input id="filter" type="search" autocomplete="off" placeholder="Search services and servers…" spellcheck="false">
            <span class="kbd" aria-hidden="true">/</span>
        </div>
        <span id="conn" class="conn" hidden>Live updates paused: cannot reach the server.</span>
        @can('admin')
            <a class="btn" href="{{ route('services.create') }}">Add service</a>
            <a class="btn" href="{{ route('servers.create') }}">Add server</a>
            <a class="btn" href="{{ route('links.create') }}">Add link</a>
        @endcan
    </div>

    {{-- Hidden counters the live update keeps in step (the hero reads these). --}}
    <span hidden>
        @foreach ($summary as $kind => $counts)
            @foreach ($counts as $state => $n)<span data-count="{{ $kind }}:{{ $state }}">{{ $n }}</span>@endforeach
        @endforeach
    </span>

    @if ($empty)
        <div class="card empty">
            Nothing here yet.
            @can('admin') <a href="{{ route('services.create') }}">Add a service</a>, <a href="{{ route('servers.create') }}">add a server</a> or import a YAML file under Settings.@endcan
        </div>
    @endif

    @if ($canServices)
        @foreach ($serviceGroups as $group => $items)
            <section class="group">
                <h2>{{ $group !== '' ? $group : 'Services' }}</h2>
                <div class="tiles">
                    @foreach ($items as $service)
                        @php($open = $service->openUrl())
                        @php($st = \App\Monitoring\StatusView::of($service))
                        @php($bad = collect($st['checks'])->firstWhere('ok', false))
                        <article class="tile {{ $open ? 'clickable' : '' }}" data-kind="services" data-id="{{ $service->id }}" data-state="{{ $st['state'] }}"
                                 @if ($open) data-href="{{ $open }}" tabindex="0" role="link" aria-label="Open {{ $service->name }}" @endif
                                 data-filter="{{ strtolower(implode(' ', [$service->name, $service->domain, $service->public_domain, $service->backendAddress(), $service->group, $service->notes])) }}">
                            @include('partials.icon', ['model' => $service])
                            <div class="tile-body">
                                <span class="tile-name">{{ $service->name }}</span>
                                <small class="tile-sub">{{ $service->domain ?: $service->public_domain ?: ($service->backendAddress() ? \App\Support\Hosts::forUrl($service->backendAddress()).':'.$service->effectivePort() : 'no address') }}</small>
                                <small class="tile-detail" data-detail>@if ($bad){{ $bad['label'] }} · {{ $bad['detail'] }}@endif</small>
                                @if ($service->backendUrl() && ($service->domain || $service->public_domain))
                                    <a class="tile-ip mono" href="{{ $service->backendUrl() }}" target="_blank" rel="noopener noreferrer" title="Open the backend directly, bypassing the domain name">{{ \App\Support\Hosts::forUrl($service->backendAddress()) }}:{{ $service->effectivePort() }}</a>
                                @endif
                                @if ($service->domain && $service->public_domain)
                                    <a class="tile-public" href="{{ $service->publicDomainUrl() }}" target="_blank" rel="noopener noreferrer" title="Open the public address">{{ $service->public_domain }}</a>
                                @endif
                            </div>
                            <span data-pill class="pill pill-{{ $st['state'] }}{{ $st['stale'] && $st['state'] !== 'off' ? ' is-stale' : '' }}" title="{{ $st['checked_at'] ? 'Checked '.$st['checked_at'] : 'Not checked yet' }}">{{ \App\Monitoring\StatusView::label($st['state']) }}</span>
                        </article>
                    @endforeach
                </div>
            </section>
        @endforeach
    @endif

    @if ($canServers)
        @foreach ($serverGroups as $group => $items)
            <section class="group">
                <h2>{{ $group !== '' ? 'Servers · '.$group : 'Servers' }}</h2>
                <div class="tiles">
                    @foreach ($items as $server)
                        @php($ssh = $server->sshCommand())
                        @php($st = \App\Monitoring\StatusView::of($server))
                        @php($bad = collect($st['checks'])->firstWhere('ok', false))
                        <article class="tile clickable" tabindex="0" role="button" data-kind="servers" data-id="{{ $server->id }}" data-state="{{ $st['state'] }}"
                                 data-ssh="{{ $ssh }}" aria-label="Copy SSH command for {{ $server->name }}: {{ $ssh }}"
                                 data-filter="{{ strtolower(implode(' ', [$server->name, $server->domain, $server->address, $server->group, $server->notes])) }}">
                            @include('partials.icon', ['model' => $server])
                            <div class="tile-body">
                                <span class="tile-name">{{ $server->name }}</span>
                                <a class="tile-sub mono" href="{{ $server->addressUrl() }}" target="_blank" rel="noopener noreferrer" title="Open http://{{ $server->address }}">{{ $server->address }}</a>
                                <small class="tile-detail" data-detail>@if ($bad){{ $bad['label'] }} · {{ $bad['detail'] }}@endif</small>
                            </div>
                            <span data-pill class="pill pill-{{ $st['state'] }}{{ $st['stale'] && $st['state'] !== 'off' ? ' is-stale' : '' }}" title="{{ $st['checked_at'] ? 'Checked '.$st['checked_at'] : 'Not checked yet' }}">{{ \App\Monitoring\StatusView::label($st['state']) }}</span>
                        </article>
                    @endforeach
                </div>
            </section>
        @endforeach
    @endif

    @foreach ($linkGroups as $group => $items)
        <section class="group">
            <h2>{{ $group !== '' ? 'Links · '.$group : 'Links' }}</h2>
            <div class="tiles">
                @foreach ($items as $link)
                    <article class="tile clickable" data-href="{{ $link->url }}" tabindex="0" role="link" aria-label="Open {{ $link->name }}"
                             data-filter="{{ strtolower(implode(' ', [$link->name, $link->url, $link->group, $link->notes])) }}">
                        @include('partials.icon', ['model' => $link])
                        <div class="tile-body">
                            <span class="tile-name">{{ $link->name }}</span>
                            <small class="tile-sub">{{ $link->notes ?: $link->hostLabel() }}</small>
                        </div>
                    </article>
                @endforeach
            </div>
        </section>
    @endforeach

    <p id="no-match" class="card empty" hidden>Nothing matches your search.</p>
@endsection
