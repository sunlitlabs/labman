@extends('layouts.app')

@section('title', 'Servers')
{{-- Block form on purpose: an inline @section('x', '...') HTML-escapes its content. --}}
@section('body_attrs')data-status-url="{{ route('status') }}" data-interval="{{ \App\Support\Settings::interval() }}"@endsection

@section('content')
    <h1>Servers</h1>
    <p class="muted">Click a server to copy its SSH command.</p>

    @include('partials.toolbar', ['kind' => 'servers', 'summary' => $summary, 'label' => 'server'])

    @if ($servers->isEmpty())
        <div class="card empty">
            No servers yet.
            @can('admin') <a href="{{ route('servers.create') }}">Add the first one</a> or import a YAML file under Settings.@endcan
        </div>
    @else
        @foreach ($grouped as $group => $items)
            <section class="group">
                <h2>{{ $group !== '' ? $group : 'Ungrouped' }}</h2>
                <div class="rows">
                    <div class="row head" aria-hidden="true">
                        <div>Status</div><div>Name</div><div>Domain</div><div>Address</div><div>Checks</div><div></div>
                    </div>
                    @foreach ($items as $server)
                        @php($ssh = $server->sshCommand())
                        <article class="row clickable" tabindex="0" role="button" data-kind="servers" data-id="{{ $server->id }}"
                                 data-ssh="{{ $ssh }}" aria-label="Copy SSH command for {{ $server->name }}: {{ $ssh }}"
                                 data-filter="{{ strtolower(implode(' ', [$server->name, $server->domain, $server->address, $server->group, $server->notes])) }}">
                            @include('partials.health', ['item' => $server])
                            <div class="cell name withicon">
                                @include('partials.icon', ['model' => $server])
                                <span class="nm">{{ $server->name }}</span>
                                @if ($server->notes)<small>{{ $server->notes }}</small>@endif
                            </div>
                            <div class="cell domain{{ $server->domain ? '' : ' is-empty' }}">{{ $server->domain ?: '—' }}</div>
                            <div class="cell addr">
                                <span class="mono">{{ $server->address }}</span>
                                <small class="mono">{{ $ssh }} <span class="ssh-hint">click to copy</span></small>
                            </div>
                            @include('partials.detail', ['item' => $server])
                            <div class="cell actions">
                                @can('admin')<a href="{{ route('servers.edit', $server) }}">Edit</a>@endcan
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>
        @endforeach
        <p id="no-match" class="card empty" hidden>Nothing matches your filter.</p>
    @endif
@endsection
