<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    public function show()
    {
        return view('auth.login');
    }

    public function store(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        // Throttle per email + IP.
        $key = Str::transliterate(Str::lower($credentials['email']).'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($key);
            throw ValidationException::withMessages(['email' => "Too many sign-in attempts. Try again in {$seconds} seconds."])->status(429);
        }

        $credentials['email'] = Str::lower($credentials['email']);

        if (! Auth::validate($credentials)) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }

        $user = User::query()->where('email', $credentials['email'])->firstOrFail();

        // Only after the password is right do we say why an account cannot sign in.
        if ($user->status === User::STATUS_PENDING) {
            throw ValidationException::withMessages(['email' => 'Your account is waiting for an administrator to approve it.']);
        }
        if (! $user->isActive()) {
            throw ValidationException::withMessages(['email' => 'This account has been disabled.']);
        }

        RateLimiter::clear($key);

        // Upgrade an old bcrypt hash to the current algorithm now that we hold the plain password.
        if (Hash::needsRehash($user->password)) {
            $user->forceFill(['password' => $credentials['password']])->save();
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        return redirect()->intended('/');
    }

    public function destroy(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
