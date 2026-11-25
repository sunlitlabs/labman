@php($next = route('zones.wizard', ['step' => 'domains']))
<section class="formcard">
    <h2>3. Connect each zone's proxy</h2>
    <p class="sub">Each zone can have its own Nginx Proxy Manager. A zone without one simply has no managed hosts; connect it later whenever you like. LabMan keeps each login encrypted and never shows it again.</p>

    @foreach ($zones as $z)
        @php($c = $connections[$z->id])
        @php($st = $statuses[$z->id])
        <div class="wizard-proxy card" id="proxy-{{ $z->id }}">
            <h3>{{ $z->name }}
                @if ($c)<span class="pill pill-up">connected</span>@else<span class="pill pill-unknown">no proxy yet</span>@endif
            </h3>
            @if ($c)
                <p class="muted"><span class="mono">{{ $c['url'] }}</span> as <span class="mono">{{ $c['email'] }}</span>
                    @if ($st && $st['state'] === 'error')<span class="warn"> · {{ $st['message'] }}</span>@endif
                    <a href="{{ route('npm.proxy', $z) }}">Open its proxy page</a></p>
            @else
                <p class="muted">This zone has no proxy yet. Connect one below, or skip it: nothing else depends on it.</p>
            @endif
            <details @if (! $c) open @endif>
                <summary>{{ $c ? 'Change the connection' : 'Connect a proxy' }}</summary>
                <form method="POST" action="{{ route('npm.connect', ['zone' => $z->id]) }}">
                    @csrf
                    <input type="hidden" name="return" value="wizard">
                    <div class="grid2">
                        <div class="field"><label for="url-{{ $z->id }}">NPM address</label><input id="url-{{ $z->id }}" name="url" type="text" required placeholder="http://192.0.2.5:81"></div>
                        <div class="field"><label for="em-{{ $z->id }}">NPM e-mail</label><input id="em-{{ $z->id }}" name="email" type="text" required autocomplete="off"></div>
                        <div class="field"><label for="pw-{{ $z->id }}">NPM password</label><input id="pw-{{ $z->id }}" name="password" type="password" required autocomplete="new-password"></div>
                    </div>
                    <label class="check"><input type="hidden" name="verify_tls" value="0"><input type="checkbox" name="verify_tls" value="1" checked> Verify the TLS certificate (untick for a self-signed https address)</label>
                    <button class="btn primary" type="submit" data-busy="Testing the login…">Test and save</button>
                </form>
            </details>
        </div>
    @endforeach
</section>
<div class="savebar">
    <a class="btn primary" href="{{ $next }}">Continue</a>
    <a class="btn" href="{{ route('zones.wizard', ['step' => 'servers']) }}">Back</a>
</div>
