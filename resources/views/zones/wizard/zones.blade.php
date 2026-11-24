@php($next = route('zones.wizard', ['step' => 'servers']))
<form method="POST" action="{{ route('zones.wizard.save', 'zones') }}">
    @csrf
    <section class="formcard">
        <h2>1. Name the zones</h2>
        <p class="sub">A zone is a trust level. Higher levels are more trusted (for example management 90, internal 70, LAN 40, external 10); LabMan uses them only to warn. Network blocks are optional: they suggest a zone for a server from its address in the next step.</p>

        <p>
            Start from a template:
            @foreach ($templates as $key => $t)
                <a class="btn btn-small {{ $template === $key ? 'primary' : '' }}" href="{{ route('zones.wizard', ['step' => 'zones', 'template' => $key]) }}">{{ $t['label'] }}</a>
            @endforeach
            @if ($template === 'four')<span class="hint">Your existing zone stays; rename it to fit one of these.</span>@endif
        </p>

        <div class="wizard-zones">
            <div class="wizard-zone head" aria-hidden="true"><span>Name</span><span>Level</span><span>Network blocks</span></div>
            @foreach ($zones as $z)
                <div class="wizard-zone">
                    <div class="field"><label class="sr-only" for="zn-{{ $z->id }}">Name of zone {{ $z->name }}</label><input id="zn-{{ $z->id }}" type="text" name="zones[{{ $z->id }}][name]" maxlength="60" required value="{{ old('zones.'.$z->id.'.name', $z->name) }}"></div>
                    <div class="field"><label class="sr-only" for="zl-{{ $z->id }}">Level of {{ $z->name }}</label><input id="zl-{{ $z->id }}" type="number" min="0" max="100" name="zones[{{ $z->id }}][level]" required value="{{ old('zones.'.$z->id.'.level', $z->level) }}"></div>
                    <div class="field"><label class="sr-only" for="zc-{{ $z->id }}">Network blocks of {{ $z->name }}</label><input id="zc-{{ $z->id }}" type="text" name="zones[{{ $z->id }}][cidrs]" placeholder="192.0.2.0/24, 198.51.100.0/24" value="{{ old('zones.'.$z->id.'.cidrs', implode(', ', (array) $z->cidrs)) }}"></div>
                </div>
            @endforeach
            @php($rows = old('new') ?: $extra)
            @foreach (array_pad(array_values($rows), count($rows) + 1, ['name' => '', 'level' => 50, 'cidrs' => '']) as $i => $r)
                <div class="wizard-zone is-new">
                    <div class="field"><label class="sr-only" for="nn-{{ $i }}">Name of a new zone</label><input id="nn-{{ $i }}" type="text" name="new[{{ $i }}][name]" maxlength="60" placeholder="New zone" value="{{ $r['name'] }}"></div>
                    <div class="field"><label class="sr-only" for="nl-{{ $i }}">Level of the new zone</label><input id="nl-{{ $i }}" type="number" min="0" max="100" name="new[{{ $i }}][level]" value="{{ $r['level'] }}"></div>
                    <div class="field"><label class="sr-only" for="nc-{{ $i }}">Network blocks of the new zone</label><input id="nc-{{ $i }}" type="text" name="new[{{ $i }}][cidrs]" placeholder="optional" value="{{ $r['cidrs'] }}"></div>
                </div>
            @endforeach
        </div>
        <p class="hint">Leave the last row empty to add nothing; save again to add more. Zones are never deleted here (use the zone's own page).</p>
    </section>
    <div class="savebar">
        <button class="primary" type="submit">Save and continue</button>
        <a class="btn" href="{{ $next }}">Skip this step</a>
    </div>
</form>
