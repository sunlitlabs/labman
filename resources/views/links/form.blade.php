@extends('layouts.admin')

@section('title', $link->exists ? 'Edit link' : 'Add link')

@section('admin')
    <div class="wide">
        <h1>{{ $link->exists ? 'Edit '.$link->name : 'Add link' }}</h1>

        <form method="POST" action="{{ $link->exists ? route('links.update', $link) : route('links.store') }}">
            @csrf
            @if ($link->exists) @method('PUT') @endif

            <section class="formcard">
                <h2>The link</h2>
                <p class="sub">Clicking it on the dashboard opens the address in a new tab.</p>
                <div class="grid2">
                    <div class="field">
                        <label for="name">Name</label>
                        <input id="name" name="name" type="text" required maxlength="100" value="{{ old('name', $link->name) }}">
                    </div>
                    <div class="field">
                        <label for="group">Group <span class="hint">optional heading</span></label>
                        <input id="group" name="group" type="text" maxlength="60" value="{{ old('group', $link->group) }}">
                    </div>
                    <div class="field">
                        <label for="url">Address</label>
                        <input id="url" name="url" type="url" required maxlength="2000" placeholder="https://controller.example.com" value="{{ old('url', $link->url) }}">
                    </div>
                    <div class="field">
                        <label for="notes">Notes <span class="hint">optional</span></label>
                        <input id="notes" name="notes" type="text" maxlength="500" value="{{ old('notes', $link->notes) }}">
                    </div>
                    <div class="field">
                        <label>Icon <span class="hint">matched from the name unless you pick one</span></label>
                        @include('partials.iconpick', ['model' => $link, 'value' => old('icon', $link->icon)])
                    </div>
                </div>
            </section>

            <div class="savebar">
                <button class="primary" type="submit">Save</button>
                <a class="btn" href="{{ route('links.index') }}">Cancel</a>
            </div>
        </form>

        @if ($link->exists)
            <form class="card danger-zone" method="POST" action="{{ route('links.destroy', $link) }}">
                @csrf @method('DELETE')
                <button class="btn-danger" type="submit" data-confirm>Delete this link</button>
            </form>
        @endif
    </div>
@endsection
