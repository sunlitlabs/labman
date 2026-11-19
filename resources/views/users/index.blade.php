@extends('layouts.app')

@section('title', 'Users')

@section('content')
    <div class="toolbar">
        <h1>Users</h1>
        @can('admin')
            <a class="btn btn-primary" href="{{ route('users.create') }}">Add user</a>
        @endcan
    </div>
    @cannot('admin')
        <p class="muted">You can view this list but only administrators can change it.</p>
    @endcannot

    <table class="plain">
        <thead>
            <tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th></th></tr>
        </thead>
        <tbody>
            @foreach ($users as $user)
                <tr>
                    <td>{{ $user->name }}</td>
                    <td>{{ $user->email }}</td>
                    <td>{{ $user->role }}</td>
                    <td>
                        <span class="pill pill-{{ ['active' => 'up', 'pending' => 'degraded', 'disabled' => 'down'][$user->status] ?? 'unknown' }}">{{ $user->status }}</span>
                    </td>
                    <td>
                        @can('admin')
                            <div class="actions">
                                @if ($user->status === 'pending')
                                    <form method="POST" action="{{ route('users.approve', $user) }}">@csrf <button class="primary" type="submit">Approve</button></form>
                                @endif
                                <a href="{{ route('users.edit', $user) }}">Edit</a>
                            </div>
                        @endcan
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endsection
