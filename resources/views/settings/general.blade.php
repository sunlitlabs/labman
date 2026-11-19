@extends('layouts.admin')

@section('title', 'General settings')

@php($admin = auth()->user()?->isAdmin())

@section('admin')
    <h1>General</h1>
    <p class="muted">
        @if ($admin) The basics: how LabMan is named, how often it checks health, and what Copy SSH produces.
        @else You can view these settings but only administrators can change them. @endif
    </p>

    <form method="POST" action="{{ route('settings.update') }}">
        @csrf @method('PUT')
        <input type="hidden" name="section" value="general">

        <fieldset @disabled(! $admin) class="plain">
            <div class="formcard">
                <h2>Site</h2>
                <p class="sub">Shown in the header and the browser tab.</p>
                <div class="field">
                    <label for="title">Site title</label>
                    <input id="title" name="title" type="text" maxlength="60" required value="{{ old('title', $values['title']) }}">
                </div>
            </div>

            <div class="formcard">
                <h2>Health checks</h2>
                <p class="sub">The monitor checks every server and service on this schedule. A host that does not answer within the timeout counts as down.</p>
                <div class="grid2">
                    <div class="field">
                        <label for="check_interval">Check every <span class="hint">seconds, minimum 5</span></label>
                        <input id="check_interval" name="check_interval" type="number" step="any" min="5" required value="{{ old('check_interval', $values['check_interval']) }}">
                    </div>
                    <div class="field">
                        <label for="timeout">Give up after <span class="hint">seconds, 0.5 to 60</span></label>
                        <input id="timeout" name="timeout" type="number" step="any" min="0.5" max="60" required value="{{ old('timeout', $values['timeout']) }}">
                    </div>
                </div>
            </div>

            <div class="formcard">
                <h2>Domains</h2>
                <p class="sub">
                    If you use one domain at home and another from the internet, list them here (several allowed, separated by commas).
                    A service can then have a name under each; LabMan shows both, checks both, and tells NPM proxy hosts apart by which list
                    their name falls under. Leave both empty if you only use one domain.
                </p>
                <div class="grid2">
                    @foreach (['local' => ['Local domains', 'Names that resolve on your network.', 'home.example.com'], 'public' => ['Public domains', 'Names reachable from the internet.', 'example.org']] as $scope => [$title, $help, $example])
                        @php($list = \App\Support\Domains::parse(old($scope.'_domains', $values[$scope.'_domains'])))
                        <div class="field">
                            <label>{{ $title }} <span class="hint">{{ $help }} The marked one is the default.</span></label>
                            @include('partials.listedit', ['name' => $scope.'_domains', 'values' => $list, 'default' => old($scope.'_default', $list[0] ?? null), 'defaultName' => $scope.'_default', 'placeholder' => $example, 'addLabel' => 'Add '.$scope.' domain'])
                        </div>
                    @endforeach
                    @foreach (['npm_cert_local' => 'Certificate for local domains', 'npm_cert_public' => 'Certificate for public domains'] as $key => $label)
                        <div class="field">
                            <label for="{{ $key }}">{{ $label }} <span class="hint">used when LabMan creates a proxy host in NPM</span></label>
                            @if ($certificates !== null)
                                <select id="{{ $key }}" name="{{ $key }}">
                                    <option value="">none (no SSL)</option>
                                    @foreach ($certificates as $cert)
                                        <option value="{{ $cert['id'] }}" @selected((string) old($key, $values[$key]) === (string) $cert['id'])>{{ $cert['name'] }}{{ $cert['domains'] ? ' ('.implode(', ', array_slice($cert['domains'], 0, 2)).')' : '' }}</option>
                                    @endforeach
                                </select>
                            @else
                                <input id="{{ $key }}" name="{{ $key }}" type="number" min="0" placeholder="NPM certificate id (connect NPM to pick from a list)" value="{{ old($key, $values[$key]) }}">
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="formcard">
                <h2>Icons</h2>
                <p class="sub">Show an icon next to each service and server, matched from its name. Icons come from the public <a href="https://github.com/homarr-labs/dashboard-icons" rel="noopener noreferrer">Dashboard Icons</a> library (Apache-2.0; the logos belong to their respective owners). LabMan fetches each one once through its own server and caches it, so your browser never contacts anyone else. Switch this off to stop all fetching.</p>
                <label class="check">
                    <input type="hidden" name="icons" value="0">
                    <input type="checkbox" name="icons" value="1" @checked((bool) old('icons', $values['icons']))>
                    Show icons
                </label>
            </div>

            <div class="formcard">
                <h2>Copy SSH</h2>
                <p class="sub">What the Servers page copies when you click a server: <code>ssh [-p port] [user@]host</code>.</p>
                <div class="grid2">
                    <div class="field">
                        <label for="ssh_user">Default user <span class="hint">optional; a server can override it</span></label>
                        <input id="ssh_user" name="ssh_user" type="text" maxlength="32" autocomplete="off" value="{{ old('ssh_user', $values['ssh_user']) }}">
                    </div>
                    <div class="field">
                        <label for="ssh_target">Connect to the server's</label>
                        <select id="ssh_target" name="ssh_target">
                            <option value="address" @selected(old('ssh_target', $values['ssh_target']) === 'address')>address</option>
                            <option value="domain" @selected(old('ssh_target', $values['ssh_target']) === 'domain')>domain (when it has one)</option>
                        </select>
                    </div>
                </div>
            </div>
        </fieldset>

        @if ($admin)
            <div class="savebar"><button class="primary" type="submit">Save settings</button></div>
        @endif
    </form>
@endsection
