<?php

namespace App\Http\Controllers;

use App\Config\ConfigException;
use App\Config\YamlConfig;
use App\Models\Setting;
use App\Models\User;
use App\Rules\Check;
use App\Support\Setup;
use App\Support\Settings;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * First-run wizard. Reachable only while no admin exists (RedirectToSetup 404s it
 * afterwards) and the lock is re-checked inside the transaction that creates the admin.
 */
class SetupController extends Controller
{
    public function show()
    {
        return view('setup', [
            'needsToken' => filled(config('labman.setup_token')),
            'defaults' => config('labman.defaults'),
        ]);
    }

    public function store(Request $request)
    {
        $levels = config('labman.visibility_levels');

        $rules = [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'title' => ['required', 'string', 'max:60'],
            'registration_mode' => ['required', Rule::in(config('labman.registration_modes'))],
            'yaml' => ['nullable', 'string', 'max:500000'],
            'yaml_file' => ['nullable', 'file', 'max:512'],
        ];
        foreach (config('labman.screens') as $screen) {
            $rules["visibility.{$screen}"] = ['required', Rule::in($levels)];
        }
        if (filled(config('labman.setup_token'))) {
            $rules['setup_token'] = ['required', 'string'];
        }

        $data = $request->validate($rules);

        if (filled(config('labman.setup_token')) && ! hash_equals((string) config('labman.setup_token'), (string) $data['setup_token'])) {
            throw ValidationException::withMessages(['setup_token' => 'The setup token is not correct.']);
        }

        // Validate the optional YAML up front so a bad file creates nothing.
        $yaml = $request->file('yaml_file')?->get() ?: ($data['yaml'] ?? '');
        $config = null;
        if (trim((string) $yaml) !== '') {
            try {
                $config = YamlConfig::parse($yaml);
            } catch (ConfigException $e) {
                throw ValidationException::withMessages(['yaml' => $e->errors]);
            }
        }

        try {
            $admin = DB::transaction(function () use ($data, $config) {
                // Re-check inside the transaction: the first admin wins, everyone else is locked out.
                if (User::query()->where('role', User::ROLE_ADMIN)->exists()) {
                    abort(404);
                }

                // A primary-key row doubles as a lock on databases without gap locks.
                Setting::query()->create(['key' => 'setup_complete', 'value' => '1']);

                $admin = new User(['name' => $data['name'], 'email' => mb_strtolower($data['email']), 'password' => $data['password']]);
                $admin->role = User::ROLE_ADMIN;
                $admin->status = User::STATUS_ACTIVE;
                $admin->save();

                $values = ['title' => $data['title'], 'registration_mode' => $data['registration_mode']];
                foreach (config('labman.screens') as $screen) {
                    $values["visibility.{$screen}"] = $data['visibility'][$screen];
                }
                Settings::set($values);

                if ($config !== null) {
                    YamlConfig::import($config);
                }

                return $admin;
            });
        } catch (QueryException) {
            abort(404); // lost the race: setup is already complete
        }

        Setup::reset();
        Auth::login($admin);
        $request->session()->regenerate();

        return redirect('/')->with('status', 'Setup complete. Welcome to LabMan.');
    }
}
