@extends('layouts.admin')

@section('title', 'Nginx Proxy Manager')

@section('admin')
    <h1>Nginx Proxy Manager</h1>
    <p class="muted">
        Import proxy hosts as services, or push LabMan's services back to NPM. Every change is previewed first and
        nothing is written until you tick it. LabMan never deletes anything in NPM.
        Each zone has its own proxy: open one to see its status, certificates and domains, or edit how LabMan connects to it.
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

    <div class="card proxies">
        <h2>Proxies <span class="hint">one per zone</span></h2>
        <div class="rows">
            @foreach ($proxies as $p)
                @php($z = $p['zone'])
                <article class="row">
                    <div class="cell name">
                        <a class="nm" href="{{ route('npm.proxy', $z) }}">{{ $z->name }}</a>
                        <small>Level {{ $z->level }}</small>
                    </div>
                    <div class="cell">
                        @if ($p['state'] === 'none')
                            <span class="pill pill-unknown">no proxy</span>
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
                    <div class="cell actions">
                        @if ($p['state'] === 'none')
                            <a class="btn primary" href="{{ route('npm.proxy.edit', $z) }}">Connect</a>
                        @else
                            <a class="btn" href="{{ route('npm.proxy', $z) }}">Open</a>
                            <a class="btn" href="{{ route('npm.proxy.edit', $z) }}">Edit</a>
                            <form method="POST" action="{{ route('npm.disconnect', ['zone' => $z->id]) }}" class="inline-form">
                                @csrf @method('DELETE')
                                <button class="btn-danger" type="submit" data-confirm>Disconnect</button>
                            </form>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>
        @if ($connected > 0)
            <div class="actions">
                <a class="btn" href="{{ route('npm.all', ['mode' => 'push']) }}" data-progress='["Connecting to each proxy","Reading their proxy hosts","Working out what would change in each"]'>Preview push to {{ $connected > 1 ? 'all proxies' : 'NPM' }}</a>
                <a class="btn" href="{{ route('npm.all', ['mode' => 'import']) }}" data-progress='["Connecting to each proxy","Reading their proxy hosts","Working out what would change in LabMan"]'>Preview import from {{ $connected > 1 ? 'all proxies' : 'NPM' }}</a>
                @if ($proxies && count($proxies) > 1)
                    <a class="btn" href="{{ route('npm.mirrors') }}">Mirrors</a>
                @endif
            </div>
        @else
            <p class="notice">Connect a proxy first. LabMan then walks you through matching your proxy hosts with your services, with sensible defaults already chosen.</p>
        @endif
    </div>

    @if ($connected > 0)
        <form class="formcard" method="POST" action="{{ route('npm.auto') }}">
            @csrf
            <h2>Keep NPM up to date</h2>
            <label class="check">
                <input type="checkbox" name="auto" value="1" @checked($auto) data-autosubmit-form>
                <span>Update NPM automatically when something changes here
                    <span class="hint">LabMan is the source of truth: when a service, its server's address, or a certificate changes, the matching proxy host is updated (or created) in the zone's NPM. LabMan never deletes anything in NPM.</span></span>
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
@endsection
