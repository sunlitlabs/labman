@extends('layouts.admin')

@section('title', 'AdGuard Home')
@section('crumb', 'DNS plan')
@section('crumb_url', route('dns.index'))

@section('admin')
    <h1>AdGuard Home</h1>
    <p class="muted">
        Write the <strong>inside</strong> records of the <a href="{{ route('dns.index') }}">DNS plan</a> to AdGuard Home as DNS rewrites.
        You always see a preview first and choose what is written. LabMan adds and changes rewrites; it never deletes one.
        The public provider is not covered here.
    </p>

    @if ($errors->has('adguard'))
        <p class="warn" role="alert">{{ $errors->first('adguard') }}</p>
    @endif

    @if (session('adguard_results'))
        <div class="card">
            <h2>Result</h2>
            <ul class="results">
                @foreach (session('adguard_results') as $r)
                    <li class="{{ $r['outcome'] === 'failed' ? 'bad' : 'ok' }}"><strong>{{ $r['title'] }}</strong>: {{ $r['message'] }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($connection)
        <div class="card">
            <h2>Connected</h2>
            <p>
                <span class="mono">{{ $connection['url'] }}</span>@if ($connection['username'] !== '') as <span class="mono">{{ $connection['username'] }}</span>@endif
                @unless ($connection['verify_tls']) <span class="muted">(TLS certificate not verified)</span> @endunless
            </p>
            @if ($error)
                <p class="warn" role="alert">{{ $error }}</p>
            @else
                <p class="muted">AdGuard Home {{ $version }} answered.</p>
            @endif
            <div class="toolbar">
                @unless ($error)
                    <a class="btn primary" href="{{ route('dns.adguard.preview') }}">Preview what would be written</a>
                @endunless
                <form method="POST" action="{{ route('dns.adguard.disconnect') }}">
                    @csrf @method('DELETE')
                    <button class="btn" type="submit">Disconnect</button>
                </form>
            </div>
        </div>
    @endif

    <form class="card" id="connect" method="POST" action="{{ route('dns.adguard.connect') }}">
        @csrf
        <h2>{{ $connection ? 'Change connection' : 'Connect' }}</h2>
        <div class="grid2">
            <div class="field">
                <label for="url">AdGuard Home address <span class="hint">the address of its web page, e.g. http://192.0.2.53:3000</span></label>
                <input id="url" name="url" type="text" required placeholder="http://192.0.2.53:3000" value="{{ old('url', $connection['url'] ?? '') }}">
                @error('url') <p class="warn" role="alert">{{ $message }}</p> @enderror
            </div>
            <div class="field">
                <label for="username">User name <span class="hint">leave empty if AdGuard Home has no login</span></label>
                <input id="username" name="username" type="text" autocomplete="off" value="{{ old('username', $connection['username'] ?? '') }}">
            </div>
            <div class="field">
                <label for="password">Password</label>
                <input id="password" name="password" type="password" autocomplete="new-password">
            </div>
        </div>
        <label class="check">
            <input type="hidden" name="verify_tls" value="0">
            <input type="checkbox" name="verify_tls" value="1" @checked(old('verify_tls', $connection['verify_tls'] ?? true))>
            Verify the TLS certificate (untick for a self-signed https address)
        </label>
        <p class="hint">The login is stored encrypted and is never shown again, exported or returned by the MCP tools.</p>
        <button class="primary" type="submit">Test and save</button>
    </form>
@endsection
