@extends('layouts.admin')

@section('title', 'DNS plan')

@section('admin')
    <h1>DNS plan</h1>
    <p class="muted">
        The records your zones need, so you can create them in your resolvers. LabMan lists them and never writes DNS.
        <strong>Inside</strong> is the resolver on your network; <strong>public</strong> is your DNS provider on the internet.
        A wildcard points each zone domain at the zone's proxy, each server gets a record at its own address, and a mirror's name is answered inside by the mirroring zone's proxy.
    </p>

    <div class="toolbar">
        @foreach ($formats as $key => $label)
            <a class="btn" href="{{ route('dns.export', ['format' => $key]) }}">{{ $label }}</a>
        @endforeach
        <a class="btn" href="{{ route('dns.adguard') }}">AdGuard Home</a>
    </div>

    @php($records = collect($plan['records']))
    @php($conflicts = collect($plan['conflicts']))
    <div class="card check-result {{ $open ? 'check-failed' : ($conflicts->isEmpty() ? 'inv-ok' : '') }}" role="status">
        <p class="check-line"><strong>{{ $records->count() }} record(s) planned</strong>: {{ $records->where('resolver', 'inside')->count() }} inside, {{ $records->where('resolver', 'public')->count() }} public.</p>
        <p class="check-verdict">{{ $open ? $open.' collision(s) need an owner before their records appear.' : ($conflicts->isEmpty() ? 'No conflicts.' : 'No collisions to settle; '.$conflicts->count().' note(s) below.') }}</p>
    </div>

    @if ($conflicts->isNotEmpty())
        <section class="proxy-section" id="conflicts">
            <h2>Conflicts and notes</h2>
            @foreach ($conflicts as $c)
                <div class="plan-item plan-{{ $c['severity'] === 'error' ? 'create' : 'unchanged' }}">
                    <div class="plan-head">
                        <strong class="mono">{{ $c['key'] }}</strong>
                        <span class="pill pill-{{ ['error' => 'down', 'warning' => 'degraded', 'resolved' => 'up'][$c['severity']] }}">{{ ['error' => 'needs an owner', 'warning' => 'check', 'resolved' => 'settled'][$c['severity']] }}</span>
                        <small class="muted">{{ str_replace('_', ' ', $c['kind']) }}</small>
                    </div>
                    <p class="{{ $c['severity'] === 'resolved' ? 'muted' : 'warn' }}">{{ $c['message'] }}</p>

                    @if (in_array($c['kind'], ['domain', 'name'], true))
                        <form class="inline-form" method="POST" action="{{ route('dns.owner') }}">
                            @csrf
                            <input type="hidden" name="kind" value="{{ $c['kind'] }}">
                            <input type="hidden" name="key" value="{{ $c['key'] }}">
                            <label for="owner-{{ $loop->index }}">Owner</label>
                            <select id="owner-{{ $loop->index }}" name="zone_id">
                                @foreach ($c['zone_ids'] as $id)
                                    <option value="{{ $id }}" @selected($c['owner'] === $id)>{{ $zones[$id]->name }}</option>
                                @endforeach
                            </select>
                            <button class="btn" type="submit">{{ $c['owner'] ? 'Change owner' : 'Pick owner' }}</button>
                        </form>

                        @if ($c['owner'])
                            {{-- With an owner picked, the other zone can still reach the owner's hosts: by mirroring them. --}}
                            @foreach (array_diff($c['zone_ids'], [$c['owner']]) as $other)
                                @php($service = $c['kind'] === 'name' ? \App\Models\Service::query()->where(fn ($q) => $q->whereRaw('lower(domain) = ?', [$c['key']])->orWhereRaw('lower(public_domain) = ?', [$c['key']]))->get()->first(fn ($s) => \App\Support\Zones::of($s)?->id === $c['owner']) : null)
                                <form class="inline-form" method="POST" action="{{ route('dns.propose') }}">
                                    @csrf
                                    <input type="hidden" name="target_zone" value="{{ $other }}">
                                    @if ($c['kind'] === 'domain')
                                        <input type="hidden" name="kind" value="zone">
                                        <input type="hidden" name="source_zone" value="{{ $c['owner'] }}">
                                        <button class="btn" type="submit">Let {{ $zones[$other]->name }} mirror {{ $zones[$c['owner']]->name }}'s hosts</button>
                                    @elseif ($service)
                                        <input type="hidden" name="kind" value="service">
                                        <input type="hidden" name="service_id" value="{{ $service->id }}">
                                        <button class="btn" type="submit">Also publish {{ $service->name }} in {{ $zones[$other]->name }}</button>
                                    @endif
                                </form>
                            @endforeach
                        @endif
                    @endif
                </div>
            @endforeach
        </section>
    @endif

    @foreach (['inside' => 'Inside resolver (your network)', 'public' => 'Public provider (the internet)'] as $resolver => $title)
        @php($rows = $records->where('resolver', $resolver))
        <section class="proxy-section" id="{{ $resolver }}">
            <h2>{{ $title }}</h2>
            @if ($rows->isEmpty())
                <p class="muted">{{ $resolver === 'public' ? 'No public names yet. List a public domain on a zone to plan its records.' : 'No inside records yet. Give a zone a local domain and a proxy address, or give servers a domain.' }}</p>
            @else
                <div class="rows">
                    <div class="row head" aria-hidden="true"><div>Name</div><div>Type</div><div>Answer</div><div>Zone</div></div>
                    @foreach ($rows as $r)
                        <article class="row">
                            <div class="cell name"><span class="nm mono">{{ $r['name'] }}</span><small>{{ $r['kind'] }}{{ $r['derived'] ? ' (suggested)' : '' }}{{ $r['note'] !== '' ? ' · '.$r['note'] : '' }}</small></div>
                            <div class="cell"><span class="mono">{{ $r['type'] }}</span></div>
                            <div class="cell">@if ($r['value'] !== null)<span class="mono">{{ $r['value'] }}</span>@else<span class="pill pill-degraded">set the public address</span>@endif</div>
                            <div class="cell"><small>{{ $r['zone'] }}</small></div>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>
    @endforeach
@endsection
