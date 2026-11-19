@extends('layouts.admin')

@section('title', $user->exists ? 'Edit user' : 'Add user')

@section('admin')
    <div class="wide">
        <h1>{{ $user->exists ? 'Edit user' : 'Add user' }}</h1>

        <form class="card" method="POST" action="{{ $user->exists ? route('users.update', $user) : route('users.store') }}">
            @csrf
            @if ($user->exists) @method('PUT') @endif

            <div class="grid2">
                <div class="field">
                    <label for="name">Name</label>
                    <input id="name" name="name" type="text" required maxlength="100" value="{{ old('name', $user->name) }}">
                </div>
                <div class="field">
                    <label for="email">Email</label>
                    <input id="email" name="email" type="email" required maxlength="255" value="{{ old('email', $user->email) }}">
                </div>
                <div class="field">
                    <label for="role">Role</label>
                    <select id="role" name="role">
                        @foreach (['user', 'admin'] as $role)
                            <option value="{{ $role }}" @selected(old('role', $user->role) === $role)>{{ $role }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        @foreach (['active', 'pending', 'disabled'] as $status)
                            <option value="{{ $status }}" @selected(old('status', $user->status) === $status)>{{ $status }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="password">{{ $user->exists ? 'New password' : 'Password' }} <span class="hint">{{ $user->exists ? '(leave blank to keep; min 8)' : '(min 8)' }}</span></label>
                    <input id="password" name="password" type="password" autocomplete="new-password" @required(! $user->exists)>
                </div>
                <div class="field">
                    <label for="password_confirmation">Confirm password</label>
                    <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password">
                </div>
            </div>

            <p class="hint">You cannot demote or disable yourself, or the last active administrator.</p>
            <div class="actions">
                <button class="primary" type="submit">Save</button>
                <a class="btn" href="{{ route('users.index') }}">Cancel</a>
            </div>
        </form>
    </div>
@endsection
