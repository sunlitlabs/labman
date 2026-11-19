<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title>@hasSection('title'){{ trim($__env->yieldContent('title')) }} · @endif{{ \App\Support\Settings::title() }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="stylesheet" href="{{ asset('css/labman.css') }}">
    <script src="{{ asset('js/labman.js') }}" defer></script>
</head>
<body @yield('body_attrs')>
<header class="top">
    <div class="top-inner">
        <a class="brand" href="{{ url('/') }}">{{ \App\Support\Settings::title() }}</a>
        @unless (request()->is('setup'))
        <nav class="nav" aria-label="Main">
            @can('view-screen', 'services')
                <a href="{{ route('services.index') }}" @if(request()->routeIs('services.*')) aria-current="page" @endif>Services</a>
            @endcan
            @can('view-screen', 'servers')
                <a href="{{ route('servers.index') }}" @if(request()->routeIs('servers.*')) aria-current="page" @endif>Servers</a>
            @endcan
            @can('view-screen', 'settings')
                <a href="{{ route('settings.index') }}" @if(request()->routeIs('settings.*')) aria-current="page" @endif>Settings</a>
            @endcan
            @can('view-screen', 'users')
                <a href="{{ route('users.index') }}" @if(request()->routeIs('users.*')) aria-current="page" @endif>Users</a>
            @endcan
            @can('admin')
                <a href="{{ route('npm.index') }}" @if(request()->routeIs('npm.*')) aria-current="page" @endif>NPM</a>
            @endcan
        </nav>
        <div class="who">
            @auth
                <span>{{ auth()->user()->name }}@if(auth()->user()->isAdmin()) <span class="muted">(admin)</span>@endif</span>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit">Sign out</button>
                </form>
            @else
                <a href="{{ route('login') }}">Sign in</a>
            @endauth
        </div>
        @endunless
    </div>
</header>

<main>
    @if (session('status'))
        <div class="flash" role="status">{{ session('status') }}</div>
    @endif
    @include('partials.errors')

    @yield('content')
</main>

<div id="toast" class="toast" role="status" aria-live="polite" hidden></div>
</body>
</html>
