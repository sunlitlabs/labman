<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role' => ['required', Rule::in([User::ROLE_ADMIN, User::ROLE_USER])],
            'status' => ['required', Rule::in([User::STATUS_ACTIVE, User::STATUS_PENDING, User::STATUS_DISABLED])],
        ]);

        $user = new User(['name' => $data['name'], 'email' => mb_strtolower($data['email']), 'password' => $data['password']]);
        $user->role = $data['role'];
        $user->status = $data['status'];
        $user->save();

        return redirect()->route('users.index')->with('status', 'User created.');
    }

    public function edit(User $user)
    {
        return view('users.form', ['user' => $user]);
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')->ignore($user)],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'role' => ['required', Rule::in([User::ROLE_ADMIN, User::ROLE_USER])],
            'status' => ['required', Rule::in([User::STATUS_ACTIVE, User::STATUS_PENDING, User::STATUS_DISABLED])],
        ]);

        $losesAdmin = $data['role'] !== User::ROLE_ADMIN || $data['status'] !== User::STATUS_ACTIVE;

        if ($losesAdmin && $user->is($request->user())) {
            throw ValidationException::withMessages(['role' => 'You cannot demote or disable your own account.']);
        }

        if ($losesAdmin && $user->isAdmin() && $user->isActive()
            && ! User::activeAdmins()->whereKeyNot($user->id)->exists()) {
            throw ValidationException::withMessages(['role' => 'This is the last active administrator and cannot be demoted or disabled.']);
        }

        $user->fill(['name' => $data['name'], 'email' => mb_strtolower($data['email'])]);
        if (filled($data['password'] ?? null)) {
            $user->password = $data['password']; // hashed by the model cast
        }
        $user->role = $data['role'];
        $user->status = $data['status'];
        $user->save();

        if ($user->status !== User::STATUS_ACTIVE || filled($data['password'] ?? null)) {
            // Drop their sessions right away; EnsureUserIsActive also guards the next request.
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }

        return redirect()->route('users.index')->with('status', 'User updated.');
    }

    public function approve(User $user)
    {
        if ($user->status === User::STATUS_PENDING) {
            $user->status = User::STATUS_ACTIVE;
            $user->save();
        }

        return redirect()->route('users.index')->with('status', "{$user->name} approved.");
    }
}
