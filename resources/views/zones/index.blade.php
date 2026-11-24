@extends('layouts.admin')

@section('title', 'Zones')

@section('admin')
    <h1>Zones</h1>
    <p class="muted">A zone is a trust level that servers belong to, such as management, internal, LAN or external. Higher levels are more trusted. LabMan uses the level only to warn, never to block. A lab with one zone sees no change anywhere else in LabMan.</p>

    <div class="toolbar"><a class="btn primary" href="{{ route('zones.create') }}">Add zone</a></div>

    @if ($zones->count() === 1)
        <div class="card"><p class="muted">There is one zone, <strong>{{ $zones->first()->name }}</strong>, and every server is in it. Add a second zone to start separating servers; zone choices then appear on the server and service forms.</p></div>
    @endif

    <div class="rows">
        @foreach ($zones as $zone)
            @php($names = $zone->domainNames())
            <article class="row">
                <div class="cell name">
                    <span class="nm">{{ $zone->name }}</span>
                    @if ($zone->is_default)<span class="pill pill-up" title="Servers and services without a zone choice land here">default</span>@endif
                    <small>Level {{ $zone->level }}@if ($zone->notes) · {{ $zone->notes }}@endif</small>
                </div>
                <div class="cell">
                    <small>{{ $serverCounts[$zone->id] ?? 0 }} server(s)@if (($serviceCounts[$zone->id] ?? 0) > 0), {{ $serviceCounts[$zone->id] }} service override(s)@endif</small>
                </div>
                <div class="cell">
                    @forelse ($names as $name)<span class="dom">{{ $name }}</span>@empty<small class="muted">no domain</small>@endforelse
                </div>
                <div class="cell actions"><a href="{{ route('zones.edit', $zone) }}">Edit</a></div>
            </article>
        @endforeach
    </div>
@endsection
