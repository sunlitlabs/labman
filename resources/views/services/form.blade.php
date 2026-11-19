@extends('layouts.app')

@section('title', $service->exists ? 'Edit service' : 'Add service')

@section('content')
    <div class="wide">
        <h1>{{ $service->exists ? 'Edit service' : 'Add service' }}</h1>

        <form class="card" method="POST" action="{{ $service->exists ? route('services.update', $service) : route('services.store') }}">
            @csrf
            @if ($service->exists) @method('PUT') @endif

            <div class="grid2">
                <div class="field">
                    <label for="name">Name</label>
                    <input id="name" name="name" type="text" required maxlength="100" value="{{ old('name', $service->name) }}">
                </div>
                <div class="field">
                    <label for="group">Group <span class="hint">(optional)</span></label>
                    <input id="group" name="group" type="text" maxlength="60" value="{{ old('group', $service->group) }}">
                </div>
                <div class="field">
                    <label for="domain">Public domain <span class="hint">(optional)</span></label>
                    <input id="domain" name="domain" type="text" maxlength="253" placeholder="wiki.example.com" value="{{ old('domain', $service->domain) }}">
                </div>
                <div class="field">
                    <label for="server_id">Runs on server</label>
                    <select id="server_id" name="server_id">
                        <option value="">— none, use an address —</option>
                        @foreach ($servers as $s)
                            <option value="{{ $s->id }}" @selected((string) old('server_id', $service->server_id) === (string) $s->id)>{{ $s->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="address">…or its own address <span class="hint">exactly one of the two</span></label>
                    <input id="address" name="address" type="text" maxlength="253" placeholder="192.0.2.1" value="{{ old('address', $service->address) }}">
                </div>
                <div class="field">
                    <label for="port">Port <span class="hint">(blank = 80, or 443 for https)</span></label>
                    <input id="port" name="port" type="number" min="1" max="65535" value="{{ old('port', $service->port) }}">
                </div>
                <div class="field">
                    <label for="scheme">Scheme</label>
                    <select id="scheme" name="scheme">
                        @foreach (['http', 'https'] as $scheme)
                            <option value="{{ $scheme }}" @selected(old('scheme', $service->scheme) === $scheme)>{{ $scheme }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="path">Path</label>
                    <input id="path" name="path" type="text" maxlength="500" value="{{ old('path', $service->path ?: '/') }}">
                </div>
                <div class="field">
                    <label for="expect">Healthy status <span class="hint">e.g. 200, 200-499, 401</span></label>
                    <input id="expect" name="expect" type="text" maxlength="12" value="{{ old('expect', $service->expect ?: '200-399') }}">
                </div>
                <div class="field">
                    <label for="notes">Notes <span class="hint">(optional)</span></label>
                    <input id="notes" name="notes" type="text" maxlength="500" value="{{ old('notes', $service->notes) }}">
                </div>
            </div>

            @foreach (['public_check' => 'Also check the public domain over HTTPS', 'verify_tls' => 'Verify the certificate of the public check', 'websockets' => 'Uses websockets (informational)'] as $flag => $text)
                <label class="check">
                    <input type="hidden" name="{{ $flag }}" value="0">
                    <input type="checkbox" name="{{ $flag }}" value="1" @checked((bool) old($flag, $service->{$flag}))>
                    {{ $text }}
                </label>
            @endforeach

            <div class="actions">
                <button class="primary" type="submit">Save</button>
                <a class="btn" href="{{ route('services.index') }}">Cancel</a>
            </div>
        </form>

        @if ($service->exists)
            <form class="card" method="POST" action="{{ route('services.destroy', $service) }}">
                @csrf @method('DELETE')
                <button class="btn-danger" type="submit" data-confirm>Delete this service</button>
            </form>
        @endif
    </div>
@endsection
