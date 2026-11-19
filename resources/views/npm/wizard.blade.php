@extends('layouts.admin')

@section('title', 'Match up with NPM')

@section('focus', '1')
@section('crumb', 'Nginx Proxy Manager')
@section('crumb_url', route('npm.index'))

@php
    $sections = [
        'new_here' => ['In NPM, not in LabMan', 'These proxy hosts have no matching service. Adding them gives you a service with the right domain, address and port.'],
        'link_only' => ['Same thing, not linked yet', 'These match by domain. Linking them lets future syncs follow the proxy host even if the domain changes.'],
        'differs' => ['Linked, but the settings differ', 'Pick which side is right. NPM is what actually routes traffic, so its values are the default.'],
        'suggest_domain' => ['Suggested local names', 'A best guess from each name, under your default local domain. Saved in LabMan only; run the sync again afterwards to create proxy hosts for them.'],
        'new_there' => ['In LabMan, not in NPM', 'Services with a domain but no proxy host. Nothing is created in NPM unless you choose it (the new host has no SSL until you add a certificate in NPM).'],
    ];
    $actionable = collect($rows)->whereIn('kind', array_keys($sections));
    $total = array_sum($counts);
@endphp

@section('admin')
    <h1>Match up with Nginx Proxy Manager</h1>

    @if ($only)
        <p class="muted">Showing only the service you came from. <a href="{{ route('npm.wizard') }}">Show everything</a></p>
    @else
        <p class="muted">
            Connected to <span class="mono">{{ $connection['url'] }}</span>.
            @if ($total === 0)
                Neither side has anything to match yet.
            @else
                {{ $total }} item(s) compared:
                <strong>{{ $counts['in_sync'] }}</strong> already match,
                <strong>{{ $actionable->count() }}</strong> {{ $actionable->count() === 1 ? 'needs' : 'need' }} a look{{ $counts['skipped'] ? ', '.$counts['skipped'].' cannot be used' : '' }}.
            @endif
            The ticked defaults only ever change LabMan, and you can still change any of them. LabMan never deletes anything in NPM.
        </p>
    @endif

    <form class="formcard suggest-toggle" method="GET" action="{{ route('npm.wizard') }}">
        @if ($only)<input type="hidden" name="service" value="{{ $only }}">@endif
        <label class="check">
            <input type="checkbox" name="suggest" value="1" @checked($suggest) @disabled(! $suggestAvailable) data-autosubmit>
            <span>Also suggest local domain names for services and servers that have none
                <span class="hint">{{ $suggestAvailable ? 'Guessed from each name under '.\App\Support\Domains::defaultFor('local').'. Off by default.' : 'Set a local domain under Settings > General to use this.' }}</span></span>
        </label>
        <noscript><button class="btn" type="submit">Apply</button></noscript>
    </form>

    @if ($actionable->isEmpty())
        <div class="card empty">
            Everything that can be matched is already matched.
            <a href="{{ route('npm.index') }}">Back to Nginx Proxy Manager</a>
        </div>
    @else
        <form method="POST" action="{{ route('npm.wizard.apply') }}">
            @csrf

            @foreach ($sections as $kind => [$heading, $explain])
                @php($group = $actionable->where('kind', $kind))
                @continue($group->isEmpty())
                <section class="formcard">
                    <h2>{{ $heading }} <span class="count">{{ $group->count() }}</span></h2>
                    <p class="sub">{{ $explain }}</p>

                    @foreach ($group as $row)
                        <div class="plan-item wiz-row">
                            <div class="plan-head"><strong>{{ $row['title'] }}</strong></div>

                            @if ($row['changes'])
                                <ul class="changes">
                                    @foreach ($row['changes'] as $c)
                                        <li>
                                            <span class="field">{{ $c['field'] }}</span>
                                            @if ($c['from'] !== null && $c['from'] !== '') <span class="from">{{ $c['from'] }}</span> → @endif
                                            <span class="to">{{ $c['to'] }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                            @if ($row['kind'] === 'suggest_domain')
                                <div class="field">
                                    <label for="de-{{ md5($row['id']) }}">Local name <span class="hint">edit it if the guess is off</span></label>
                                    <input id="de-{{ md5($row['id']) }}" type="text" name="domain_edit[{{ $row['id'] }}]" value="{{ old('domain_edit.'.$row['id'], $row['changes'][0]['to']) }}" autocomplete="off" spellcheck="false" maxlength="255">
                                </div>
                            @endif
                            @foreach ($row['warnings'] as $w)<p class="warn">{{ $w }}</p>@endforeach

                            <fieldset class="choices">
                                <legend class="sr-only">What to do with {{ $row['title'] }}</legend>
                                @foreach ($row['options'] as $value => $label)
                                    <label class="check">
                                        <input type="radio" name="choice[{{ $row['id'] }}]" value="{{ $value }}" @checked($row['default'] === $value)>
                                        {{ $label }}
                                    </label>
                                @endforeach
                            </fieldset>

                            @if ($row['import'])<input type="hidden" name="fp_import[{{ $row['id'] }}]" value="{{ $row['import']['fingerprint'] }}">@endif
                            @if ($row['push'])
                                <input type="hidden" name="push_key[{{ $row['id'] }}]" value="{{ $row['push']['key'] }}">
                                <input type="hidden" name="fp_push[{{ $row['id'] }}]" value="{{ $row['push']['fingerprint'] }}">
                            @endif
                        </div>
                    @endforeach
                </section>
            @endforeach

            <div class="savebar">
                <button class="primary" type="submit">Apply my choices</button>
                <a class="btn" href="{{ route('npm.index') }}">Skip for now</a>
            </div>
        </form>
    @endif

    @php($rest = collect($rows)->whereIn('kind', ['in_sync', 'skipped']))
    @if ($rest->isNotEmpty())
        <details class="formcard">
            <summary>Already matched or not usable ({{ $rest->count() }})</summary>
            <ul class="changes">
                @foreach ($rest as $row)
                    <li><strong>{{ $row['title'] }}</strong>: {{ $row['kind'] === 'in_sync' ? 'in sync' : ($row['reason'] ?: 'skipped') }}</li>
                @endforeach
            </ul>
        </details>
    @endif
@endsection
