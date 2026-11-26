@extends('layouts.admin')

@section('title', $node->exists ? 'Edit node' : 'Add node')
@section('crumb', 'Nodes')
@section('crumb_url', route('nodes.index'))

@section('admin')
    <h1>{{ $node->exists ? 'Edit '.$node->name : 'Add node' }}</h1>

    <form method="POST" action="{{ $node->exists ? route('nodes.update', $node) : route('nodes.store') }}">
        @csrf
        @if ($node->exists) @method('PUT') @endif

        <section class="formcard">
            <h2>The node</h2>
            <p class="sub">The other LabMan, running with <code>LABMAN_MODE=node</code>.</p>
            <div class="grid2">
                <div class="field">
                    <label for="name">Name</label>
                    <input id="name" name="name" type="text" required maxlength="100" value="{{ old('name', $node->name) }}" placeholder="Family zone dashboard">
                </div>
                <div class="field">
                    <label for="url">Address <span class="hint">where this LabMan can reach the node</span></label>
                    <input id="url" name="url" type="url" required maxlength="255" value="{{ old('url', $node->url) }}" placeholder="http://192.0.2.60:8080">
                </div>
            </div>
            <div class="field">
                <label for="token">Token <span class="hint">{{ $node->exists ? 'leave blank to keep the saved one' : 'copy this into the node as LABMAN_NODE_TOKEN' }}</span></label>
                <input id="token" name="token" type="text" autocomplete="off" @required(! $node->exists) minlength="16" maxlength="200" value="{{ old('token', $suggested) }}" class="mono">
                @if ($suggested)<small class="hint">It is stored encrypted and is not shown again.</small>@endif
            </div>
        </section>

        <section class="formcard">
            <h2>What it shows</h2>
            <div class="field">
                <label for="zone_id">Zone</label>
                <select id="zone_id" name="zone_id">
                    <option value="">Everything</option>
                    @foreach (\App\Support\Zones::all() as $z)
                        <option value="{{ $z->id }}" @selected((string) old('zone_id', $node->zone_id) === (string) $z->id)>{{ $z->name }}</option>
                    @endforeach
                </select>
                <small class="hint">The zone's servers and services (plus all links) are sent. Items you keep off the dashboard stay off it.</small>
            </div>
            <label class="check">
                <input type="hidden" name="enabled" value="0">
                <input type="checkbox" name="enabled" value="1" @checked((bool) old('enabled', $node->enabled))>
                <span>Keep it updated <span class="hint">(untick to pause pushes)</span></span>
            </label>
        </section>

        <p><button class="btn primary" type="submit">{{ $node->exists ? 'Save and send' : 'Add and send' }}</button> <a class="btn" href="{{ route('nodes.index') }}">Cancel</a></p>
    </form>
@endsection
