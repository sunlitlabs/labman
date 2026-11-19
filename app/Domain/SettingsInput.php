<?php

namespace App\Domain;

use App\Rules\Check;
use App\Support\Settings;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Validation and persistence of the Settings screen, shared by the form and the MCP tools. */
final class SettingsInput
{
    /** @return array<string, array<int, mixed>> */
    public static function rules(): array
    {
        $limits = config('labman.limits');

        $rules = [
            'title' => ['required', 'string', 'max:60'],
            'check_interval' => ['required', 'numeric', 'min:'.$limits['check_interval_min'], 'max:86400'],
            'timeout' => ['required', 'numeric', 'min:'.$limits['timeout_min'], 'max:'.$limits['timeout_max']],
            'ssh_user' => ['nullable', 'string', 'max:32', Check::sshUser()],
            'ssh_target' => ['required', Rule::in(['address', 'domain'])],
            'registration_mode' => ['required', Rule::in(config('labman.registration_modes'))],
            'icons' => ['boolean'],
        ];

        foreach (config('labman.screens') as $screen) {
            $rules["visibility.{$screen}"] = ['required', Rule::in(config('labman.visibility_levels'))];
        }

        return $rules;
    }

    /** The current settings in the shape the form submits. @return array<string, mixed> */
    public static function current(): array
    {
        $all = Settings::all();
        $out = [];

        foreach (['title', 'check_interval', 'timeout', 'ssh_user', 'ssh_target', 'registration_mode'] as $key) {
            $out[$key] = $all[$key];
        }
        $out['icons'] = Settings::icons();
        foreach (config('labman.screens') as $screen) {
            $out['visibility'][$screen] = $all['visibility.'.$screen];
        }

        return $out;
    }

    /** Validate a partial or full set of settings (merged over the current ones) and save it. @return array<string, mixed> */
    public static function update(array $changes): array
    {
        $merged = array_replace_recursive(self::current(), $changes);

        return self::save(Validator::make($merged, self::rules())->validate());
    }

    /** @return array<string, mixed> the settings as stored */
    public static function save(array $validated): array
    {
        $values = collect($validated)->except('visibility')->all();
        $values['ssh_user'] = $values['ssh_user'] ?? '';
        if (array_key_exists('icons', $values)) {
            $values['icons'] = filter_var($values['icons'], FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
        }

        foreach ($validated['visibility'] as $screen => $level) {
            $values["visibility.{$screen}"] = $level;
        }

        Settings::set($values);

        return self::current();
    }
}
