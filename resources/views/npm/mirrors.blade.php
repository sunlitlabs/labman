@extends('layouts.admin')

@section('title', 'Mirrors')

@section('focus', '1')
@section('crumb', 'Nginx Proxy Manager')
@section('crumb_url', route('npm.index'))

@section('admin')
    <h1>Mirrors</h1>
    <p class="muted">
        A mirror is an entry in one zone's proxy that forwards to another zone's proxy for a name that zone publishes, so people
        inside can use the same name without going out and back. Turn them on per zone (<strong>Admin &gt; Zones &gt; edit &gt; Mirrors</strong>)
        or per service (<strong>Also publish in</strong> on the service form). Nothing is created until you apply a previewed push.
        A mirror nothing asks for any more is listed here as orphaned. Preview a push for its proxy to delete it from NPM (only a mirror LabMan made, and only when you tick it), or forget it here if it is already gone.
    </p>

    @forelse ($sections as $s)
        @php($zone = $s['zone'])
        <section class="proxy-section">
            <div class="proxy-head">
                <h2>In the {{ $zone->name }} proxy</h2>
                @if ($s['connected'])
                    <a class="btn" href="{{ route('npm.push', ['zone' => $zone->id]) }}" data-progress='["Connecting to Nginx Proxy Manager","Reading its proxy hosts","Working out the mirrors to add or update"]'>Preview push for this proxy</a>
                @else
                    <span class="pill pill-unknown">no proxy connected</span>
                @endif
            </div>

            @if ($s['rows'])
                <div class="rows">
                    @foreach ($s['rows'] as $r)
                        @php($w = $r['wanted'])
                        <article class="row">
                            <div class="cell name"><span class="nm mono">{{ $w['domain'] }}</span><small>mirror of {{ $w['service']->name }}</small></div>
                            <div class="cell"><small>from the {{ $w['source']->name }} zone, {{ \App\Npm\Mirrors::UPSTREAM_LABELS[$w['upstream']] }}</small></div>
                            <div class="cell">
                                @if ($r['record'])<span class="pill pill-up">created</span> <small>host #{{ $r['record']->npm_host_id }}</small>
                                @else<span class="pill pill-degraded">not created yet</span>@endif
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif

            @if ($s['orphans']->isNotEmpty())
                <h3>Orphaned</h3>
                <p class="muted">Nothing asks for these any more. Delete them from NPM with a push preview, or forget one here if its proxy host is already gone.</p>
                <div class="rows">
                    @foreach ($s['orphans'] as $o)
                        <article class="row">
                            <div class="cell name"><span class="nm mono">{{ $o->domain }}</span><small>was a mirror of {{ $o->service_name }}</small></div>
                            <div class="cell"><small>proxy host {{ $o->npm_host_id ? '#'.$o->npm_host_id : 'unknown' }}</small></div>
                            <div class="cell actions">
                                <form method="POST" action="{{ route('npm.mirrors.forget', $o) }}">
                                    @csrf @method('DELETE')
                                    <button class="btn" type="submit" data-confirm>Forget (I removed it in NPM)</button>
                                </form>
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>
    @empty
        <div class="card empty">
            No mirrors are set up. Open a zone and tick the zones whose hosts it should mirror, or tick zones under <strong>Also publish in</strong> on a service.
            <a href="{{ route('zones.index') }}">Zones</a>
        </div>
    @endforelse
@endsection
