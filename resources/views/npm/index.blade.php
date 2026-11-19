@extends('layouts.admin')

@section('title', 'Nginx Proxy Manager')

@section('admin')
    <h1>Nginx Proxy Manager</h1>
    <p class="muted">
        Import proxy hosts as services, or push LabMan's services back to NPM. Every change is previewed first and
        nothing is written until you tick it. LabMan never deletes anything in NPM.
    </p>

    @if (session('npm_results'))
        <div class="card">
            <h2>Result</h2>
            <ul class="results">
                @foreach (session('npm_results') as $r)
                    <li class="{{ $r['outcome'] === 'applied' ? 'ok' : 'bad' }}"><strong>{{ $r['title'] }}</strong>: {{ $r['message'] }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($connection)
        <div class="card">
            <h2>Connected</h2>
            <p>
                <span class="mono">{{ $connection['url'] }}</span> as <span class="mono">{{ $connection['email'] }}</span>
                @unless ($connection['verify_tls']) <span class="muted">(TLS certificate not verified)</span> @endunless
            </p>
            <div class="actions">
                <a class="btn btn-primary" href="{{ route('npm.import') }}">Preview import (NPM → LabMan)</a>
                <a class="btn btn-primary" href="{{ route('npm.push') }}">Preview push (LabMan → NPM)</a>
                <form method="POST" action="{{ route('npm.disconnect') }}">
                    @csrf @method('DELETE')
                    <button class="btn-danger" type="submit" data-confirm>Disconnect</button>
                </form>
            </div>
            <p class="hint">
                Services are matched to proxy hosts by NPM host id (set on first import/push) or by their domain.
                The login is stored encrypted with this install's application key and is never shown, exported or sent to an MCP client.
            </p>
        </div>
    @endif

    <form class="card" method="POST" action="{{ route('npm.connect') }}">
        @csrf
        <h2>{{ $connection ? 'Change connection' : 'Connect' }}</h2>
        <div class="grid2">
            <div class="field">
                <label for="url">NPM address <span class="hint">paste any NPM page, e.g. http://192.0.2.5:81/nginx/proxy</span></label>
                <input id="url" name="url" type="text" required placeholder="http://192.0.2.5:81" value="{{ old('url', $connection['url'] ?? '') }}">
            </div>
            <div class="field">
                <label for="email">NPM e-mail</label>
                <input id="email" name="email" type="text" required autocomplete="off" value="{{ old('email', $connection['email'] ?? '') }}">
            </div>
            <div class="field">
                <label for="password">NPM password</label>
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
        <button class="primary" type="submit">Test and save</button>
    </form>
@endsection
