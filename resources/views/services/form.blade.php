@extends('layouts.app')

@section('title', $service->exists ? 'Edit service' : 'Add service')

@section('content')
    <div class="wide">
        <h1>{{ $service->exists ? 'Edit '.$service->name : 'Add service' }}</h1>

        <form method="POST" action="{{ $service->exists ? route('services.update', $service) : route('services.store') }}">
            @csrf
            @if ($service->exists) @method('PUT') @endif

            <section class="formcard">
                <h2>The service</h2>
                <p class="sub">What it is called and the address people use.</p>
                <div class="grid2">
                    <div class="field">
                        <label for="name">Name</label>
                        <input id="name" name="name" type="text" required maxlength="100" value="{{ old('name', $service->name) }}">
                    </div>
                    <div class="field">
                        <label for="group">Group <span class="hint">optional heading</span></label>
                        <input id="group" name="group" type="text" maxlength="60" value="{{ old('group', $service->group) }}">
                    </div>
                </div>
                @php($names = \App\Support\Domains::namesOf($service))
                @php($localDefault = \App\Support\Domains::defaultFor('local'))
                @php($publicDefault = \App\Support\Domains::defaultFor('public'))
                <div class="grid2">
                    <div class="field">
                        <label>{{ $localDefault || $publicDefault ? 'Local domains' : 'Domains' }} <span class="hint">optional; the first is primary and is what clicking the service opens</span></label>
                        @include('partials.listedit', ['name' => 'local_domains', 'values' => old('local_domains', $names['local']), 'placeholder' => 'wiki.'.($localDefault ?? 'example.com'), 'addLabel' => 'Add local domain', 'suffix' => $localDefault, 'slugFrom' => 'name'])
                    </div>
                    <div class="field">
                        <label>Public domains <span class="hint">optional; names reachable from the internet, each with its own NPM proxy host</span></label>
                        @include('partials.listedit', ['name' => 'public_domains', 'values' => old('public_domains', $names['public']), 'placeholder' => 'wiki.'.($publicDefault ?? 'example.org'), 'addLabel' => 'Add public domain', 'suffix' => $publicDefault, 'slugFrom' => 'name'])
                        @unless ($publicDefault || $localDefault)
                            <small class="hint">Set your domains on the Nginx Proxy Manager page to get a suggested name when you click Add.</small>
                        @endunless
                    </div>
                </div>
            </section>

            <section class="formcard">
                <h2>Where it runs</h2>
                <p class="sub">Pick a server, or give the service its own address (exactly one of the two).</p>
                <div class="grid2">
                    <div class="field">
                        <label for="server_id">Server</label>
                        <select id="server_id" name="server_id">
                            <option value="">none, use an address</option>
                            @foreach ($servers as $s)
                                <option value="{{ $s->id }}" @selected((string) old('server_id', $service->server_id) === (string) $s->id)>{{ $s->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    @if (\App\Support\Zones::multiple())
                        <div class="field">
                            <label for="zone_id">Zone <span class="hint">normally the server's zone</span></label>
                            <select id="zone_id" name="zone_id">
                                <option value="">same as the server</option>
                                @foreach (\App\Support\Zones::all() as $z)
                                    <option value="{{ $z->id }}" @selected((string) old('zone_id', $service->zone_id) === (string) $z->id)>{{ $z->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                    <div class="field">
                        <label for="address">Its own address</label>
                        <input id="address" name="address" type="text" maxlength="253" placeholder="192.0.2.1" value="{{ old('address', $service->address) }}">
                    </div>
                    <div class="field">
                        <label for="scheme">Scheme</label>
                        <select id="scheme" name="scheme">
                            @foreach (['http', 'https'] as $scheme)
                                <option value="{{ $scheme }}" @selected(old('scheme', $service->scheme) === $scheme)>{{ $scheme }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="port">Port <span class="hint">blank = 80, or 443 for https</span></label>
                        <input id="port" name="port" type="number" min="1" max="65535" value="{{ old('port', $service->port) }}">
                    </div>
                    <div class="field">
                        <label for="path">Path</label>
                        <input id="path" name="path" type="text" maxlength="500" value="{{ old('path', $service->path ?: '/') }}">
                    </div>
                </div>
            </section>

            <section class="formcard">
                <h2>Health check</h2>
                <p class="sub">A service shows as up when <strong>any</strong> check passes: the web request, an open port, or its public address.</p>
                <label class="check">
                    <input type="hidden" name="monitor" value="0">
                    <input type="checkbox" name="monitor" value="1" @checked((bool) old('monitor', $service->monitor))>
                    <span>Monitor this service <span class="hint">(untick to show "not monitored" instead of a status)</span></span>
                </label>
                <label class="check">
                    <input type="hidden" name="check_tcp" value="0">
                    <input type="checkbox" name="check_tcp" value="1" @checked((bool) old('check_tcp', $service->check_tcp))>
                    An open port counts as up, even if the web request fails
                </label>
                <label class="check">
                    <input type="hidden" name="public_check" value="0">
                    <input type="checkbox" name="public_check" value="1" @checked((bool) old('public_check', $service->public_check))>
                    Also check the public domain over HTTPS
                </label>
                <div class="grid2">
                    <div class="field">
                        <label for="health_path">Health path <span class="hint">if different from Path, e.g. /health</span></label>
                        <input id="health_path" name="health_path" type="text" maxlength="500" placeholder="/health" value="{{ old('health_path', $service->health_path) }}">
                    </div>
                    <div class="field">
                        <label for="expect">Healthy status <span class="hint">e.g. 200, 200-499, 200-399,401</span></label>
                        <input id="expect" name="expect" type="text" maxlength="60" value="{{ old('expect', $service->expect ?: '200-499') }}">
                    </div>
                </div>
                @if ($service->exists)
                    <a class="btn" href="{{ route('services.diagnose', $service) }}">Diagnose this health check…</a>
                @endif
            </section>

            <section class="formcard">
                <h2>Update</h2>
                <p class="sub">The Semaphore workbook that updates this service (for a Laravel app: pull the new code, install, migrate, rebuild, restart workers). An <strong>Update</strong> button then appears on the service, aimed at its server; you review the run before it starts.</p>
                @if ($workbooks)
                    <div class="field">
                        <label for="update_workbook">Update with</label>
                        <select id="update_workbook" name="update_workbook">
                            <option value="">none</option>
                            @foreach ($workbooks as $w)
                                <option value="{{ $w['project'] }}:{{ $w['template'] }}" @selected(old('update_workbook', $service->update_project.':'.$service->update_template) === $w['project'].':'.$w['template'])>{{ $w['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                @else
                    <p class="hint">Connect Semaphore (Admin > Semaphore) to pick a workbook here.</p>
                @endif
            </section>

            @if (\App\Support\Zones::multiple())
                @php($ownZone = $service->exists ? \App\Support\Zones::of($service) : null)
                @php($published = array_column(\App\Npm\Mirrors::refs($service->publish_zones), 'upstream', 'zone_id'))
                <section class="formcard">
                    <h2>Also publish in</h2>
                    <p class="sub">Other zones whose proxy should answer for this service's names too (a mirror that forwards to {{ $ownZone ? 'the '.$ownZone->name.' zone' : 'its own zone' }}'s proxy). Off unless ticked; a zone can also mirror a whole zone on its own page. Nothing changes in NPM until you apply a previewed push.</p>
                    <input type="hidden" name="publish_form" value="1">
                    @foreach (\App\Support\Zones::all()->reject(fn ($z) => $ownZone && $z->id === $ownZone->id) as $z)
                        <div class="grid2">
                            <label class="check">
                                <input type="checkbox" name="publish_in[]" value="{{ $z->id }}" @checked(old('publish_in') ? in_array((string) $z->id, array_map('strval', old('publish_in')), true) : isset($published[$z->id]))>
                                <span>{{ $z->name }} <span class="hint">level {{ $z->level }}</span></span>
                            </label>
                            <div class="field">
                                <label class="sr-only" for="publish_upstream_{{ $z->id }}">Hop for {{ $z->name }}</label>
                                <select id="publish_upstream_{{ $z->id }}" name="publish_upstream[{{ $z->id }}]">
                                    @foreach (\App\Npm\Mirrors::UPSTREAM_LABELS as $value => $label)
                                        <option value="{{ $value }}" @selected(($published[$z->id] ?? 'auto') === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    @endforeach
                </section>
            @endif

            <details class="formcard" @if (old('notes', $service->notes) || old('icon', $service->icon) || old('cert_mode', $service->cert_mode) || ! old('verify_tls', $service->verify_tls) || old('websockets', $service->websockets)) open @endif>
                <summary>More: notes, icon, certificate, TLS, websockets</summary>
                @php($certMode = (string) old('cert_mode', $service->cert_mode))
                <div class="grid2">
                    <div class="field">
                        <label for="cert_mode">Certificate on the proxy <span class="hint">normally the zone's policy decides</span></label>
                        <select id="cert_mode" name="cert_mode">
                            <option value="auto" @selected($certMode === '' || str_starts_with($certMode, 'existing:'))>Automatic (the zone's policy)</option>
                            <option value="none" @selected($certMode === 'none')>None (plain HTTP)</option>
                            <option value="letsencrypt_http" @selected($certMode === 'letsencrypt_http')>Let's Encrypt, HTTP challenge</option>
                            <option value="letsencrypt_dns" @selected($certMode === 'letsencrypt_dns')>Let's Encrypt, DNS challenge</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="cert_existing">Or a specific NPM certificate id <span class="hint">overrides the choice on the left</span></label>
                        <input id="cert_existing" name="cert_existing" type="number" min="1" value="{{ old('cert_existing', str_starts_with($certMode, 'existing:') ? substr($certMode, 9) : '') }}">
                    </div>
                </div>
                <div class="grid2">
                    <div class="field">
                        <label for="notes">Notes <span class="hint">optional, shown on the Services page</span></label>
                        <input id="notes" name="notes" type="text" maxlength="500" value="{{ old('notes', $service->notes) }}">
                    </div>
                    <div class="field">
                        <label>Icon <span class="hint">matched from the name unless you pick one</span></label>
                        @include('partials.iconpick', ['model' => $service, 'value' => old('icon', $service->icon)])
                    </div>
                </div>
                @foreach (['verify_tls' => 'Verify the certificate of the public check', 'websockets' => 'Uses websockets (informational; synced with NPM)'] as $flag => $text)
                    <label class="check">
                        <input type="hidden" name="{{ $flag }}" value="0">
                        <input type="checkbox" name="{{ $flag }}" value="1" @checked((bool) old($flag, $service->{$flag}))>
                        {{ $text }}
                    </label>
                @endforeach
            </details>

            <div class="savebar">
                <button class="primary" type="submit">Save</button>
                <a class="btn" href="{{ route('services.index') }}">Cancel</a>
            </div>
        </form>

        @if ($service->exists && $service->domain && \App\Npm\Connection::summary())
            <div class="formcard">
                <h2>Nginx Proxy Manager</h2>
                <p class="sub">
                    {{ $service->npm_host_id ? 'Linked to NPM proxy host #'.$service->npm_host_id.'.' : 'Not linked to a proxy host yet.' }}
                </p>
                <a class="btn" href="{{ route('npm.wizard', ['service' => $service->id]) }}">Compare with NPM…</a>
            </div>
        @endif

        @if ($service->exists)
            <form class="card danger-zone" method="POST" action="{{ route('services.destroy', $service) }}">
                @csrf @method('DELETE')
                <button class="btn-danger" type="submit" data-confirm>Delete this service</button>
            </form>
        @endif
    </div>
@endsection
