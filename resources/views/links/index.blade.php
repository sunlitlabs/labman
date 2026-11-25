@extends('layouts.admin')

@section('title', 'Links')

@section('admin')
    <h1>Links</h1>
    <p class="muted">Bookmarks for things that are not services or servers, like a network controller or a cloud dashboard. They appear on the dashboard and are never health-checked.</p>

    <div class="toolbar"><a class="btn primary" href="{{ route('links.create') }}">Add link</a></div>

    @if ($links->isEmpty())
        <div class="card empty">No links yet. <a href="{{ route('links.create') }}">Add the first one</a>.</div>
    @else
        <div class="rows">
            @foreach ($links as $link)
                <article class="row">
                    <div class="cell name withicon">
                        @include('partials.icon', ['model' => $link])
                        <a class="nm" href="{{ $link->url }}" target="_blank" rel="noopener noreferrer">{{ $link->name }}</a>
                        @if ($link->group)<small>Group: {{ $link->group }}</small>@endif
                    </div>
                    <div class="cell addr"><small class="mono">{{ $link->url }}</small></div>
                    <div class="cell actions"><a href="{{ route('links.edit', $link) }}">Edit</a></div>
                </article>
            @endforeach
        </div>
    @endif
@endsection
