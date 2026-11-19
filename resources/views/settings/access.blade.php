@extends('layouts.admin')

@section('title', 'Access settings')

@php
    $admin = auth()->user()?->isAdmin();
    $levels = ['everyone' => 'Everyone', 'users' => 'Signed-in users', 'admins' => 'Admins only'];
    $screens = [
        'services' => ['Services', 'The list of services and their health'],
        'servers' => ['Servers', 'The list of servers and the Copy SSH buttons'],
        'settings' => ['Settings', 'The General and Access pages (viewers can read, never change)'],
        'users' => ['Users', 'The list of accounts (viewers can read, never change)'],
    ];
    $modes = [
        'off' => ['Closed', 'Nobody can register. Admins create every account.'],
        'approval' => ['Approval needed', 'Anyone can sign up, but cannot sign in until an admin approves them.'],
        'open' => ['Open', 'Anyone can sign up and sign in straight away.'],
    ];
@endphp

@section('admin')
    <h1>Access</h1>
    <p class="muted">
        @if ($admin) Who can create an account, and who can see each page. Editing anything is always for admins only, whatever you choose here.
        @else You can view these settings but only administrators can change them. @endif
    </p>

    <form method="POST" action="{{ route('settings.update') }}">
        @csrf @method('PUT')
        <input type="hidden" name="section" value="access">

        <fieldset @disabled(! $admin) class="plain">
            <div class="formcard">
                <h2>Registration</h2>
                <p class="sub">Whether visitors can make their own account.</p>
                <div class="choice-cards" role="radiogroup" aria-label="Registration">
                    @foreach ($modes as $mode => [$label, $text])
                        <label>
                            <input type="radio" name="registration_mode" value="{{ $mode }}" @checked(old('registration_mode', $values['registration_mode']) === $mode)>
                            <span class="cc"><strong>{{ $label }}</strong><small>{{ $text }}</small></span>
                        </label>
                    @endforeach
                </div>
            </div>

            <div class="formcard">
                <h2>Who can see what</h2>
                <p class="sub">Pick the lowest level that may open each page. Guests are sent to sign in; signed-in people without access get an error.</p>
                @foreach ($screens as $screen => [$label, $text])
                    <div class="vis-row">
                        <div class="what"><strong>{{ $label }}</strong><small>{{ $text }}</small></div>
                        <div class="seg" role="radiogroup" aria-label="Who can see {{ $label }}">
                            @foreach ($levels as $level => $levelLabel)
                                <label><input type="radio" name="visibility[{{ $screen }}]" value="{{ $level }}" @checked(old('visibility.'.$screen, $values['visibility.'.$screen]) === $level)><span>{{ $levelLabel }}</span></label>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </fieldset>

        @if ($admin)
            <div class="savebar"><button class="primary" type="submit">Save access settings</button></div>
        @endif
    </form>
@endsection
