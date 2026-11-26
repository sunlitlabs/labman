@extends('layouts.admin')

@section('title', 'Nodes')

@section('admin')
    <h1>Nodes</h1>
    <p class="muted">
        A node is a small LabMan running in <strong>node mode</strong> inside a zone. This LabMan sends it the zone's services, servers and links with their current status
        (after every health-check round), and the node serves them as that zone's own dashboard, on its own domain if you give it one. A node has no sign-in or admin pages and runs no checks.
    </p>

    @if (session('node_error'))
        <p class="warn" role="alert">{{ session('node_error') }}</p>
    @endif

    <div class="toolbar"><a class="btn primary" href="{{ route('nodes.create') }}">Add node</a></div>

    @if ($nodes->isEmpty())
        <div class="card empty">
            No nodes yet. Run a second LabMan container with <code>LABMAN_MODE=node</code> and a <code>LABMAN_NODE_TOKEN</code>, then add it here (see the README, "Nodes").
        </div>
    @else
        <div class="rows">
            @foreach ($nodes as $node)
                <article class="row">
                    <div class="cell name">
                        <strong class="nm">{{ $node->name }}</strong>
                        <small class="mono">{{ $node->url }}</small>
                    </div>
                    <div class="cell">
                        <small>{{ $node->zone?->name ? 'Zone: '.$node->zone->name : 'Everything' }}@unless ($node->enabled) · paused @endunless</small>
                    </div>
                    <div class="cell">
                        @if ($node->pushed_at)
                            <span class="pill {{ $node->push_ok ? 'pill-up' : 'pill-down' }}" title="{{ $node->push_message }}">{{ $node->push_ok ? 'in step' : 'failed' }}</span>
                            <small class="muted" title="{{ $node->pushed_at }}">{{ $node->pushed_at->diffForHumans() }}</small>
                            @unless ($node->push_ok)<small class="field-error">{{ $node->push_message }}</small>@endunless
                        @else
                            <span class="pill pill-unknown">not sent yet</span>
                        @endif
                    </div>
                    <div class="cell actions">
                        <form method="POST" action="{{ route('nodes.push', $node) }}" class="inline">@csrf<button type="submit">Send now</button></form>
                        <a href="{{ route('nodes.edit', $node) }}">Edit</a>
                        <form method="POST" action="{{ route('nodes.destroy', $node) }}" class="inline">@csrf @method('DELETE')<button type="submit" class="danger" data-confirm="Remove node {{ $node->name }}?">Remove</button></form>
                    </div>
                </article>
            @endforeach
        </div>
    @endif
@endsection
