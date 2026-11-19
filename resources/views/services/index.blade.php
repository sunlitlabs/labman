@extends('layouts.app')

@section('title', 'Services')
{{-- Block form on purpose: an inline @section('x', '...') HTML-escapes its content. --}}
@section('body_attrs')data-status-url="{{ route('status') }}" data-interval="{{ \App\Support\Settings::interval() }}"@endsection

@section('content')
    <h1>Services</h1>
    <p class="muted">Click a service to open it. Health refreshes automatically.</p>

    @include('partials.toolbar', ['kind' => 'services', 'summary' => $summary, 'label' => 'service'])

    @if ($services->isEmpty())
        <div class="card empty">
            No services yet.
            @can('admin') <a href="{{ route('services.create') }}">Add the first one</a> or import a YAML file under Settings.@endcan
        </div>
    @else
        @foreach ($grouped as $group => $items)
            <section class="group">
                <h2>{{ $group !== '' ? $group : 'Ungrouped' }}</h2>
                <div class="rows">
                    <div class="row head" aria-hidden="true">
                        <div>Status</div><div>Name</div><div>Domain</div><div>Backend</div><div>Checks</div><div></div>
                    </div>
                    @foreach ($items as $service)
                        @php($open = $service->openUrl())
                        @php($backend = $service->backendAddress() ? \App\Support\Hosts::forUrl($service->backendAddress()).':'.$service->effectivePort() : 'no address')
                        <article class="row {{ $open ? 'clickable' : '' }}" data-kind="services" data-id="{{ $service->id }}"
                                 @if ($open) data-href="{{ $open }}" @endif
                                 data-filter="{{ strtolower(implode(' ', [$service->name, $service->domain, $backend, $service->group, $service->notes])) }}">
                            @include('partials.health', ['item' => $service])
                            <div class="cell name withicon">
                                @include('partials.icon', ['model' => $service])
                                @if ($open)
                                    <a href="{{ $open }}" target="_blank" rel="noopener noreferrer">{{ $service->name }}</a>
                                @else
                                    <span class="nm">{{ $service->name }}</span>
                                @endif
                                @if ($service->notes)<small>{{ $service->notes }}</small>@endif
                            </div>
                            @php($names = \App\Support\Domains::namesOf($service))
                            <div class="cell domain{{ $names['local'] || $names['public'] ? '' : ' is-empty' }}">
                                @forelse ($names as $scope => $list)
                                    @foreach ($list as $name)
                                        <span class="dom">{{ $name }}@if ($names['local'] && $names['public']) <small class="scope scope-{{ $scope }}">{{ $scope }}</small>@endif</span>
                                    @endforeach
                                @empty
                                @endforelse
                                @if (! $names['local'] && ! $names['public']) — @endif
                            </div>
                            <div class="cell addr mono">{{ $backend }}</div>
                            @include('partials.detail', ['item' => $service])
                            <div class="cell actions">
                                @can('admin')<a href="{{ route('services.edit', $service) }}">Edit</a>@endcan
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>
        @endforeach
        <p id="no-match" class="card empty" hidden>Nothing matches your filter.</p>
    @endif
@endsection
