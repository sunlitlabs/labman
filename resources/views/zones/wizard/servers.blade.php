@php($next = route('zones.wizard', ['step' => 'proxies']))
@php($hasSuggestions = $suggestions->filter()->isNotEmpty())
<form method="POST" action="{{ route('zones.wizard.save', 'servers') }}">
    @csrf
    <section class="formcard">
        <h2>2. Place the servers</h2>
        <p class="sub">Choose the zone each server belongs to. A service follows its server's zone.@if ($hasSuggestions) The network blocks you gave suggest a zone for some servers: <em>Use the suggestions</em> applies them all.@endif</p>

        @if ($servers->isEmpty())
            <div class="card empty">There are no servers yet. <a href="{{ route('servers.create') }}">Add one</a>, then come back.</div>
        @else
            <div class="rows">
                <div class="row head" aria-hidden="true"><div>Server</div><div>Zone</div><div>Suggestion</div></div>
                @foreach ($servers as $s)
                    @php($sug = $suggestions[$s->id])
                    <article class="row">
                        <div class="cell name"><span class="nm">{{ $s->name }}</span><small class="mono">{{ $s->address }}</small></div>
                        <div class="cell">
                            <label class="sr-only" for="as-{{ $s->id }}">Zone of {{ $s->name }}</label>
                            <select id="as-{{ $s->id }}" name="assign[{{ $s->id }}]">
                                @foreach ($zones as $z)
                                    <option value="{{ $z->id }}" @selected((int) old('assign.'.$s->id, $s->zone_id) === $z->id)>{{ $z->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="cell">
                            @if ($sug && $sug->id !== (int) $s->zone_id)<span class="pill pill-degraded">fits {{ $sug->name }}</span>
                            @elseif ($sug)<span class="pill pill-up">matches</span>
                            @else<small class="muted">none</small>@endif
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </section>
    <div class="savebar">
        <button class="primary" type="submit">Save and continue</button>
        @if ($hasSuggestions)<button class="btn" type="submit" name="suggest" value="1">Use the suggestions</button>@endif
        <a class="btn" href="{{ $next }}">Skip this step</a>
        <a class="btn" href="{{ route('zones.wizard', ['step' => 'zones']) }}">Back</a>
    </div>
</form>
