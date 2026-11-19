{{-- The outcome of the last push to the inventory repository. $last from SyncStatus::last() --}}
@if ($last)
    @php($when = \Illuminate\Support\Carbon::parse($last['at']))
    <div class="card sync-status" role="status">
        <h2>Last sync
            <span class="pill pill-{{ $last['ok'] ? 'up' : 'down' }}">{{ $last['ok'] ? ($last['pushed'] ? 'pushed' : 'applied') : 'failed' }}</span>
        </h2>
        <p class="muted">
            <time datetime="{{ $when->toIso8601String() }}" title="{{ $when->toDayDateTimeString() }}">{{ $when->diffForHumans() }}</time>
            · <span class="mono">{{ $last['path'] }}</span> on <span class="mono">{{ $last['branch'] }}</span>
        </p>
        @if ($last['ok'])
            <ul class="changes">
                @if ($last['pushed'])<li>Pushed commit <span class="mono">{{ substr((string) $last['commit'], 0, 7) }}</span> to the repository</li>@else<li>Nothing needed pushing to the repository</li>@endif
                <li>{{ $last['labman'] }} change(s) saved in LabMan</li>
            </ul>
        @else
            <p class="warn">{{ $last['message'] }}</p>
            <p class="hint">Nothing was changed in LabMan when a push fails.</p>
        @endif
    </div>
@endif
