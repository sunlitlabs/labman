@extends('layouts.admin')

@section('title', 'Nginx Proxy Manager')

@section('admin')
    <h1>Nginx Proxy Manager</h1>
    <p class="muted">
        Import proxy hosts as services, or push LabMan's services back to NPM. Every change is previewed first and
        nothing is written until you tick it. LabMan never deletes anything in NPM.
    </p>
    @unless ($connection)
        <p class="notice">Connect below. LabMan then walks you through matching your proxy hosts with your services, with sensible defaults already chosen.</p>
    @endunless

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
                <a class="btn btn-primary" href="{{ route('npm.wizard') }}">Match everything up (guided)</a>
                <a class="btn" href="{{ route('npm.import') }}">Preview import (NPM → LabMan)</a>
                <a class="btn" href="{{ route('npm.push') }}">Preview push (LabMan → NPM)</a>
                <a class="btn" href="{{ $connection['url'] }}" target="_blank" rel="noopener noreferrer">Open NPM</a>
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

    @if ($connection)
        <form class="formcard" method="POST" action="{{ route('npm.auto') }}">
            @csrf
            <h2>Keep NPM up to date</h2>
            <label class="check">
                <input type="checkbox" name="auto" value="1" @checked($auto) data-autosubmit-form>
                <span>Update NPM automatically when something changes here
                    <span class="hint">LabMan is the source of truth: when a service, its server's address, or a certificate below changes, the matching proxy host is updated (or created) in NPM. LabMan never deletes anything in NPM.</span></span>
            </label>
            <div class="savebar"><button class="primary" type="submit">Save</button></div>
        </form>

        @if ($last)
            <div class="card sync-status" role="status">
                <h2>Last automatic update <span class="pill pill-{{ $last['ok'] ? 'up' : 'down' }}">{{ $last['ok'] ? 'ok' : 'failed' }}</span></h2>
                <p class="muted">{{ \Illuminate\Support\Carbon::parse($last['at'])->diffForHumans() }} · {{ $last['applied'] }} proxy host(s) written</p>
                <ul class="results">
                    @foreach ($last['results'] as $r)
                        <li class="{{ $r['outcome'] === 'applied' ? 'ok' : 'bad' }}"><strong>{{ $r['title'] }}</strong>: {{ $r['message'] }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
    @endif

    <form class="formcard" method="POST" action="{{ route('npm.domains') }}">
        @csrf @method('PUT')
        <h2>Domains and certificates</h2>
        <p class="sub">
            If you use one domain at home and another from the internet, list them here (several allowed). A service can then have a name under each;
            LabMan shows and checks both, and each gets its own proxy host in NPM with the certificate you pick for that kind of domain.
            Changing a certificate here is sent to every proxy host of that kind. Leave both lists empty if you only use one domain.
        </p>
        <div class="grid2">
            @foreach (['local' => ['Local domains', 'Names that resolve on your network.', 'home.example.com'], 'public' => ['Public domains', 'Names reachable from the internet.', 'example.org']] as $scope => [$title, $help, $example])
                @php($list = \App\Support\Domains::parse(old($scope.'_domains', $values[$scope.'_domains'])))
                <div class="field">
                    <label>{{ $title }} <span class="hint">{{ $help }} The marked one is the default.</span></label>
                    @include('partials.listedit', ['name' => $scope.'_domains', 'values' => $list, 'default' => old($scope.'_default', $list[0] ?? null), 'defaultName' => $scope.'_default', 'placeholder' => $example, 'addLabel' => 'Add '.$scope.' domain'])
                </div>
            @endforeach
            @foreach (['npm_cert_local' => 'Certificate for local domains', 'npm_cert_public' => 'Certificate for public domains'] as $key => $label)
                <div class="field">
                    <label for="{{ $key }}">{{ $label }} <span class="hint">given to every proxy host of this kind</span></label>
                    @if ($certificates !== null)
                        <select id="{{ $key }}" name="{{ $key }}">
                            <option value="">none (leave NPM's setting alone)</option>
                            @foreach ($certificates as $cert)
                                <option value="{{ $cert['id'] }}" @selected((string) old($key, $values[$key]) === (string) $cert['id'])>{{ $cert['name'] }}{{ $cert['domains'] ? ' ('.implode(', ', array_slice($cert['domains'], 0, 2)).')' : '' }}</option>
                            @endforeach
                        </select>
                    @else
                        <input id="{{ $key }}" name="{{ $key }}" type="number" min="0" placeholder="NPM certificate id (connect NPM to pick from a list)" value="{{ old($key, $values[$key]) }}">
                    @endif
                </div>
            @endforeach
        </div>
        <div class="savebar"><button class="primary" type="submit">Save domains</button></div>
    </form>
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
