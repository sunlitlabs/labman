@php($issues = 0)
<section class="formcard">
    <h2>5. Review</h2>
    <p class="sub">This is what is set up. Anything missing can be done now or later; none of it blocks the rest of LabMan.</p>

    <div class="rows">
        <div class="row head" aria-hidden="true"><div>Zone</div><div>Servers</div><div>Proxy</div><div>Domains</div></div>
        @foreach ($zones as $z)
            <article class="row">
                <div class="cell name"><a class="nm" href="{{ route('zones.show', $z) }}">{{ $z->name }}</a><small>level {{ $z->level }}{{ $z->is_default ? ', default' : '' }}</small></div>
                <div class="cell"><small>{{ $serverCounts[$z->id] }} server(s)</small></div>
                <div class="cell">@if ($connections[$z->id])<span class="pill pill-up">connected</span>@else<span class="pill pill-unknown">none</span> <a href="{{ route('zones.wizard', ['step' => 'proxies']) }}#proxy-{{ $z->id }}">Connect</a>@endif</div>
                <div class="cell">@forelse ($z->domainNames() as $name)<span class="dom">{{ $name }}</span>@empty<small class="muted">none</small> <a href="{{ route('zones.wizard', ['step' => 'domains']) }}">Set</a>@endforelse</div>
            </article>
        @endforeach
    </div>

    <h3>Next</h3>
    <ul>
        <li><a href="{{ route('npm.all', ['mode' => 'push']) }}">Preview a push to every proxy</a> (nothing is written until you apply it)</li>
        <li><a href="{{ route('dns.index') }}">The DNS plan</a> lists the records these zones need</li>
        <li><a href="{{ route('npm.mirrors') }}">Mirrors</a> let one zone's proxy answer for another zone's hosts</li>
    </ul>
</section>
<form method="POST" action="{{ route('zones.wizard.save', 'review') }}">
    @csrf
    <div class="savebar">
        <button class="primary" type="submit">Finish</button>
        <a class="btn" href="{{ route('zones.wizard', ['step' => 'domains']) }}">Back</a>
    </div>
</form>
