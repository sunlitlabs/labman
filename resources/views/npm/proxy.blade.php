@extends('layouts.admin')

@section('title', 'Nginx Proxy Manager')

@section('admin')
    <p class="crumbs"><a href="{{ route('npm.index') }}">&larr; All Nginx Proxy Managers</a></p>
    <h1>{{ $zones->count() > 1 ? $zone->name.' proxy' : 'Nginx Proxy Manager' }}</h1>
    <p class="muted">
        Import proxy hosts as services, or push LabMan's services back to NPM. Every change is previewed first and
        nothing is written until you tick it. LabMan never deletes anything in NPM.
    </p>
    @unless ($connection)
        <p class="notice">This zone has no proxy yet. <a href="{{ route('npm.proxy.edit', $zone) }}">Connect it</a> and LabMan walks you through matching your proxy hosts with your services, with sensible defaults already chosen.</p>
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
                <a class="btn" href="{{ route('npm.proxy.edit', $zone) }}">Edit connection</a>
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

@endsection
