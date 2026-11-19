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
    /** Read-only for non-admins who are allowed to see the screen (the view disables the form). */
    public function show(Request $request)
    {
        return view('settings', [
            'values' => Settings::all(),
            'tokens' => $request->user()?->isAdmin() ? ApiToken::with('user')->orderBy('id')->get() : collect(),
        ]);
    }

    public function update(Request $request)
    {
        SettingsInput::save($request->validate(SettingsInput::rules()));

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
