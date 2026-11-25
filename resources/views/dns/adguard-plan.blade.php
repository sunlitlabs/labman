@extends('layouts.admin')

@php($actionable = collect($items)->whereIn('action', ['create', 'update', 'delete'])->filter(fn ($i) => empty($i['errors'])))
@php($byAction = collect($items)->countBy('action'))

@section('title', 'Preview AdGuard Home')
@section('focus', '1')
@section('crumb', 'AdGuard Home')
@section('crumb_url', route('dns.adguard'))

@section('admin')
    <h1>Write to AdGuard Home: preview</h1>
    <p class="muted">The inside records of the DNS plan compared with the DNS rewrites in AdGuard Home. Ticked changes will be <strong>written to AdGuard Home</strong>. A delete is offered only for a rewrite LabMan wrote itself that the plan no longer asks for, and is never ticked for you.</p>

    <div class="card check-result {{ $actionable->isEmpty() ? 'inv-ok' : '' }}" role="status">
        <p class="check-line"><strong>Checked {{ count($items) }} record{{ count($items) === 1 ? '' : 's' }}:</strong>
            {{ $byAction->get('unchanged', 0) }} already there, {{ $byAction->get('create', 0) }} to add, {{ $byAction->get('update', 0) }} to change, {{ $byAction->get('delete', 0) }} to delete, {{ $byAction->get('skip', 0) }} skipped.
            @if ($others) {{ $others }} other rewrite{{ $others === 1 ? '' : 's' }} in AdGuard Home {{ $others === 1 ? 'is' : 'are' }} not part of the plan and left alone. @endif</p>
        <p class="check-verdict">{{ $actionable->isEmpty() ? 'Nothing needs changing.' : $actionable->count().' '.($actionable->count() === 1 ? 'change is' : 'changes are').' ready to review below.' }}</p>
    </div>

    @if ($errors->has('adguard'))
        <p class="warn" role="alert">{{ $errors->first('adguard') }}</p>
    @endif

    @if ($items === [])
        <div class="card empty">The DNS plan has no inside records yet. Give a zone a local domain and a proxy address first.</div>
    @else
        <form method="POST" action="{{ route('dns.adguard.apply') }}">
            @csrf
            <div class="rows plan">
                @foreach ($items as $item)
                    @php($blocked = ! empty($item['errors']))
                    @php($can = in_array($item['action'], ['create', 'update', 'delete']) && ! $blocked)
                    <div class="plan-item plan-{{ $item['action'] }}">
                        <div class="plan-head">
                            @if ($can)
                                <label class="check">
                                    <input type="checkbox" name="apply[]" value="{{ $item['key'] }}" @checked($item['action'] === 'create')>
                                    <strong class="mono">{{ $item['title'] }}</strong>
                                </label>
                                <input type="hidden" name="fp[{{ $item['key'] }}]" value="{{ $item['fingerprint'] }}">
                            @else
                                <strong class="mono">{{ $item['title'] }}</strong>
                            @endif
                            <span class="pill pill-{{ $blocked ? 'down' : ['create' => 'degraded', 'update' => 'degraded', 'unchanged' => 'up', 'skip' => 'unknown', 'delete' => 'down'][$item['action']] }}">
                                {{ $blocked ? 'blocked' : ['create' => 'new rewrite', 'update' => 'change answer', 'unchanged' => 'already there', 'skip' => 'skipped', 'delete' => 'delete'][$item['action']] }}
                            </span>
                            <small class="muted">{{ $item['zone'] }}</small>
                        </div>
                        @foreach ($item['errors'] as $e)
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
                        @elseif ($item['action'] === 'unchanged')
                            <p class="muted">{{ $item['data']['domain'] }} already answers {{ $item['data']['answer'] }}.</p>
                        @endif
                        @foreach ($item['warnings'] as $w)
                            <p class="warn">{{ $w }}</p>
                        @endforeach
                    </div>
                @endforeach
            </div>

            <div class="savebar plan-actions">
                @if ($actionable->isNotEmpty())
                    <button class="primary" type="submit">Write ticked changes to AdGuard Home</button>
                @else
                    <span class="muted">Nothing to apply.</span>
                @endif
                <a class="btn" href="{{ route('dns.adguard') }}">Back</a>
            </div>
        </form>
    @endif
@endsection
