@extends('layouts.admin')

@section('title', $zone->exists ? 'Edit zone' : 'Add zone')

@section('admin')
    @php($d = $zone->exists ? $zone->domains() : ['local' => [], 'public' => []])
    <div class="wide">
        <h1>{{ $zone->exists ? 'Edit '.$zone->name : 'Add zone' }}</h1>

        <form method="POST" action="{{ $zone->exists ? route('zones.update', $zone) : route('zones.store') }}">
            @csrf
            @if ($zone->exists) @method('PUT') @endif

            <section class="formcard">
                <h2>The zone</h2>
                <div class="grid2">
                    <div class="field">
                        <label for="name">Name</label>
                        <input id="name" name="name" type="text" required maxlength="60" value="{{ old('name', $zone->name) }}">
                    </div>
                    <div class="field">
                        <label for="level">Level <span class="hint">0 to 100; higher is more trusted</span></label>
                        <input id="level" name="level" type="number" min="0" max="100" required value="{{ old('level', $zone->level) }}">
                    </div>
                    <div class="field">
                        <label for="color">Colour <span class="hint">optional, #rrggbb</span></label>
                        <input id="color" name="color" type="text" maxlength="7" placeholder="#2557d6" value="{{ old('color', $zone->color) }}">
                    </div>
                    <div class="field">
                        <label for="notes">Notes <span class="hint">optional</span></label>
                        <input id="notes" name="notes" type="text" maxlength="500" value="{{ old('notes', $zone->notes) }}">
                    </div>
                </div>
                <input type="hidden" name="icon" value="{{ old('icon', $zone->icon) }}">
                @if ($zone->is_default)
                    <p class="hint">This is the default zone: where servers go when no zone is chosen. To change it, make another zone the default.</p>
                @else
                    <label class="check">
                        <input type="checkbox" name="is_default" value="1" @checked(old('is_default'))>
                        <span>Make this the default zone <span class="hint">where servers go when no zone is chosen</span></span>
                    </label>
                @endif
            </section>

            <section class="formcard">
                <h2>Which servers belong here</h2>
                <p class="sub">Servers are placed on the server form. Network blocks only suggest a zone for a new server from its address.</p>
                <div class="field">
                    <label for="cidrs">Network blocks <span class="hint">optional, comma separated, e.g. 192.0.2.0/24</span></label>
                    <input id="cidrs" name="cidrs" type="text" maxlength="1000" placeholder="192.0.2.0/24, 198.51.100.0/24" value="{{ old('cidrs', implode(', ', (array) $zone->cidrs)) }}">
                </div>
            </section>

            <section class="formcard">
                <h2>Domains and proxy</h2>
                @if ($zone->is_default)
                    <p class="sub">The default zone's domains are the two lists on the <a href="{{ route('npm.index') }}">Nginx Proxy Manager</a> page: {{ implode(', ', $zone->domainNames()) ?: 'none set' }}.</p>
                @else
                    <div class="grid2">
                        <div class="field">
                            <label for="local_domains">Local domains <span class="hint">optional, comma separated, e.g. lab.example.com</span></label>
                            <input id="local_domains" name="local_domains" type="text" value="{{ old('local_domains', implode(', ', $d['local'])) }}">
                        </div>
                        <div class="field">
                            <label for="public_domains">Public domains <span class="hint">optional</span></label>
                            <input id="public_domains" name="public_domains" type="text" value="{{ old('public_domains', implode(', ', $d['public'])) }}">
                        </div>
                    </div>
                @endif
                <div class="field">
                    <label for="proxy_address">Proxy address <span class="hint">optional; what other zones' proxies forward to for this zone's hosts</span></label>
                    <input id="proxy_address" name="proxy_address" type="text" maxlength="253" placeholder="192.0.2.5" value="{{ old('proxy_address', $zone->proxy_address) }}">
                </div>
            </section>

            @php($dns = $zone->dnsSettings())
            <section class="formcard">
                <h2>DNS</h2>
                <p class="sub">Used by the <a href="{{ route('dns.index') }}">DNS plan</a> (LabMan lists the records; it does not write them).</p>
                <div class="field">
                    <label for="dns_public_address">Public address <span class="hint">optional; where the public DNS provider points this zone's public names (a CDN, tunnel or WAN address)</span></label>
                    <input id="dns_public_address" name="dns_public_address" type="text" maxlength="253" placeholder="203.0.113.10" value="{{ old('dns_public_address', $dns['public_address']) }}">
                </div>
                <label class="check">
                    <input type="checkbox" name="dns_split_horizon" value="1" @checked(old('dns_split_horizon', $dns['split_horizon']))>
                    <span>Also answer this zone's public names on the inside resolver, with its proxy <span class="hint">(split-horizon: people inside reach the proxy directly instead of going out and back)</span></span>
                </label>
            </section>

            @php($others = \App\Support\Zones::all()->reject(fn ($z) => $z->id === $zone->id))
            @if ($others->isNotEmpty())
                @php($sources = \App\Npm\Mirrors::sourcesOf($zone))
                <section class="formcard">
                    <h2>Mirrors</h2>
                    <p class="sub">Let this zone's proxy also answer for the hosts of another zone, forwarding to that zone's proxy by the same name. Off by default. The other zone needs a proxy address (what proxies forward to), and this zone needs a browser-trusted certificate for the name (a wildcard or a requested one). A mirror from a higher zone into a lower one is normal; a lower zone reaching into a higher one is allowed but warned about.</p>
                    @foreach ($others as $o)
                        <div class="grid2">
                            <label class="check">
                                <input type="checkbox" name="mirror_from[]" value="{{ $o->id }}" @checked(old('mirror_from') ? in_array((string) $o->id, array_map('strval', old('mirror_from')), true) : isset($sources[$o->id]))>
                                <span>Mirror the hosts of <strong>{{ $o->name }}</strong> <span class="hint">level {{ $o->level }}{{ $o->proxy_address ? ', proxy '.$o->proxy_address : ', no proxy address yet' }}</span></span>
                            </label>
                            <div class="field">
                                <label class="sr-only" for="mirror_upstream_{{ $o->id }}">Hop to {{ $o->name }}</label>
                                <select id="mirror_upstream_{{ $o->id }}" name="mirror_upstream[{{ $o->id }}]">
                                    @foreach (\App\Npm\Mirrors::UPSTREAM_LABELS as $value => $label)
                                        <option value="{{ $value }}" @selected(($sources[$o->id] ?? 'auto') === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    @endforeach
                </section>
            @endif

            @php($policy = $zone->certificatePolicy())
            <section class="formcard">
                <h2>Certificates</h2>
                <p class="sub">How this zone's proxy gets certificates. A certificate that already covers a name is always used before a new one is requested, and nothing is requested until you apply a previewed change.</p>
                <div class="field">
                    <label for="cert_strategy">For hosts with no certificate</label>
                    <select id="cert_strategy" name="cert_strategy">
                        @foreach (\App\Npm\CertificatePolicy::STRATEGY_LABELS as $value => $label)
                            <option value="{{ $value }}" @selected(old('cert_strategy', $policy->strategy) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <label class="check">
                    <input type="checkbox" name="cert_edge_managed" value="1" @checked(old('cert_edge_managed', $policy->edgeManaged))>
                    <span>This proxy sits behind a CDN or tunnel that terminates TLS publicly <span class="hint">its certificates are origin certificates: never requested from Let's Encrypt here, and never reused on a proxy that browsers connect to directly</span></span>
                </label>
                <div class="field">
                    <label for="cert_pins">Always use this certificate for a domain <span class="hint">one per line: <code>example.dev = 12</code> (domain = NPM certificate id)</span></label>
                    <textarea id="cert_pins" name="cert_pins" rows="3" spellcheck="false" placeholder="example.dev = 12">{{ old('cert_pins', $policy->pinText()) }}</textarea>
                </div>
                <div class="grid2">
                    <div class="field">
                        <label for="cert_origin">Origin certificates <span class="hint">ids of certificates here that browsers do not trust, comma separated</span></label>
                        <input id="cert_origin" name="cert_origin" type="text" maxlength="200" placeholder="12" value="{{ old('cert_origin', implode(', ', $policy->originIds)) }}">
                    </div>
                    <div class="field">
                        <label for="cert_email">Let's Encrypt e-mail <span class="hint">needed to request certificates</span></label>
                        <input id="cert_email" name="cert_email" type="email" maxlength="254" value="{{ old('cert_email', $policy->email) }}">
                    </div>
                    <div class="field">
                        <label for="cert_dns_template">DNS challenge: reuse the provider of certificate # <span class="hint">an existing DNS-challenge certificate in this NPM; LabMan never stores the provider's token</span></label>
                        <input id="cert_dns_template" name="cert_dns_template" type="number" min="1" value="{{ old('cert_dns_template', $policy->dnsTemplate) }}">
                    </div>
                </div>
                <label class="check">
                    <input type="checkbox" name="cert_wildcard" value="1" @checked(old('cert_wildcard', $policy->wildcard))>
                    <span>With the DNS challenge, request one wildcard certificate for the domain (so its other hosts are covered too)</span>
                </label>

                @if ($certificates !== null)
                    <h3>Certificates in this proxy</h3>
                    <div class="rows">
                        @forelse ($certificates as $c)
                            @php($left = \App\Npm\Certificates::daysLeft($c))
                            <article class="row">
                                <div class="cell name"><span class="nm">#{{ $c['id'] }} {{ $c['name'] }}</span><small>{{ $c['provider'] === 'letsencrypt' ? "Let's Encrypt" : 'custom' }}</small></div>
                                <div class="cell">@foreach ($c['domains'] as $d)<span class="dom">{{ $d }}</span>@endforeach</div>
                                <div class="cell">
                                    @if ($left === null)<small class="muted">no expiry given</small>
                                    @elseif ($left < 0)<span class="pill pill-down">expired</span>
                                    @elseif ($left < \App\Npm\Certificates::EXPIRY_WARNING_DAYS)<span class="pill pill-degraded">{{ $left }} day(s) left</span>
                                    @else<small>{{ $left }} days left</small>@endif
                                    @if (in_array($c['id'], $policy->originIds, true))<span class="pill pill-unknown">origin</span>@endif
                                </div>
                            </article>
                        @empty
                            <p class="muted">This proxy holds no certificates yet.</p>
                        @endforelse
                    </div>
                @elseif ($zone->exists)
                    <p class="hint">Connect this zone's proxy on the Nginx Proxy Manager page to see its certificates here.</p>
                @endif
            </section>

            <div class="savebar">
                <button class="primary" type="submit">Save</button>
                <a class="btn" href="{{ route('zones.index') }}">Cancel</a>
            </div>
        </form>

        @if ($zone->exists && ! $zone->is_default)
            <form class="card danger-zone" method="POST" action="{{ route('zones.destroy', $zone) }}">
                @csrf @method('DELETE')
                <p class="muted">A zone can be deleted once no server is in it.</p>
                <button class="btn-danger" type="submit" data-confirm>Delete this zone</button>
            </form>
        @endif
    </div>
@endsection
