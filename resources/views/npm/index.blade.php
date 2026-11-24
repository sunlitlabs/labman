@extends('layouts.admin')

@section('title', 'Nginx Proxy Manager')

@section('admin')
    <h1>Nginx Proxy Manager</h1>
    <p class="muted">
        Import proxy hosts as services, or push LabMan's services back to NPM. Every change is previewed first and
        nothing is written until you tick it. LabMan never deletes anything in NPM.
    </p>
    @if ($zones->count() > 1)
        {{-- One proxy per zone: a status row for each, and the page below is for the zone picked here. --}}
        <div class="card proxies">
            <h2>Proxies <span class="hint">one per zone</span></h2>
            <div class="rows">
                @foreach ($proxies as $p)
                    @php($z = $p['zone'])
                    <article class="row {{ $z->id === $zone->id ? 'is-current' : '' }}">
                        <div class="cell name">
                            <a class="nm" href="{{ route('npm.index', ['zone' => $z->id]) }}" @if ($z->id === $zone->id) aria-current="true" @endif>{{ $z->name }}</a>
                            <small>Level {{ $z->level }}</small>
                        </div>
                        <div class="cell">
                            @if ($p['state'] === 'none')
                                <span class="pill pill-unknown">no proxy</span>
                                <small><a href="{{ route('npm.index', ['zone' => $z->id]) }}#connect">Connect one</a></small>
                            @elseif ($p['state'] === 'error')
                                <span class="pill pill-down">unreachable</span>
                                <small class="warn">{{ $p['message'] }}</small>
                            @else
                                <span class="pill pill-up">connected</span>
                                <small>NPM {{ $p['version'] }} · {{ $p['hosts'] }} host(s)</small>
                            @endif
                        </div>
                        <div class="cell">
                            @if ($p['state'] === 'ok')
                                <small>{{ $p['unknown_to_labman'] }} in NPM that LabMan does not know · {{ $p['to_push'] }} LabMan change(s) to send · certificates: {{ $p['cert_ok'] + $p['cert_expiring'] }} covered{{ $p['cert_expiring'] ? ', '.$p['cert_expiring'].' expiring' : '' }}{{ $p['cert_problems'] ? ', '.$p['cert_problems'].' to fix' : '' }}</small>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>
            <div class="actions">
                <a class="btn" href="{{ route('npm.all', ['mode' => 'push']) }}" data-progress='["Connecting to each proxy","Reading their proxy hosts","Working out what would change in each"]'>Preview push to all proxies</a>
                <a class="btn" href="{{ route('npm.mirrors') }}">Mirrors</a>
                <a class="btn" href="{{ route('npm.all', ['mode' => 'import']) }}" data-progress='["Connecting to each proxy","Reading their proxy hosts","Working out what would change in LabMan"]'>Preview import from all proxies</a>
            </div>
        </div>
        <h2>{{ $zone->name }}</h2>
    @endif

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
                <a class="btn btn-primary" href="{{ route('npm.wizard', $zp) }}" data-progress='["Connecting to Nginx Proxy Manager","Reading its proxy hosts and certificates","Matching them with your services and servers"]'>Match everything up (guided)</a>
                <a class="btn" href="{{ route('npm.import', $zp) }}" data-progress='["Connecting to Nginx Proxy Manager","Reading its proxy hosts","Working out what would change in LabMan"]'>Preview import (NPM → LabMan)</a>
                <a class="btn" href="{{ route('npm.push', $zp) }}" data-progress='["Connecting to Nginx Proxy Manager","Reading its proxy hosts","Working out what would change in Nginx Proxy Manager"]'>Preview push (LabMan → NPM)</a>
                <a class="btn" href="{{ $connection['url'] }}" target="_blank" rel="noopener noreferrer">Open NPM</a>
                <form method="POST" action="{{ route('npm.disconnect', $zp) }}">
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

    @if ($connection && $certReport)
        @php($cc = $certReport['counts'])
        @php($issues = array_filter($certReport['rows'], fn ($r) => ! in_array($r['state'], ['ok', 'none'], true)))
        <div class="card" id="certificates">
            <h2>Certificates
                <span class="pill pill-{{ $issues ? 'degraded' : 'up' }}">{{ $issues ? count($issues).' to look at' : 'all covered' }}</span>
            </h2>
            @php($broken = $cc['uncovered'] + $cc['expired'] + $cc['untrusted'] + $cc['unknown'])
            @php($summary = ($cc['ok'] + $cc['expiring']).' host name(s) have a valid certificate'.($cc['expiring'] ? ', '.$cc['expiring'].' expiring soon' : '').($cc['missing'] ? ', '.$cc['missing'].' have none' : '').($broken ? ', '.$broken.' have a certificate that does not work' : '').'.')
            <p class="muted">{{ $summary }}
                A certificate that already covers a name is used before a new one is requested; nothing is requested until you apply a previewed change.
                <a href="{{ \Illuminate\Support\Facades\Route::has('zones.edit') ? route('zones.edit', $zone) : '#' }}">Certificate settings of the {{ $zone->name }} zone</a></p>
            @if ($issues)
                <ul class="results">
                    @foreach ($issues as $r)
                        <li class="bad"><strong>{{ $r['domain'] }}</strong> <span class="muted">({{ $r['service'] }})</span>: {{ $r['detail'] }}</li>
                    @endforeach
                </ul>
                <div class="actions"><a class="btn" href="{{ route('npm.push', $zp) }}" data-progress='["Connecting to Nginx Proxy Manager","Reading its certificates and proxy hosts","Working out the fixes"]'>Preview the fixes</a></div>
            @endif
        </div>
    @endif

    @if ($connection)
        <form class="formcard" method="POST" action="{{ route('npm.auto', $zp) }}">
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

    @if ($zone->is_default)
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
    @else
    <div class="card">
        <h2>Domains and certificates</h2>
        <p class="sub">The {{ $zone->name }} zone's domains: {{ implode(', ', $zone->domainNames()) ?: 'none set' }}. Change them on the <a href="{{ route('zones.edit', $zone) }}">zone's page</a>. New proxy hosts here are created without a certificate until certificate policies are set per zone.</p>
    </div>
    @endif
    <form class="card" id="connect" method="POST" action="{{ route('npm.connect', $zp) }}">
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
