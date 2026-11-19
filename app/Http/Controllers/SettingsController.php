<?php

namespace App\Http\Controllers;

use App\Config\ConfigException;
use App\Config\YamlConfig;
use App\Domain\SettingsInput;
use App\Models\ApiToken;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SettingsController extends Controller
{
    /** General settings. Read-only for non-admins who are allowed to see the screen (the view disables the form). */
    public function general()
    {
        // The certificate pickers need NPM's list; without a (working) connection the page just omits them.
        $certificates = null;
        if (\App\Npm\Connection::summary()) {
            try {
                $certificates = \App\Npm\NpmClient::fromStored()->certificates();
            } catch (\Throwable) {
                $certificates = null;
            }
        }

        return view('settings.general', ['values' => Settings::all(), 'certificates' => $certificates]);
    }

    public function access()
    {
        return view('settings.access', ['values' => Settings::all(), 'levels' => config('labman.visibility_levels')]);
    }

    public function data()
    {
        return view('settings.data');
    }

    public function mcp()
    {
        return view('settings.mcp', ['tokens' => ApiToken::with('user')->orderBy('id')->get()]);
    }

    /**
     * Each settings page saves only its own fields (`section`), merged over the current settings.
     * Posting every field without a section (older clients, scripts) still works.
     */
    public function update(Request $request)
    {
        $sections = [
            'general' => ['title', 'check_interval', 'timeout', 'ssh_user', 'ssh_target', 'icons', 'local_domains', 'public_domains', 'npm_cert_local', 'npm_cert_public'],
            'access' => ['registration_mode', 'visibility'],
        ];

        $section = $request->input('section');
        $rules = SettingsInput::rules();

        if (isset($sections[$section])) {
            $rules = array_filter($rules, fn ($key) => in_array(explode('.', $key)[0], $sections[$section], true), ARRAY_FILTER_USE_KEY);
        }

        SettingsInput::update($request->validate($rules));

        return redirect()->route($section === 'access' ? 'settings.access' : 'settings.index')->with('status', 'Settings saved.');
    }
    public function import(Request $request)
    {
        $request->validate([
            'yaml' => ['nullable', 'string', 'max:500000'],
            'yaml_file' => ['nullable', 'file', 'max:512'],
        ]);

        $yaml = $request->file('yaml_file')?->get() ?: (string) $request->input('yaml', '');

        if (trim($yaml) === '') {
            throw ValidationException::withMessages(['yaml' => 'Paste some YAML or choose a file.']);
        }

        try {
            $config = YamlConfig::parse($yaml);
        } catch (ConfigException $e) {
            throw ValidationException::withMessages(['yaml' => $e->errors]);
        }

        $result = YamlConfig::import($config, $request->boolean('replace'));

        return redirect()->route('settings.data')->with('status', sprintf(
            'Imported %d server(s) and %d service(s)%s.',
            $result['servers'], $result['services'], $result['removed'] ? "; removed {$result['removed']} not in the file" : '',
        ));
    }

    public function export()
    {
        return response(YamlConfig::export(), 200, [
            'Content-Type' => 'application/x-yaml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="labman.yml"',
        ]);
    }
}
