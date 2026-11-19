@extends('layouts.app')

@section('title', 'Setup')

@php
    $levels = ['everyone' => 'Everyone (including guests)', 'users' => 'Signed-in users', 'admins' => 'Admins only'];
@endphp

@section('content')
    <div class="wide">
        <h1>Set up LabMan</h1>
        <p class="muted">
            This creates the first administrator. Once it exists this page disappears for good.
            Anyone who can reach this page right now can claim the install, so finish setup before exposing it.
        </p>

        <form method="POST" action="{{ route('setup.store') }}" enctype="multipart/form-data">
            @csrf

            <div class="card">
                <h2>Administrator</h2>
                <div class="grid2">
                    <div class="field">
                        <label for="name">Name</label>
                        <input id="name" name="name" type="text" required maxlength="100" autofocus value="{{ old('name') }}">
                    </div>
                    <div class="field">
                        <label for="email">Email</label>
                        <input id="email" name="email" type="email" required maxlength="255" autocomplete="username" value="{{ old('email') }}">
                    </div>
                    <div class="field">
                        <label for="password">Password <span class="hint">(min 8)</span></label>
                        <input id="password" name="password" type="password" required autocomplete="new-password">
                    </div>
                    <div class="field">
                        <label for="password_confirmation">Confirm password</label>
                        <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">
                    </div>
                </div>
                @if ($needsToken)
                    <div class="field">
                        <label for="setup_token">Setup token <span class="hint">the LABMAN_SETUP_TOKEN value of this container</span></label>
                        <input id="setup_token" name="setup_token" type="password" required autocomplete="off">
                    </div>
                @endif
            </div>

            <div class="card">
                <h2>Site</h2>
                <div class="grid2">
                    <div class="field">
                        <label for="title">Site title</label>
                        <input id="title" name="title" type="text" required maxlength="60" value="{{ old('title', $defaults['title']) }}">
                    </div>
                    <div class="field">
                        <label for="registration_mode">Registration</label>
                        <select id="registration_mode" name="registration_mode">
                            @foreach (['off' => 'Off', 'open' => 'Open', 'approval' => 'Open, admin approval required'] as $mode => $label)
                                <option value="{{ $mode }}" @selected(old('registration_mode', $defaults['registration_mode']) === $mode)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <h2>Who can see each screen</h2>
                <div class="grid2">
                    @foreach (config('labman.screens') as $screen)
                        <div class="field">
                            <label for="vis-{{ $screen }}">{{ ucfirst($screen) }}</label>
                            <select id="vis-{{ $screen }}" name="visibility[{{ $screen }}]">
                                @foreach ($levels as $level => $label)
                                    <option value="{{ $level }}" @selected(old('visibility.'.$screen, $defaults['visibility.'.$screen]) === $level)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="card">
                <h2>Import existing config <span class="hint">(optional)</span></h2>
                <div class="field">
                    <label for="yaml">Paste YAML</label>
                    <textarea id="yaml" name="yaml" spellcheck="false">{{ old('yaml') }}</textarea>
                </div>
                <div class="field">
                    <label for="yaml_file">…or choose a file</label>
                    <input id="yaml_file" name="yaml_file" type="file" accept=".yml,.yaml,text/yaml,text/plain">
                </div>
            </div>

            <button class="primary" type="submit">Create administrator and finish</button>
        </form>
    </div>
@endsection
