@extends('layouts.admin')

@section('title', 'Zones')

@section('admin')
    <h1>Zones</h1>
    <p class="muted">A zone is a trust level that servers belong to, such as management, internal, LAN or external. Higher levels are more trusted. LabMan uses the level only to warn, never to block. A lab with one zone sees no change anywhere else in LabMan.</p>

    <div class="toolbar">
        <a class="btn primary" href="{{ route('zones.wizard') }}">{{ $multiple ? 'Setup wizard' : 'Set up zones' }}</a>
        <a class="btn" href="{{ route('zones.create') }}">Add zone</a>
        @if ($multiple)
            <a class="btn" href="{{ route('dns.index') }}">DNS plan</a>
            <a class="btn" href="{{ route('npm.mirrors') }}">Mirrors</a>
            <form method="POST" action="{{ route('zones.refresh') }}" class="inline-form">
                @csrf
                <button class="btn" type="submit" data-busy="Asking every proxy…">Refresh proxy status</button>
            </form>
        @endif
    </div>

    @unless ($multiple)
        <div class="card">
            <h2>One zone so far</h2>
            <p class="muted">Every server is in <strong>{{ $cards[0]['zone']->name }}</strong>. To separate servers by trust (and give each zone its own proxy, domain and certificates), the setup wizard walks you through it: name the zones, place the servers, connect each proxy, set the domains. Every step can be skipped and revisited.</p>
            <div class="actions"><a class="btn primary" href="{{ route('zones.wizard') }}">Start the setup wizard</a></div>
        </div>
    @endunless

    <div class="zone-grid">
        @foreach ($cards as $c)
            @php($z = $c['zone'])
            @php($st = $c['status'])
            <article class="card zone-card" @if ($z->color) style="border-top: 4px solid {{ $z->color }}" @endif>
                <header class="zone-card-head">
                    <h2><a href="{{ route('zones.show', $z) }}">{{ $z->name }}</a></h2>
                    @if ($z->is_default)<span class="pill pill-up" title="Servers and services without a zone choice land here">default</span>@endif
                    <small class="muted">Level {{ $z->level }}</small>
                </header>

                <p class="zone-line">
                    @if (! $c['connected'])
                        <span class="pill pill-unknown">no proxy</span>
                    @elseif ($st === null)
                        <span class="pill pill-unknown">not checked</span>
                    @elseif ($st['state'] === 'error')
                        <span class="pill pill-down">unreachable</span>
                    @else
                        <span class="pill pill-up">connected</span> <small>NPM {{ $st['version'] }} · {{ $st['hosts'] }} host(s)</small>
                    @endif
                    @if ($st)<small class="muted" title="{{ \Illuminate\Support\Carbon::parse($st['checked_at'])->toDayDateTimeString() }}">checked {{ \Illuminate\Support\Carbon::parse($st['checked_at'])->diffForHumans() }}</small>@endif
                </p>

                <p class="zone-line">
                    @forelse ($c['domains'] as $d)<span class="dom">{{ $d }}</span>@empty<small class="muted">no domain</small>@endforelse
                </p>

                <p class="zone-line">
                    <small>{{ $c['servers'] }} server(s) · {{ $c['services'] }} service(s)@if ($c['mirrors']) · mirrors {{ $c['mirrors'] }} zone(s) @endif</small>
                </p>

                @if ($st && $st['state'] === 'ok')
                    <p class="zone-line">
                        <small>
                            Certificates: {{ $st['cert_ok'] + $st['cert_expiring'] }} covered{{ $st['cert_expiring'] ? ', '.$st['cert_expiring'].' expiring' : '' }}{{ $st['cert_problems'] ? ', '.$st['cert_problems'].' to fix' : '' }}
                            · Drift: {{ $st['unknown_to_labman'] }} unknown to LabMan, {{ $st['to_push'] }} to send
                        </small>
                    </p>
                @endif

                @if ($c['issues'])
                    <ul class="zone-issues">
                        @foreach ($c['issues'] as $i)
                            <li>{{ $i['text'] }} <a href="{{ $i['url'] }}">{{ $i['label'] }}</a></li>
                        @endforeach
                    </ul>
                @else
                    <p class="muted zone-line"><small>Nothing needs attention.</small></p>
                @endif

                <div class="actions">
                    <a class="btn" href="{{ route('zones.show', $z) }}">Open</a>
                    <a class="btn" href="{{ route('zones.edit', $z) }}">Edit</a>
                    <a class="btn" href="{{ route('npm.proxy.edit', $z) }}">{{ $c['connected'] ? 'Edit proxy connection' : 'Connect a proxy' }}</a>
                </div>
            </article>
        @endforeach
    </div>
@endsection
