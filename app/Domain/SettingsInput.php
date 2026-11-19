<?php

namespace App\Domain;

use App\Rules\Check;
use App\Support\Domains;
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
            'local_domains' => ['nullable', Check::domainList()],
            'public_domains' => ['nullable', Check::domainList()],
            'local_default' => ['nullable', 'string', 'max:253'],
            'public_default' => ['nullable', 'string', 'max:253'],
            'npm_cert_local' => ['nullable', 'integer', 'min:0'],
            'npm_cert_public' => ['nullable', 'integer', 'min:0'],
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
        foreach (['local_domains', 'public_domains', 'npm_cert_local', 'npm_cert_public'] as $key) {
            $out[$key] = (string) ($all[$key] ?? '');
        }
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
        foreach (['local_domains', 'public_domains'] as $key) {
            if (array_key_exists($key, $values)) {
                $values[$key] = implode(', ', Domains::compose($values[$key], $values[str_replace('domains', 'default', $key)] ?? null));
            }
        }
        unset($values['local_default'], $values['public_default']);
        foreach (['npm_cert_local', 'npm_cert_public'] as $key) {
            if (array_key_exists($key, $values)) {
                $values[$key] = (string) ($values[$key] ?? '');
            }
        }
        if (array_key_exists('icons', $values)) {
            $values['icons'] = filter_var($values['icons'], FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
        }

        foreach ($validated['visibility'] as $screen => $level) {
            $values["visibility.{$screen}"] = $level;
        }

        $before = ['local' => (string) Settings::get('npm_cert_local', ''), 'public' => (string) Settings::get('npm_cert_public', '')];

        Settings::set($values);

        // A different certificate for a kind of domain moves to every proxy host of that kind (when NPM is kept up to date automatically).
        $changed = array_keys(array_filter($before, fn ($old, $scope) => array_key_exists('npm_cert_'.$scope, $values) && $values['npm_cert_'.$scope] !== $old, ARRAY_FILTER_USE_BOTH));
        \App\Npm\NpmAuto::queueScopes($changed);

        return self::current();
    }
}
