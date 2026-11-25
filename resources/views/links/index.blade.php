@extends('layouts.app')

@section('title', 'Links')

@section('content')
    <h1>Links</h1>
    <p class="muted">Bookmarks for things that are not services or servers, like a network controller or a cloud dashboard. They are never health-checked.</p>

    @can('admin')
        <div class="toolbar"><a class="btn primary" href="{{ route('links.create') }}">Add link</a></div>
    @endcan

    @if ($links->isEmpty())
        <div class="card empty">
            No links yet.
            @can('admin') <a href="{{ route('links.create') }}">Add the first one</a>.@endcan
        </div>
    @else
        @foreach ($grouped as $group => $items)
            <section class="group">
                <h2>{{ $group !== '' ? $group : 'Ungrouped' }}</h2>
                <div class="rows">
                    @foreach ($items as $link)
                        <article class="row">
                            <div class="cell name withicon">
                                @include('partials.icon', ['model' => $link])
                                <a class="nm" href="{{ $link->url }}" target="_blank" rel="noopener noreferrer">{{ $link->name }}</a>
                                @if ($link->notes)<small>{{ $link->notes }}</small>@endif
                            </div>
                            <div class="cell addr"><small class="mono">{{ $link->url }}</small></div>
                            <div class="cell">
                                @unless ($link->on_dashboard)<span class="pill pill-off" title="Kept here, not shown on the dashboard">not on dashboard</span>@endunless
                            </div>
                            <div class="cell actions">@can('admin')<a href="{{ route('links.edit', $link) }}">Edit</a>@endcan</div>
                        </article>
                    @endforeach
                </div>
            </section>
        @endforeach
    @endif
@endsection