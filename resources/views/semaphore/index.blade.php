@extends('layouts.admin')

@section('title', 'Semaphore')

@section('admin')
    <h1>Semaphore</h1>
    <p class="muted">
        The connection to Semaphore, and the host list it runs against. Your playbooks are on the
        <a href="{{ route('workbooks.index') }}">Workbooks</a> page.
    </p>

    @if ($error)
        <div class="errors" role="alert"><ul><li>{{ $error }}</li></ul></div>
    @endif

    @if ($connection)
        <div class="card">
            <div class="toolbar">
                <div>
                    <h2>Connected</h2>
                    <span class="mono">{{ $connection['url'] }}</span>
                    @unless ($connection['verify_tls']) <span class="muted">(TLS certificate not verified)</span> @endunless
                </div>
                <form method="POST" action="{{ route('semaphore.disconnect') }}">
                    @csrf @method('DELETE')
                    <button class="btn-danger" type="submit" data-confirm>Disconnect</button>
                </form>
            </div>
        </div>

        <form class="formcard" method="POST" action="{{ route('semaphore.inventory.save') }}">
            @csrf
            <h2>Host list</h2>
            <p class="sub">
                Semaphore runs playbooks against an inventory (its host list). Choose the one that holds your machines and LabMan will show which
                of your servers are in it, let you aim runs at them, and tell you when new hosts appear there.
            </p>

            @php($usable = collect($choices)->where('usable', true))
            @if ($choices === [])
                <p class="muted">No inventories were found in Semaphore.</p>
            @else
                <div class="field">
                    <label for="inventory">Inventory <span class="hint">static inventories only (kept inside Semaphore)</span></label>
                    <select id="inventory" name="inventory" required>
                        <option value="">— choose —</option>
                        @foreach ($choices as $c)
                            <option value="{{ $c['project'] }}:{{ $c['id'] }}" @selected($target && $target['project'] === $c['project'] && $target['inventory'] === $c['id']) @disabled(! $c['usable'])>
                                {{ $c['project_name'] }} / {{ $c['name'] }} ({{ $c['type'] ?: 'unknown' }}{{ $c['usable'] ? '' : ', not supported' }})
                            </option>
                        @endforeach
                    </select>
                    @error('inventory') <p class="warn">{{ $message }}</p> @enderror
                </div>

                <label class="check">
                    <input type="checkbox" name="auto" value="1" @checked($target && $target['auto'])>
                    <span>Update this inventory whenever my server list changes
                        <span class="hint">New servers are added, and address, user, port and group follow LabMan. Hosts that only exist in Semaphore are never removed. An INI inventory becomes YAML the first time LabMan writes to it.</span></span>
                </label>
                <div class="savebar">
                    <button class="primary" type="submit">Save</button>
                    @if ($target)
                        <button class="btn" type="submit" formaction="{{ route('semaphore.inventory.sync') }}" data-busy="Updating Semaphore…">Update Semaphore now</button>
                    @endif
                </div>
            @endif
        </form>

        @if ($target)
            <form method="POST" action="{{ route('semaphore.inventory.clear') }}" class="actions">
                @csrf @method('DELETE')
                <button class="btn" type="submit" data-confirm>Stop using this inventory</button>
            </form>

            @if ($last)
                <div class="card sync-status" role="status">
                    <h2>Last update of Semaphore <span class="pill pill-{{ $last['ok'] ? 'up' : 'down' }}">{{ $last['ok'] ? 'ok' : 'failed' }}</span></h2>
                    <p class="muted">{{ \Illuminate\Support\Carbon::parse($last['at'])->diffForHumans() }}</p>
                    @if ($last['ok'])
                        <p>{{ $last['added'] }} host(s) added, {{ $last['updated'] }} updated, {{ $last['removed'] }} removed.</p>
                    @else
                        <p class="warn">{{ $last['message'] }}</p>
                    @endif
                </div>
            @endif

            @if ($known)
                <div class="card">
                    <h2>What Semaphore has</h2>
                    <p class="muted">
                        {{ count(array_filter($known['hosts'], fn ($h) => ! $h['pattern'])) }} host(s), looked at {{ \Illuminate\Support\Carbon::parse($known['at'])->diffForHumans() }}
                        (LabMan looks again every ten minutes) · {{ $servers }} server(s) in LabMan.
                    </p>
                    @if ($strangers)
                        <p><strong>{{ count($strangers) }}</strong> host(s) are in Semaphore but not in LabMan: <span class="mono">{{ implode(', ', array_slice(array_keys($strangers), 0, 8)) }}</span>.
                            <a class="btn btn-primary" href="{{ route('semaphore.hosts') }}">Review them</a></p>
                    @else
                        <p>Every host in Semaphore is in your server list.</p>
                    @endif
                </div>
            @endif
        @endif
    @endif
    <form class="card" method="POST" action="{{ route('semaphore.connect') }}">
        @csrf
        <h2>{{ $connection ? 'Change connection' : 'Connect' }}</h2>
        <div class="grid2">
            <div class="field">
                <label for="url">Semaphore address <span class="hint">paste any Semaphore page, e.g. http://192.0.2.7:3000/project/1/templates</span></label>
                <input id="url" name="url" type="text" required placeholder="http://192.0.2.7:3000" value="{{ old('url', $connection['url'] ?? '') }}">
            </div>
            <div class="field">
                <label for="token">API token <span class="hint">{{ $connection ? 'leave blank to keep the saved one (same address only)' : 'create one in Semaphore under your user menu' }}</span></label>
                <input id="token" name="token" type="password" autocomplete="new-password" @required(! $connection)>
            </div>
        </div>
        <label class="check">
            <input type="hidden" name="verify_tls" value="0">
            <input type="checkbox" name="verify_tls" value="1" @checked(old('verify_tls', $connection['verify_tls'] ?? true))>
            Verify the TLS certificate (untick for a self-signed https address)
        </label>
        <p class="hint">The token acts as the Semaphore user who made it, so use a dedicated user that can only run the templates you want available here. It is stored encrypted.</p>
        <button class="primary" type="submit">Test and save</button>
    </form>
@endsection
