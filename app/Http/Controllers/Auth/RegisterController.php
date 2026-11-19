<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RegisterController extends Controller
{
    public function show()
    {
        abort_if(Settings::registrationMode() === 'off', 404);

        return view('auth.register', ['needsApproval' => Settings::registrationMode() === 'approval']);
    }

    public function store(Request $request)
    {
        $mode = Settings::registrationMode();
        abort_if($mode === 'off', 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = new User(['name' => $data['name'], 'email' => mb_strtolower($data['email']), 'password' => $data['password']]);
        $user->role = User::ROLE_USER;
        $user->status = $mode === 'approval' ? User::STATUS_PENDING : User::STATUS_ACTIVE;
        $user->save();

        if ($user->status === User::STATUS_PENDING) {
            return redirect()->route('login')->with('status', 'Account created. An administrator must approve it before you can sign in.');
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect('/');
    }
}
