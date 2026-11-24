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
