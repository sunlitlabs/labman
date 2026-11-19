<?php

namespace App\Http\Controllers;

use App\Domain\UserAdmin;
use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index()
    {
        return view('users.index', ['users' => User::query()->orderBy('role')->orderBy('name')->get()]);
    }

    public function create()
    {
        return view('users.form', ['user' => new User(['role' => User::ROLE_USER, 'status' => User::STATUS_ACTIVE])]);
    }

    public function store(Request $request)
    {
        UserAdmin::save($request->all(), null, $request->user(), confirmPassword: true);

        return redirect()->route('users.index')->with('status', 'User created.');
    }

    public function edit(User $user)
    {
        return view('users.form', ['user' => $user]);
    }

    public function update(Request $request, User $user)
    {
        UserAdmin::save($request->all(), $user, $request->user(), confirmPassword: true);

        return redirect()->route('users.index')->with('status', 'User updated.');
    }

    public function approve(User $user)
    {
        UserAdmin::approve($user);

        return redirect()->route('users.index')->with('status', "{$user->name} approved.");
    }
}
