@extends('layouts.admin')

@section('title', 'Zones setup')

@section('focus', '1')
@section('crumb', 'Zones')
@section('crumb_url', route('zones.index'))

@section('admin')
    <h1>Zones setup</h1>
    <p class="muted">Five short steps. Each one saves straight away, every one can be skipped, and you can come back to any of them later. Nothing is written to a proxy or to DNS here.</p>

    <nav class="wizard-steps" aria-label="Setup steps">
        <ol>
            @foreach ($steps as $key => $label)
                <li @if ($key === $step) aria-current="step" @endif class="{{ $key === $step ? 'is-current' : '' }} {{ $done[$key] ? 'is-done' : '' }}">
                    <a href="{{ route('zones.wizard', ['step' => $key]) }}"><span class="wizard-num">{{ $loop->iteration }}</span> {{ $label }}@if ($done[$key]) <span class="sr-only">(looks done)</span>@endif</a>
                </li>
            @endforeach
        </ol>
    </nav>

    @include('zones.wizard.'.$step)
@endsection
