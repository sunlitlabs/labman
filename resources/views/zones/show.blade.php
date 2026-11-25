@extends('layouts.admin')

@section('title', $card['zone']->name.' zone')

@section('admin')
    @php($z = $card['zone'])
    @php($st = $card['status'])
    <h1>{{ $z->name }} <span class="muted">· level {{ $z->level }}@if ($z->is_default), default @endif</span></h1>
    <div class="toolbar">
        <a class="btn" href="{{ route('zones.index') }}">All zones</a>
        <a class="btn" href="{{ route('zones.edit', $z) }}">Edit zone</a>
        @if ($card['connected'])
            <a class="btn" href="{{ route('npm.proxy', $z) }}">Its proxy</a>
            <a class="btn" href="{{ route('npm.proxy.edit', $z) }}">Edit proxy connection</a>
            <form method="POST" action="{{ route('npm.disconnect', ['zone' => $z->id]) }}" class="inline-form">
                @csrf @method('DELETE')
                <button class="btn-danger" type="submit" data-confirm>Disconnect proxy</button>
            </form>
            <form method="POST" action="{{ route('zones.refresh.one', $z) }}" class="inline-form">
                @csrf
                <button class="btn" type="submit" data-busy="Asking the proxy…">Refresh status</button>
            </form>
        @endif
    </div>

    <section class="proxy-section" id="issues">
        <h2>Issues</h2>
        @if ($card['issues'])
            <ul class="zone-issues">
                @foreach ($card['issues'] as $i)
                    <li>{{ $i['text'] }} <a class="btn btn-small" href="{{ $i['url'] }}">{{ $i['label'] }}</a></li>
                @endforeach
            </ul>
        @else
            <div class="card inv-ok"><p class="check-verdict">Nothing needs attention.</p></div>
        @endif

        @if (! $card['connected'])
            <div class="card empty">
                <p>This zone has no proxy yet. Connect its Nginx Proxy Manager to preview and push hosts, see its certificates and check for drift.</p>
                <div class="actions"><a class="btn primary" href="{{ route('npm.proxy.edit', $z) }}">Connect a proxy</a></div>
            </div>
        @elseif ($st === null)
            <p class="muted">The proxy has not been checked yet. <em>Refresh status</em> asks it now.</p>
        @endif
    </section>

    <section class="proxy-section" id="servers">
        <h2>Servers <span class="count">{{ $servers->count() }}</span></h2>
        @if ($suggestions->isNotEmpty())
            <p class="muted">A network block of another zone matches these servers' addresses.</p>
        @endif
        @forelse ($servers as $s)
            <article class="row">
                <div class="cell name"><span class="nm">{{ $s->name }}</span><small class="mono">{{ $s->address }}</small></div>
                <div class="cell">
                    @if ($suggestions->has($s->id))
                        <small>Its address fits the <strong>{{ $suggestions[$s->id]->name }}</strong> zone.</small>
                        <form method="POST" action="{{ route('zones.assign') }}" class="inline-form">
                            @csrf
                            <input type="hidden" name="server_id" value="{{ $s->id }}">
                            <input type="hidden" name="zone_id" value="{{ $suggestions[$s->id]->id }}">
                            <button class="btn btn-small" type="submit">Move to {{ $suggestions[$s->id]->name }}</button>
                        </form>
                    @endif
                </div>
                <div class="cell actions">
                    @if ($others->isNotEmpty())
                        <form method="POST" action="{{ route('zones.assign') }}" class="inline-form">
                            @csrf
                            <input type="hidden" name="server_id" value="{{ $s->id }}">
                            <label class="sr-only" for="move-{{ $s->id }}">Move {{ $s->name }} to</label>
                            <select id="move-{{ $s->id }}" name="zone_id">
                                @foreach ($others as $o)<option value="{{ $o->id }}">{{ $o->name }}</option>@endforeach
                            </select>
                            <button class="btn btn-small" type="submit">Move</button>
                        </form>
                    @endif
                    <a href="{{ route('servers.edit', $s) }}">Edit</a>
                </div>
            </article>
        @empty
            <div class="card empty">No servers in this zone yet. Move one here from <a href="{{ route('servers.index') }}">the server list</a> (the Zone field on its form), or use the <a href="{{ route('zones.wizard', ['step' => 'servers']) }}">wizard's server step</a>.</div>
        @endforelse
    </section>

    <section class="proxy-section" id="services">
        <h2>Services <span class="count">{{ $services->count() }}</span></h2>
        @forelse ($services as $svc)
            <article class="row">
                <div class="cell name"><a class="nm" href="{{ route('services.edit', $svc) }}">{{ $svc->name }}</a><small>{{ $svc->domain ?: ($svc->public_domain ?: 'no name') }}{{ $svc->server ? ' · on '.$svc->server->name : '' }}</small></div>
                <div class="cell"><small>{{ $svc->zone_id ? 'zone set on the service' : 'follows its server' }}</small></div>
            </article>
        @empty
            <p class="muted">No services in this zone.</p>
        @endforelse
    </section>

    @if ($mirrorable->isNotEmpty())
        <section class="proxy-section" id="reach">
            <h2>Reach this zone from other zones</h2>
            <p class="muted">A mirror in another zone's proxy answers this zone's names by the same name, forwarding here. It is created only when you apply a previewed push.</p>
            @foreach ($mirrorable as $o)
                <form method="POST" action="{{ route('dns.propose') }}" class="inline-form">
                    @csrf
                    <input type="hidden" name="kind" value="zone">
                    <input type="hidden" name="target_zone" value="{{ $o->id }}">
                    <input type="hidden" name="source_zone" value="{{ $z->id }}">
                    <button class="btn" type="submit">Let {{ $o->name }} mirror {{ $z->name }}'s hosts</button>
                </form>
            @endforeach
        </section>
    @endif

    <section class="proxy-section" id="log">
        <h2>What changed</h2>
        @forelse ($log as $entry)
            <article class="row">
                <div class="cell name">
                    <span class="nm">{{ $entry->applied }} applied{{ $entry->failed ? ', '.$entry->failed.' failed' : '' }}</span>
                    <small>{{ ['preview' => 'from a preview', 'import' => 'import into LabMan', 'guided' => 'guided sync', 'automatic' => 'automatic update'][$entry->source] ?? $entry->source }}{{ $entry->who ? ' by '.$entry->who : '' }}</small>
                </div>
                <div class="cell"><small title="{{ $entry->created_at->toDayDateTimeString() }}">{{ $entry->created_at->diffForHumans() }}</small></div>
                <div class="cell">
                    <details><summary class="muted">Details</summary>
                        <ul class="results">
                            @foreach ($entry->results ?? [] as $r)
                                <li class="{{ $r['outcome'] === 'applied' ? 'ok' : 'bad' }}"><strong>{{ $r['title'] }}</strong>: {{ $r['message'] }}</li>
                            @endforeach
                        </ul>
                    </details>
                </div>
            </article>
        @empty
            <p class="muted">Nothing has been applied to this zone's proxy yet. When something is, it is listed here with the time and who applied it, so drift can be explained.</p>
        @endforelse
    </section>
@endsection
