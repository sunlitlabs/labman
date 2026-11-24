@php($next = route('zones.wizard', ['step' => 'review']))
<form method="POST" action="{{ route('zones.wizard.save', 'domains') }}">
    @csrf
    <section class="formcard">
        <h2>4. Domains and certificates</h2>
        <p class="sub">A zone's hosts get names under its domains (<code>wiki.</code> plus a domain). Local domains resolve on your network; public ones are reachable from the internet. The certificate choice is what a host with no certificate gets: a certificate that already covers its name is always used first. Fine-tuning (pins, origin certificates, wildcards) is on each zone's own page.</p>

        @foreach ($zones as $z)
            @php($d = $z->domains())
            @php($dns = $z->dnsSettings())
            @php($policy = $z->certificatePolicy())
            <div class="card wizard-domain">
                <h3>{{ $z->name }}<small class="muted"> · level {{ $z->level }}</small></h3>
                <div class="grid2">
                    <div class="field">
                        <label for="ld-{{ $z->id }}">Local domains <span class="hint">comma separated, e.g. lab.example.com</span></label>
                        <input id="ld-{{ $z->id }}" type="text" name="z[{{ $z->id }}][local_domains]" value="{{ old('z.'.$z->id.'.local_domains', implode(', ', $d['local'])) }}">
                    </div>
                    <div class="field">
                        <label for="pd-{{ $z->id }}">Public domains <span class="hint">optional</span></label>
                        <input id="pd-{{ $z->id }}" type="text" name="z[{{ $z->id }}][public_domains]" value="{{ old('z.'.$z->id.'.public_domains', implode(', ', $d['public'])) }}">
                    </div>
                    <div class="field">
                        <label for="pa-{{ $z->id }}">Public address <span class="hint">where the public DNS points this zone's public names (optional)</span></label>
                        <input id="pa-{{ $z->id }}" type="text" name="z[{{ $z->id }}][public_address]" placeholder="203.0.113.10" value="{{ old('z.'.$z->id.'.public_address', $dns['public_address']) }}">
                    </div>
                    <div class="field">
                        <label for="cs-{{ $z->id }}">For hosts with no certificate</label>
                        <select id="cs-{{ $z->id }}" name="z[{{ $z->id }}][cert_strategy]">
                            @foreach (\App\Npm\CertificatePolicy::STRATEGY_LABELS as $value => $label)
                                <option value="{{ $value }}" @selected(old('z.'.$z->id.'.cert_strategy', $policy->strategy) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="ce-{{ $z->id }}">Let's Encrypt e-mail <span class="hint">needed only to request certificates</span></label>
                        <input id="ce-{{ $z->id }}" type="email" name="z[{{ $z->id }}][cert_email]" value="{{ old('z.'.$z->id.'.cert_email', $policy->email) }}">
                    </div>
                </div>
            </div>
        @endforeach
    </section>
    <div class="savebar">
        <button class="primary" type="submit">Save and continue</button>
        <a class="btn" href="{{ $next }}">Skip this step</a>
        <a class="btn" href="{{ route('zones.wizard', ['step' => 'proxies']) }}">Back</a>
    </div>
</form>
