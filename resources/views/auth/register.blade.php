@extends('layouts.app')

@section('title', 'Create account')

@section('content')
    <div class="narrow">
        <h1>Create account</h1>
        @if ($needsApproval)
            <p class="muted">An administrator has to approve new accounts before you can sign in.</p>
        @endif
        <form class="card" method="POST" action="{{ route('register.store') }}">
            @csrf
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
            <div class="actions">
                <button class="primary" type="submit">Create account</button>
                <a href="{{ route('login') }}">Back to sign in</a>
            </div>
        </form>
    </div>
@endsection
