@extends('layouts.admin')

@section('title', 'Admin')

@section('admin')
    <h1>Admin</h1>
    <p class="muted">{{ $admin ? 'Everything that configures LabMan lives here, apart from the pages you look at day to day.' : 'You can view these settings; only administrators can change them.' }}</p>

    @if ($pending > 0)
        <a class="alertcard" href="{{ route('users.index') }}">
            <strong>{{ $pending }} {{ \Illuminate\Support\Str::plural('account', $pending) }} waiting for approval</strong>
            <span>Review and approve</span>
        </a>
    @endif

    <div class="stats">
        <div><strong>{{ $counts['services'] }}</strong><span>services</span></div>
        <div><strong>{{ $counts['servers'] }}</strong><span>servers</span></div>
        @if ($counts['users'] !== null) <div><strong>{{ $counts['users'] }}</strong><span>users</span></div> @endif
    </div>

    <h2>Site</h2>
    <div class="cards">
        @if ($canSettings)
            <a class="acard" href="{{ route('settings.index') }}">
                <h3>General</h3>
                <p>Site title, how often health is checked, and the default SSH user for Copy SSH.</p>
            </a>
            <a class="acard" href="{{ route('settings.access') }}">
                <h3>Access</h3>
                <p>Whether people can register, and who may see Services, Servers, Settings and Users.</p>
            </a>
        @endif
        @if ($canUsers)
            <a class="acard" href="{{ route('users.index') }}">
                <h3>Users</h3>
                <p>Create accounts, change roles, approve new sign-ups and reset passwords.</p>
            </a>
        @endif
    </div>

    @if ($admin)
        <h2>Integrations and tools</h2>
        <div class="cards">
            @foreach ($cards as $card)
                <a class="acard" href="{{ route($card['to']) }}">
                    <h3>
                        {{ $card['title'] }}
                        <span class="statuschip {{ $card['on'] ? 'on' : 'off' }}">{{ $card['on'] ? 'connected' : 'not set up' }}</span>
                    </h3>
                    <p>{{ $card['detail'] }}</p>
                    <span class="acard-action">{{ $card['action'] }} →</span>
                </a>
            @endforeach
            <a class="acard" href="{{ route('settings.data') }}">
                <h3>Import and export</h3>
                <p>Move your whole configuration as a YAML file, or load one.</p>
            </a>
        </div>
    @endif
@endsection
