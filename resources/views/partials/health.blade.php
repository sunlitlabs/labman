{{-- Server-rendered health cells; labman.js re-renders the same markup from /status. --}}
@php($st = \App\Monitoring\StatusView::of($item))
<div class="cell state">
    <span data-pill class="pill pill-{{ $st['state'] }}{{ $st['stale'] ? ' is-stale' : '' }}"
          title="{{ $st['checked_at'] ? 'Checked '.$st['checked_at'] : 'Not checked yet' }}">{{ $st['state'] }}{{ $st['stale'] ? ' (stale)' : '' }}</span>
</div>
