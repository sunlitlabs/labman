@extends('layouts.app')

@section('title', 'Dashboard')
{{-- Block form on purpose: an inline @section('x', '...') HTML-escapes its content. --}}
@section('body_attrs')data-status-url="{{ route('status', ['scope' => 'dashboard']) }}" data-interval="{{ \App\Support\Settings::interval() }}"@endsection

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

        $tabs = ['all' => ['All', array_sum($kinds)]];
        if ($canServices && $kinds['services'] > 0) { $tabs['services'] = ['Services', $kinds['services']]; }
        if ($canServers && $kinds['servers'] > 0) { $tabs['servers'] = ['Servers', $kinds['servers']]; }
        if ($kinds['links'] > 0) { $tabs['links'] = ['Links', $kinds['links']]; }

        $unknownDot = ['state' => 'unknown', 'title' => 'Not checked'];
        $worst = function (array $states) {
            foreach (['down', 'degraded', 'unknown', 'up'] as $s) { if (in_array($s, $states, true)) { return $s; } }
            return 'off';
        };
    @endphp

    <section class="hero hero-{{ $tone }}" id="hero" aria-live="polite">
        <div>
            <h1 id="headline">{{ $headline }}</h1>
            <p class="muted">Click a service or link to open it, or a server to copy its SSH command.</p>
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

    <div class="dashbar">
        <div class="filter">
            <label class="sr-only" for="filter">Search</label>
            <input id="filter" type="search" autocomplete="off" placeholder="Search everything…" spellcheck="false">
            <span class="kbd" aria-hidden="true">/</span>
        </div>
        @if (count($tabs) > 2)
            <div class="tabs" role="tablist" aria-label="What to show" data-tabs>
                @foreach ($tabs as $key => [$label, $n])
                    <button type="button" class="tab" role="tab" data-tab="{{ $key }}" aria-selected="{{ $loop->first ? 'true' : 'false' }}" tabindex="{{ $loop->first ? '0' : '-1' }}">{{ $label }} <span class="n" data-tab-count="{{ $key }}">{{ $n }}</span></button>
                @endforeach
            </div>
        @endif
        @if ($indicator)
            <div class="tabs viewswitch" aria-label="Arrange by">
                <a class="tab" href="{{ request()->fullUrlWithQuery(['view' => null]) }}" @if ($view === 'groups') aria-current="true" @endif>Groups</a>
                <a class="tab" href="{{ request()->fullUrlWithQuery(['view' => 'zones']) }}" @if ($view === 'zones') aria-current="true" @endif>Zones</a>
            </div>
        @endif
        <span id="conn" class="conn" hidden>Live updates paused: cannot reach the server.</span>
        @can('admin')
            <span class="dashbar-add">
                <a class="btn" href="{{ route('services.create') }}">Add service</a>
                <a class="btn" href="{{ route('servers.create') }}">Add server</a>
                <a class="btn" href="{{ route('links.create') }}">Add link</a>
            </span>
        @endcan
    </div>

    {{-- Hidden counters the live update keeps in step (the hero reads these). --}}
    <span hidden>
        @foreach ($summary as $kind => $kindCounts)
            @foreach ($kindCounts as $state => $n)<span data-count="{{ $kind }}:{{ $state }}">{{ $n }}</span>@endforeach
        @endforeach
    </span>

    @if ($empty)
        <div class="card empty">
            Nothing on the dashboard yet.
            @can('admin') <a href="{{ route('services.create') }}">Add a service</a>, <a href="{{ route('servers.create') }}">add a server</a>, <a href="{{ route('links.create') }}">add a link</a> or import a YAML file under Settings. Items you untick "Show on the dashboard" for stay out of here.@endcan
        </div>
    @endif

    <div class="board">
        @foreach ($cards as $card)
            <section class="group gcard">
                <h2>{{ $card['title'] }}<span class="count">{{ count($card['entries']) }}</span></h2>
                <div class="tiles">
                    @foreach ($card['entries'] as $entry)
                        @if ($entry['kind'] === 'services')
                            @php
                                $service = $entry['model'];
                                $open = $service->openUrl();
                                $st = \App\Monitoring\StatusView::of($service);
                                $bad = collect($st['checks'])->firstWhere('ok', false);
                                $dots = \App\Monitoring\Dots::service($st['checks'], $st['state']);
                                $alts = \App\Support\Alternates::of($service);
                                $zoneName = $indicator && $view !== 'zones' ? ($zones[$zoneOf['services'][$service->id] ?? 0]->name ?? null) : null;
                            @endphp
                            <article class="tile {{ $open ? 'clickable' : '' }}" data-kind="services" data-section="services" data-id="{{ $service->id }}" data-state="{{ $st['state'] }}"
                                     @if ($open) data-href="{{ $open }}" tabindex="0" role="link" aria-label="Open {{ $service->name }}" @endif
                                     data-filter="{{ strtolower(implode(' ', array_filter([$service->name, $service->domain, $service->public_domain, $service->backendAddress(), $service->group, $service->notes, $service->family, $zoneName, implode(' ', array_map(fn ($a) => $a['label'].' '.$a['address'].' '.$a['domain'], $alts)), 'service']))) }}">
                                @include('partials.icon', ['model' => $service])
                                <div class="tile-body">
                                    <span class="tile-name">{{ $service->name }}@if ($zoneName)<span class="zone-chip zone-c{{ ($zoneOf['services'][$service->id] ?? 0) % 8 }}">{{ $zoneName }}</span>@endif</span>
                                    <small class="tile-sub">@if ($service->domain || $service->public_domain)<span class="dot" data-dot="name" data-dot-state="{{ $dots['name']['state'] }}" title="{{ $dots['name']['title'] }}"></span>@else<span class="dot" data-dot="backend" data-dot-state="{{ $dots['backend']['state'] }}" title="{{ $dots['backend']['title'] }}"></span>@endif{{ $service->domain ?: $service->public_domain ?: ($service->backendAddress() ? \App\Support\Hosts::forUrl($service->backendAddress()).':'.$service->effectivePort() : 'no address') }}</small>
                                    <small class="tile-detail" data-detail>@if ($bad){{ $bad['label'] }} · {{ $bad['detail'] }}@endif</small>
                                    @if ($service->backendUrl() && ($service->domain || $service->public_domain))
                                        <a class="tile-ip mono" href="{{ $service->backendUrl() }}" target="_blank" rel="noopener noreferrer" title="Open the backend directly, bypassing the domain name"><span class="dot" data-dot="backend" data-dot-state="{{ $dots['backend']['state'] }}" title="{{ $dots['backend']['title'] }}"></span>{{ \App\Support\Hosts::forUrl($service->backendAddress()) }}:{{ $service->effectivePort() }}</a>
                                    @endif
                                    @if ($service->domain && $service->public_domain)
                                        <a class="tile-public" href="{{ $service->publicDomainUrl() }}" target="_blank" rel="noopener noreferrer" title="Open the public address"><span class="dot" data-dot="public" data-dot-state="{{ $dots['public']['state'] }}" title="{{ $dots['public']['title'] }}"></span>{{ $service->public_domain }}</a>
                                    @endif
                                    @foreach ($alts as $i => $alt)
                                        @php $ad = $dots['alt:'.$i] ?? $unknownDot; @endphp
                                        <a class="tile-alt" href="{{ $alt['domain'] ? 'https://'.$alt['domain'].$service->path : $service->scheme.'://'.\App\Support\Hosts::forUrl($alt['address']).':'.$service->effectivePort().$service->path }}" target="_blank" rel="noopener noreferrer" title="{{ $alt['address'] }}"><span class="dot" data-dot="alt:{{ $i }}" data-dot-state="{{ $ad['state'] }}" title="{{ $ad['title'] }}"></span><span class="alt-label">{{ $alt['label'] }}</span> {{ $alt['domain'] ?: $alt['address'] }}</a>
                                    @endforeach
                                </div>
                                <span data-pill class="pill pill-{{ $st['state'] }}{{ $st['stale'] && $st['state'] !== 'off' ? ' is-stale' : '' }}" title="{{ $st['checked_at'] ? 'Checked '.$st['checked_at'] : 'Not checked yet' }}">{{ \App\Monitoring\StatusView::label($st['state']) }}</span>
                            </article>
                        @elseif ($entry['kind'] === 'family')
                            @php
                                $members = $entry['members'];
                                $memberStates = array_map(fn ($m) => \App\Monitoring\StatusView::of($m)['state'], $members);
                                $familyState = $worst($memberStates);
                            @endphp
                            <article class="tile family" data-family data-section="services" data-state="{{ $familyState }}"
                                     data-filter="{{ strtolower(implode(' ', array_merge([$entry['name'], 'service'], array_map(fn ($m) => implode(' ', array_filter([$m->name, $m->domain, $m->backendAddress()])), $members)))) }}">
                                @include('partials.icon', ['model' => $members[0]])
                                <div class="tile-body">
                                    <span class="tile-name">{{ $entry['name'] }}<span class="fam-count">{{ count($members) }}</span></span>
                                    <div class="fam-members">
                                        @foreach ($members as $m)
                                            @php
                                                $ms = \App\Monitoring\StatusView::of($m);
                                                $short = trim(preg_replace('/^'.preg_quote($entry['name'], '/').'[\s:\-–—·]*/iu', '', $m->name));
                                            @endphp
                                            <a class="fam-member" href="{{ $m->openUrl() ?: '#' }}" target="_blank" rel="noopener noreferrer" data-kind="services" data-id="{{ $m->id }}" data-state="{{ $ms['state'] }}" title="{{ $m->name }}">
                                                <span data-pill class="pill pill-{{ $ms['state'] }}{{ $ms['stale'] && $ms['state'] !== 'off' ? ' is-stale' : '' }}" title="{{ $ms['checked_at'] ? 'Checked '.$ms['checked_at'] : 'Not checked yet' }}">{{ \App\Monitoring\StatusView::label($ms['state']) }}</span>{{ $short !== '' ? $short : $m->name }}
                                            </a>
                                        @endforeach
                                    </div>
                                </div>
                            </article>
                        @elseif ($entry['kind'] === 'servers')
                            @php
                                $server = $entry['model'];
                                $ssh = $server->sshCommand();
                                $st = \App\Monitoring\StatusView::of($server);
                                $bad = collect($st['checks'])->firstWhere('ok', false);
                                $dot = \App\Monitoring\Dots::server($st['checks'], $st['state']);
                                $alts = \App\Support\Alternates::of($server);
                                $zoneName = $indicator && $view !== 'zones' ? ($zones[$zoneOf['servers'][$server->id] ?? 0]->name ?? null) : null;
                            @endphp
                            <article class="tile clickable" tabindex="0" role="button" data-kind="servers" data-section="servers" data-id="{{ $server->id }}" data-state="{{ $st['state'] }}"
                                     data-ssh="{{ $ssh }}" aria-label="Copy SSH command for {{ $server->name }}: {{ $ssh }}"
                                     data-filter="{{ strtolower(implode(' ', array_filter([$server->name, $server->domain, $server->address, $server->group, $server->notes, $zoneName, implode(' ', array_map(fn ($a) => $a['label'].' '.$a['address'].' '.$a['domain'], $alts)), 'server']))) }}">
                                @include('partials.icon', ['model' => $server])
                                <div class="tile-body">
                                    <span class="tile-name">{{ $server->name }}@if ($zoneName)<span class="zone-chip zone-c{{ ($zoneOf['servers'][$server->id] ?? 0) % 8 }}">{{ $zoneName }}</span>@endif</span>
                                    <a class="tile-sub mono" href="{{ $server->addressUrl() }}" target="_blank" rel="noopener noreferrer" title="Open http://{{ $server->address }}"><span class="dot" data-dot="backend" data-dot-state="{{ $dot['backend']['state'] ?? 'unknown' }}" title="{{ $dot['backend']['title'] ?? 'Not checked' }}"></span>{{ $server->address }}</a>
                                    <small class="tile-detail" data-detail>@if ($bad){{ $bad['label'] }} · {{ $bad['detail'] }}@endif</small>
                                    @foreach ($alts as $i => $alt)
                                        @php $ad = $dot['alt:'.$i] ?? $unknownDot; @endphp
                                        <a class="tile-alt" href="{{ $alt['domain'] ? 'http://'.$alt['domain'] : 'http://'.\App\Support\Hosts::forUrl($alt['address']) }}" target="_blank" rel="noopener noreferrer" title="{{ $alt['address'] }}"><span class="dot" data-dot="alt:{{ $i }}" data-dot-state="{{ $ad['state'] }}" title="{{ $ad['title'] }}"></span><span class="alt-label">{{ $alt['label'] }}</span> {{ $alt['domain'] ?: $alt['address'] }}</a>
                                    @endforeach
                                </div>
                                <span data-pill class="pill pill-{{ $st['state'] }}{{ $st['stale'] && $st['state'] !== 'off' ? ' is-stale' : '' }}" title="{{ $st['checked_at'] ? 'Checked '.$st['checked_at'] : 'Not checked yet' }}">{{ \App\Monitoring\StatusView::label($st['state']) }}</span>
                            </article>
                        @else
                            @php $link = $entry['model']; @endphp
                            <article class="tile clickable" data-section="links" data-href="{{ $link->url }}" tabindex="0" role="link" aria-label="Open {{ $link->name }}"
                                     data-filter="{{ strtolower(implode(' ', array_filter([$link->name, $link->url, $link->group, $link->notes, 'link']))) }}">
                                @include('partials.icon', ['model' => $link])
                                <div class="tile-body">
                                    <span class="tile-name">{{ $link->name }}</span>
                                    <small class="tile-sub">{{ $link->notes ?: $link->hostLabel() }}</small>
                                </div>
                            </article>
                        @endif
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>

    <p id="no-match" class="card empty" hidden>Nothing matches your search.</p>
@endsection
