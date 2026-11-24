{{-- The rows of a preview. $items, $isImport, $applyName ('apply[]'), $fpPrefix ('fp'); the ticked state defaults to what is safe to apply --}}
<div class="rows plan">
    @foreach ($items as $item)
        @php($blocked = ! empty($item['errors']))
        @php($can = in_array($item['action'], ['create', 'update']) && ! $blocked)
        <div class="plan-item plan-{{ $item['action'] }}">
            <div class="plan-head">
                @if ($can)
                    <label class="check">
                        <input type="checkbox" name="{{ $applyName }}" value="{{ $item['key'] }}" @checked($isImport || $item['action'] === 'update')>
                        <strong>{{ $item['title'] }}</strong>
                    </label>
                    <input type="hidden" name="{{ $fpPrefix }}[{{ $item['key'] }}]" value="{{ $item['fingerprint'] }}">
                @else
                    <strong>{{ $item['title'] }}</strong>
                @endif
                <span class="pill pill-{{ $blocked ? 'down' : ['create' => 'degraded', 'update' => 'degraded', 'unchanged' => 'up', 'skip' => 'unknown', 'orphan' => 'down'][$item['action']] }}">
                    {{ $blocked ? 'blocked' : ['create' => $isImport ? 'new service' : 'new proxy host', 'update' => 'update', 'unchanged' => 'in sync', 'skip' => 'skipped', 'orphan' => 'orphaned'][$item['action']] }}
                </span>
            </div>
            <p class="plan-plain">{{ \App\Npm\PlanText::describe($item, $zoneName ?? 'Nginx Proxy Manager', $isImport) }}</p>
            @foreach ($item['errors'] ?? [] as $e)
                <p class="warn" role="alert"><strong>Error:</strong> {{ $e }}</p>
            @endforeach

            @if ($item['changes'])
                <ul class="changes">
                    @foreach ($item['changes'] as $c)
                        <li>
                            <span class="field">{{ $c['field'] }}</span>
                            @if ($c['from'] !== null && $c['from'] !== '') <span class="from">{{ $c['from'] }}</span> → @endif
                            <span class="to">{{ $c['to'] }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif

            @foreach ($item['warnings'] as $w)
                <p class="warn">{{ $w }}</p>
            @endforeach

            @if ($item['changes'] || ! empty($item['data']['payload']))
                <details class="plan-raw"><summary>Raw changes</summary><pre>{{ \App\Npm\PlanText::raw($item) }}</pre></details>
            @endif
        </div>
    @endforeach
</div>
