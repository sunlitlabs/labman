@extends('layouts.admin')

@section('title', $connection ? 'Edit NPM connection' : 'Connect NPM')

@section('admin')
    <p class="crumbs">
        <a href="{{ route('npm.index') }}">Nginx Proxy Manager</a> <span aria-hidden="true">/</span>
        <a href="{{ route('npm.proxy', $zone) }}">{{ $zones->count() > 1 ? $zone->name : 'Proxy' }}</a>
    </p>
    <h1>{{ $connection ? 'Edit connection' : 'Connect' }}{{ $zones->count() > 1 ? ' · '.$zone->name : '' }}</h1>

    <form class="card" id="connect" method="POST" action="{{ route('npm.connect', ['zone' => $zone->id]) }}">
        @csrf
        <div class="grid2">
            <div class="field">
                <label for="url">NPM address <span class="hint">paste any NPM page, e.g. http://192.0.2.5:81/nginx/proxy</span></label>
                <input id="url" name="url" type="text" required placeholder="http://192.0.2.5:81" value="{{ old('url', $connection['url'] ?? '') }}">
                @error('url') <p class="warn" role="alert">{{ $message }}</p> @enderror
            </div>
            <div class="field">
                <label for="email">NPM e-mail</label>
                <input id="email" name="email" type="text" required autocomplete="off" value="{{ old('email', $connection['email'] ?? '') }}">
            </div>
            <div class="field">
                <label for="password">NPM password @if ($connection)<span class="hint">enter it again to save changes; the saved one is never shown</span>@endif</label>
                <input id="password" name="password" type="password" required autocomplete="new-password">
            </div>
        </div>
        <label class="check">
            <input type="hidden" name="verify_tls" value="0">
            <input type="checkbox" name="verify_tls" value="1" @checked(old('verify_tls', $connection['verify_tls'] ?? true))>
            Verify the TLS certificate (untick for a self-signed https address)
        </label>
        <p class="hint">
            NPM has an API, but no permanent API keys: it hands out short-lived tokens in exchange for an account's e-mail and password,
            so LabMan keeps that login (encrypted) and signs in each time. Create a dedicated NPM user for LabMan with permission to
            manage proxy hosts only. Accounts with two-factor authentication are not supported.
        </p>
        <div class="savebar">
            <button class="primary" type="submit">Test and save</button>
            <a class="btn" href="{{ $connection ? route('npm.proxy', $zone) : route('npm.index') }}">Cancel</a>
        </div>
    </form>

    @if ($connection)
        <form class="card" method="POST" action="{{ route('npm.disconnect', ['zone' => $zone->id]) }}">
            @csrf @method('DELETE')
            <h2>Disconnect</h2>
            <p class="muted">Deletes the saved login from LabMan. Nothing in NPM is changed.</p>
            <button class="btn-danger" type="submit" data-confirm>Disconnect</button>
        </form>
    @endif
@endsection
