@extends('layouts.app')

@section('title', 'Sign in')

@section('content')
    <div class="narrow">
        <h1>Sign in</h1>
        <form class="card" method="POST" action="{{ route('login.store') }}">
            @csrf
            <div class="field">
                <label for="email">Email</label>
                <input id="email" name="email" type="email" required autofocus autocomplete="username" value="{{ old('email') }}">
            </div>
            <div class="field">
                <label for="password">Password</label>
                <input id="password" name="password" type="password" required autocomplete="current-password">
            </div>
            <label class="check"><input type="checkbox" name="remember" value="1"> Remember me</label>
            <div class="actions">
                <button class="primary" type="submit">Sign in</button>
                @if (\App\Support\Settings::registrationMode() !== 'off')
                    <a href="{{ route('register') }}">Create an account</a>
                @endif
            </div>
        </form>
    </div>
@endsection
