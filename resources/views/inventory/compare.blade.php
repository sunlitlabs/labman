@extends('layouts.admin')

@section('title', 'Compare inventory')

@php
    $labels = ['address' => 'Address', 'ssh_user' => 'SSH user', 'ssh_port' => 'SSH port', 'group' => 'Group'];
    $show = fn ($v) => ($v === null || $v === '') ? null : $v;
    $pick = fn (string $kind, string $key, string $default, ?string $field = null) => $field === null
        ? ($old[$kind][$key] ?? $default)
        : ($old[$kind][$key][$field] ?? $default);
    $total = count($plan['in_sync']) + count($plan['differences']) + count($plan['only_labman']) + count($plan['only_inventory']);
    $needsWork = count($plan['differences']) + count($plan['only_labman']) + count($plan['only_inventory']);
@endphp

@section('focus', '1')
@section('crumb', 'Inventory')
@section('crumb_url', route('inventory.index'))

@section('admin')
    <div class="toolbar">
        <h1>Compare LabMan and the inventory</h1>
        <a class="btn" href="{{ route('inventory.compare') }}" data-progress="{{ json_encode(\App\Inventory\CheckStatus::steps($plan['path'], $plan['server_count'])) }}">Pull latest and compare again</a>
        <a class="btn" href="{{ route('inventory.index') }}">Connection</a>
    </div>
    <p class="muted">
        <span class="mono">{{ $plan['path'] }}</span> on <span class="mono">{{ $plan['branch'] }}</span> at <span class="mono">{{ substr($plan['head'], 0, 7) }}</span>
        <span class="pill pill-{{ $plan['format'] === 'yaml' ? 'up' : 'degraded' }}">{{ strtoupper($plan['format']) }}</span>
        · {{ $plan['host_count'] }} host(s) in the inventory · {{ \App\Models\Server::count() }} server(s) in LabMan
    </p>

    <div class="summary inv-summary">
        <a class="pill pill-up" href="#in-sync">{{ count($plan['in_sync']) }} in sync</a>
        <a class="pill pill-degraded" href="#different">{{ count($plan['differences']) }} differ</a>
        <a class="pill pill-down" href="#only-labman">{{ count($plan['only_labman']) }} only in LabMan</a>
        <a class="pill pill-down" href="#only-inventory">{{ count($plan['only_inventory']) }} only in the inventory</a>
    </div>

    @if ($plan['format'] === 'ini')
        <div class="notice">
            <strong>This inventory is INI.</strong> LabMan reads it as it is. YAML is the better format to let a tool edit
            (typed values, nested groups, no quoting surprises), so when you apply changes to the inventory LabMan writes the
            same hosts as YAML to <span class="mono">{{ $plan['convert']['to'] }}</span>. Semaphore keeps using
            <span class="mono">{{ $plan['convert']['from'] }}</span> until you point its inventory at the new file.
            <label class="check"><input type="checkbox" name="convert" value="1" form="choices-form" @checked($old['convert'] ?? false)>
                Create <span class="mono">{{ $plan['convert']['to'] }}</span> now, even if no host changes</label>
            <label class="check"><input type="checkbox" name="delete_old" value="1" form="choices-form" @checked($old['delete_old'] ?? false)>
                Also delete <span class="mono">{{ $plan['convert']['from'] }}</span> in the same commit (only if you will update Semaphore right away; this creates the YAML file too)</label>
        </div>
    @endif

    @include('inventory.status', ['last' => $last])

    {{-- Always say what was done and found, including when nothing differs. --}}
    @php($checked = \Illuminate\Support\Carbon::parse($plan['checked_at']))
    <div class="card check-result {{ $needsWork === 0 ? 'inv-ok' : '' }}" role="status">
        <p class="check-line">
            <strong>Checked commit <span class="mono">{{ substr($plan['head'], 0, 7) }}</span> on <span class="mono">{{ $plan['branch'] }}</span> at <time datetime="{{ $checked->toIso8601String() }}" title="{{ $checked->toDayDateTimeString() }}">{{ $checked->format('H:i') }}</time>:</strong>
            {{ \App\Inventory\CheckStatus::counts(['in_sync' => count($plan['in_sync']), 'differ' => count($plan['differences']), 'only_labman' => count($plan['only_labman']), 'only_inventory' => count($plan['only_inventory'])]) }}.
        </p>
        <ul class="steps-done">
            <li>Connected to the repository and pulled the latest commit</li>
            <li>Read <span class="mono">{{ $plan['path'] }}</span> ({{ $plan['host_count'] }} {{ $plan['host_count'] === 1 ? 'host' : 'hosts' }})</li>
            <li>Compared {{ $plan['host_count'] }} {{ $plan['host_count'] === 1 ? 'host' : 'hosts' }} with {{ $plan['server_count'] }} {{ $plan['server_count'] === 1 ? 'server' : 'servers' }}</li>
        </ul>
        @if ($total === 0)
            <p class="check-verdict">Neither LabMan nor the inventory has any servers yet.</p>
        @elseif ($needsWork === 0)
            <p class="check-verdict">Everything lines up: all {{ count($plan['in_sync']) }} server(s) match the inventory. Nothing to do.</p>
        @else
            <p class="check-verdict">{{ $needsWork }} {{ $needsWork === 1 ? 'item needs' : 'items need' }} a decision below.</p>
        @endif
    </div>

    <form id="choices-form" method="POST" action="{{ route('inventory.review') }}">
        @csrf

        {{-- ============================================================ differ --}}
        @if ($plan['differences'])
            <section id="different" class="inv-section">
                <div class="inv-head">
                    <h2>Different <span class="count">{{ count($plan['differences']) }}</span></h2>
                    <div class="bulk">
                        Set all:
                        <button type="button" data-bulk="diff" data-value="labman">use LabMan's</button>
                        <button type="button" data-bulk="diff" data-value="inventory">use the inventory's</button>
                        <button type="button" data-bulk="diff" data-value="skip">skip</button>
                    </div>
                </div>
                <p class="muted">These servers exist on both sides but disagree. For each difference choose whose value wins, or skip it.</p>

                @foreach ($plan['differences'] as $item)
                    <div class="inv-item">
                        <div class="inv-title">
                            <strong>{{ $item['server'] }}</strong>
                            <span class="muted">is host</span> <span class="mono">{{ $item['host'] }}</span>
                        </div>
                        @foreach ($item['fields'] as $f)
                            <div class="diff-row">
                                <div class="diff-field">{{ $labels[$f['field']] }}</div>
                                <div class="diff-side"><small>LabMan</small> @if ($show($f['labman']) !== null) <span class="mono">{{ $f['labman'] }}</span> @else <span class="muted">(none)</span> @endif</div>
                                <div class="diff-side"><small>Inventory</small> @if ($show($f['inventory']) !== null) <span class="mono">{{ $f['inventory'] }}</span> @else <span class="muted">(none)</span> @endif</div>
                                <div class="seg" role="radiogroup" aria-label="{{ $labels[$f['field']] }} of {{ $item['server'] }}">
                                    @php($current = $pick('fields', $item['key'], 'skip', $f['field']))
                                    @if (in_array('labman', $f['options']))
                                        <label><input type="radio" data-group="diff" name="fields[{{ $item['key'] }}][{{ $f['field'] }}]" value="labman" @checked($current === 'labman')><span>Use LabMan's</span></label>
                                    @endif
                                    @if (in_array('inventory', $f['options']))
                                        <label><input type="radio" data-group="diff" name="fields[{{ $item['key'] }}][{{ $f['field'] }}]" value="inventory" @checked($current === 'inventory')><span>Use inventory's</span></label>
                                    @endif
                                    <label><input type="radio" data-group="diff" name="fields[{{ $item['key'] }}][{{ $f['field'] }}]" value="skip" @checked($current === 'skip')><span>Skip</span></label>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </section>
        @endif

        {{-- ====================================================== only in LabMan --}}
        @if ($plan['only_labman'])
            <section id="only-labman" class="inv-section">
                <div class="inv-head">
                    <h2>Only in LabMan <span class="count">{{ count($plan['only_labman']) }}</span></h2>
                    <div class="bulk">
                        Set all:
                        <button type="button" data-bulk="srv" data-value="add_inventory">add to inventory</button>
                        <button type="button" data-bulk="srv" data-value="leave">leave</button>
                    </div>
                </div>
                <p class="muted">LabMan knows these servers but the inventory has no matching host. Add them to the inventory, remove them from LabMan, or leave them.</p>

                @foreach ($plan['only_labman'] as $item)
                    @php($current = $pick('action', $item['key'], 'leave'))
                    <div class="inv-item">
                        <div class="inv-title">
                            <strong>{{ $item['name'] }}</strong>
                            <span class="mono">{{ $item['address'] }}</span>
                            @if ($item['group']) <span class="tag">{{ $item['group'] }}</span> @endif
                            @if ($item['ssh_user']) <span class="muted">user {{ $item['ssh_user'] }}</span> @endif
                            @if ($item['ssh_port'] !== 22) <span class="muted">port {{ $item['ssh_port'] }}</span> @endif
                        </div>
                        <div class="seg" role="radiogroup" aria-label="What to do with {{ $item['name'] }}">
                            <label><input type="radio" data-group="srv" name="action[{{ $item['key'] }}]" value="leave" @checked($current === 'leave')><span>Leave</span></label>
                            <label><input type="radio" data-group="srv" name="action[{{ $item['key'] }}]" value="add_inventory" @checked($current === 'add_inventory')><span>Add to inventory as <code>{{ $item['inventory_name'] }}</code></span></label>
                            <label class="seg-danger"><input type="radio" data-group="srv" name="action[{{ $item['key'] }}]" value="delete_labman" @checked($current === 'delete_labman')><span>Delete from LabMan</span></label>
                        </div>
                    </div>
                @endforeach
            </section>
        @endif

        {{-- ================================================= only in the inventory --}}
        @if ($plan['only_inventory'])
            <section id="only-inventory" class="inv-section">
                <div class="inv-head">
                    <h2>Only in the inventory <span class="count">{{ count($plan['only_inventory']) }}</span></h2>
                    <div class="bulk">
                        Set all:
                        <button type="button" data-bulk="host" data-value="add_labman">set up in LabMan</button>
                        <button type="button" data-bulk="host" data-value="leave">leave</button>
                    </div>
                </div>
                <p class="muted">The inventory has these hosts but LabMan does not know them. Set them up in LabMan (check the details first), remove them from the inventory, or leave them.</p>

                @foreach ($plan['only_inventory'] as $item)
                    @php($current = $pick('action', $item['key'], 'leave'))
                    @php($s = $item['suggested'])
                    <div class="inv-item">
                        <div class="inv-title">
                            <strong class="mono">{{ $item['host'] }}</strong>
                            @foreach ($item['groups'] as $g) <span class="tag">{{ $g }}</span> @endforeach
                        </div>
                        @foreach ($item['notes'] as $note) <p class="warn">{{ $note }}</p> @endforeach

                        <div class="seg" role="radiogroup" aria-label="What to do with host {{ $item['host'] }}">
                            <label><input type="radio" data-group="host" name="action[{{ $item['key'] }}]" value="leave" @checked($current === 'leave')><span>Leave</span></label>
                            <label><input type="radio" data-group="host" name="action[{{ $item['key'] }}]" value="add_labman" @checked($current === 'add_labman')><span>Set up in LabMan</span></label>
                            <label class="seg-danger"><input type="radio" data-group="host" name="action[{{ $item['key'] }}]" value="remove_inventory" @checked($current === 'remove_inventory')><span>Remove from inventory</span></label>

                            <div class="setup-fields">
                                <div class="field"><label>Name in LabMan</label><input type="text" name="setup[{{ $item['key'] }}][name]" maxlength="100" value="{{ $pick('setup', $item['key'], (string) $s['name'], 'name') }}"></div>
                                <div class="field"><label>Address</label><input type="text" name="setup[{{ $item['key'] }}][address]" maxlength="253" value="{{ $pick('setup', $item['key'], (string) $s['address'], 'address') }}"></div>
                                <div class="field"><label>Group</label><input type="text" name="setup[{{ $item['key'] }}][group]" maxlength="60" value="{{ $pick('setup', $item['key'], (string) ($s['group'] ?? ''), 'group') }}"></div>
                                <div class="field"><label>SSH user</label><input type="text" name="setup[{{ $item['key'] }}][ssh_user]" maxlength="32" value="{{ $pick('setup', $item['key'], (string) ($s['ssh_user'] ?? ''), 'ssh_user') }}"></div>
                                <div class="field"><label>SSH port</label><input type="text" inputmode="numeric" name="setup[{{ $item['key'] }}][ssh_port]" maxlength="5" value="{{ $pick('setup', $item['key'], (string) $s['ssh_port'], 'ssh_port') }}"></div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </section>
        @endif

        @if ($plan['ignored_patterns'])
            <p class="muted">Ignored: {{ implode(', ', $plan['ignored_patterns']) }} (range patterns that expand to many hosts; LabMan leaves them alone).</p>
        @endif

        <div class="stickybar">
            <span id="selected-count" class="muted">No changes selected</span>
            <button class="primary" type="submit" id="review-btn" @disabled($needsWork === 0 && ! $plan['convert'])>Review changes</button>
        </div>
    </form>

    {{-- ================================================================ in sync --}}
    <section id="in-sync" class="inv-section">
        <details>
            <summary><strong>In sync</strong> <span class="count">{{ count($plan['in_sync']) }}</span></summary>
            @if ($plan['in_sync'])
                <ul class="insync">
                    @foreach ($plan['in_sync'] as $row)
                        <li>{{ $row['server'] }} <span class="muted">↔</span> <span class="mono">{{ $row['host'] }}</span></li>
                    @endforeach
                </ul>
            @else
                <p class="muted">Nothing matches yet.</p>
            @endif
        </details>
    </section>
@endsection

@push('scripts')
    <script src="{{ asset('js/inventory.js') }}" defer></script>
@endpush
