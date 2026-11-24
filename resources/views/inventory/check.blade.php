{{-- The result of the last "pull and compare", success or failure. $check from CheckStatus::last() --}}
@if ($check)
    @php($when = \Illuminate\Support\Carbon::parse($check['at']))
    <div class="card sync-status check-result {{ $check['ok'] ? '' : 'check-failed' }}" role="status">
        <h2>Last check
            <span class="pill pill-{{ $check['ok'] ? ($check['differ'] + $check['only_labman'] + $check['only_inventory'] === 0 ? 'up' : 'degraded') : 'down' }}">{{ $check['ok'] ? ($check['differ'] + $check['only_labman'] + $check['only_inventory'] === 0 ? 'in sync' : 'differences') : 'failed' }}</span>
        </h2>
        <p class="muted">
            <time datetime="{{ $when->toIso8601String() }}" title="{{ $when->toDayDateTimeString() }}">{{ $when->diffForHumans() }}</time>
            · <span class="mono">{{ $check['path'] }}</span> on <span class="mono">{{ $check['branch'] }}</span>
        </p>
        @if ($check['ok'])
            <p>{{ \App\Inventory\CheckStatus::sentence($check) }} <a href="{{ route('inventory.compare') }}">Pull and compare again</a></p>
        @else
            <p class="warn">{{ $check['message'] }}</p>
            <p class="hint">Nothing was changed in LabMan or in the repository.</p>
        @endif
    </div>
@endif
