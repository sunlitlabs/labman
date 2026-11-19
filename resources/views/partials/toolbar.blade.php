{{-- $kind: services|servers, $summary: state counts, $label, $addRoute --}}
<div class="toolbar">
    <div class="filter">
        <label class="sr-only" for="filter">Filter {{ $kind }}</label>
        <input id="filter" type="search" autocomplete="off" placeholder="Filter {{ $kind }}…" spellcheck="false">
        <span class="kbd" aria-hidden="true">/</span>
    </div>
    <div class="summary" aria-label="Status summary">
        @foreach (['up', 'degraded', 'down', 'unknown'] as $state)
            <span class="pill pill-{{ $state }}"><span data-count="{{ $kind }}:{{ $state }}">{{ $summary[$state] }}</span> {{ $state }}</span>
        @endforeach
    </div>
    <span id="conn" class="conn" hidden>Live updates paused: cannot reach the server.</span>
    @can('admin')
        <a class="btn btn-primary" href="{{ route($kind.'.create') }}">Add {{ $label }}</a>
    @endcan
</div>
