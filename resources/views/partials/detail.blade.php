@php($st = \App\Monitoring\StatusView::of($item))
<div class="cell detail" data-detail>
    @forelse ($st['checks'] as $c)
        <span class="chk{{ $c['ok'] ? '' : ' bad' }}">{{ $c['label'] }} · {{ $c['detail'] }}@if ($c['ms'] !== null) · {{ $c['ms'] }} ms @endif</span>
    @empty
        <span class="chk">{{ $st['state'] === 'off' ? 'Not monitored: no checks are run.' : 'Waiting for the first check…' }}</span>
    @endforelse
</div>
