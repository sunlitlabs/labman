{{-- The rows of a preview. $items, $isImport, $applyName ('apply[]'), $fpPrefix ('fp'); the ticked state defaults to what is safe to apply --}}
<div class="rows plan">
    @foreach ($items as $item)
        @php($can = in_array($item['action'], ['create', 'update']))
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
                <span class="pill pill-{{ ['create' => 'degraded', 'update' => 'degraded', 'unchanged' => 'up', 'skip' => 'unknown'][$item['action']] }}">
                    {{ ['create' => $isImport ? 'new service' : 'new proxy host', 'update' => 'update', 'unchanged' => 'in sync', 'skip' => 'skipped'][$item['action']] }}
                </span>
            </div>

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
        </div>
    @endforeach
</div>
