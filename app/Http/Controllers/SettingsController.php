<?php

namespace App\Http\Controllers;

use App\Config\ConfigException;
use App\Config\YamlConfig;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Rules\Check;

class SettingsController extends Controller
{
    /** Read-only for non-admins who are allowed to see the screen (the view disables the form). */
    public function show()
    {
        return view('settings', ['values' => Settings::all()]);
    }

    public function update(Request $request)
    {
        $limits = config('labman.limits');

        $rules = [
            'title' => ['required', 'string', 'max:60'],
            'check_interval' => ['required', 'numeric', 'min:'.$limits['check_interval_min'], 'max:86400'],
            'timeout' => ['required', 'numeric', 'min:'.$limits['timeout_min'], 'max:'.$limits['timeout_max']],
            'ssh_user' => ['nullable', 'string', 'max:32', Check::sshUser()],
            'ssh_target' => ['required', Rule::in(['address', 'domain'])],
            'registration_mode' => ['required', Rule::in(config('labman.registration_modes'))],
        ];
        foreach (config('labman.screens') as $screen) {
            $rules["visibility.{$screen}"] = ['required', Rule::in(config('labman.visibility_levels'))];
        }

        $data = $request->validate($rules);

        $values = collect($data)->except('visibility')->all();
        $values['ssh_user'] = $values['ssh_user'] ?? '';
        foreach ($data['visibility'] as $screen => $level) {
            $values["visibility.{$screen}"] = $level;
        }

        Settings::set($values);

        return redirect()->route('settings.index')->with('status', 'Settings saved.');
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

        return redirect()->route('settings.index')->with('status', sprintf(
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
