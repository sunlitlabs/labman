<?php

namespace App\Semaphore;

/**
 * Semaphore only accepts extra variables on a run that the template declares as "survey variables"
 * (anything else is refused with a bare HTTP 400). This reads those declarations from a template and
 * checks a run's variables against them, so the admin gets a clear message before anything is sent.
 */
final class SurveyVars
{
    /**
     * @param  array<string, mixed>  $template  a template as returned by Semaphore
     * @return array<string, array{name: string, title: string, description: string, type: string, required: bool, values: list<array{name: string, value: string}>}>
     */
    public static function declared(array $template): array
    {
        $out = [];

        foreach ((array) ($template['survey_vars'] ?? []) as $var) {
            if (! is_array($var) || ! isset($var['name']) || ! is_string($var['name'])) {
                continue;
            }

            $values = [];
            foreach ((array) ($var['values'] ?? []) as $v) {
                if (is_array($v) && isset($v['value'])) {
                    $values[] = ['name' => (string) ($v['name'] ?? $v['value']), 'value' => (string) $v['value']];
                }
            }

            $out[$var['name']] = [
                'name' => $var['name'],
                'title' => (string) (($var['title'] ?? '') !== '' ? $var['title'] : $var['name']),
                'description' => (string) ($var['description'] ?? ''),
                'type' => in_array($var['type'] ?? '', ['int', 'enum', 'secret'], true) ? $var['type'] : 'string',
                'required' => (bool) ($var['required'] ?? false),
                'values' => $values,
            ];
        }

        return $out;
    }

    /**
     * Is this a variable a playbook uses to learn which machines to run on (target_hosts, hosts, limit…)?
     * LabMan fills it from the servers and groups picked on the run form, so it is never asked twice.
     */
    public static function isTarget(string $name): bool
    {
        $name = strtolower($name);

        return in_array($name, ['target', 'targets', 'host', 'hosts', 'limit', 'run_on'], true)
            || (bool) preg_match('/^(target|host|hosts|limit)_/', $name);
    }

    /**
     * Check and type a run's extra variables against the template's declarations.
     *
     * @param  array<string, mixed>  $options  validated RunOptions
     * @param  array<string, mixed>  $template
     * @return array<string, mixed> the options with typed extra_vars
     *
     * @throws SemaphoreException
     */
    public static function check(array $options, array $template): array
    {
        $declared = self::declared($template);
        $vars = $options['extra_vars'] ?? [];

        if ($declared === []) {
            return $options; // nothing declared: older Semaphore versions accept any variable, newer ones refuse it themselves
        }

        $typed = [];
        foreach ($vars as $name => $value) {
            $def = $declared[$name] ?? throw new SemaphoreException("'{$name}' is not a variable this template declares. It accepts: ".implode(', ', array_keys($declared)).'.');

            $typed[$name] = match ($def['type']) {
                'int' => is_numeric($value) && (string) (int) $value === (string) $value ? (int) $value : throw new SemaphoreException("{$def['title']} must be a whole number."),
                'enum' => in_array((string) $value, array_column($def['values'], 'value'), true) ? (string) $value : throw new SemaphoreException("{$def['title']} must be one of: ".implode(', ', array_column($def['values'], 'value')).'.'),
                'secret' => throw new SemaphoreException("'{$name}' is a secret variable. Set it in Semaphore; LabMan does not pass secrets."),
                default => is_scalar($value) ? (string) $value : throw new SemaphoreException("{$def['title']} must be text."),
            };
        }

        foreach ($declared as $name => $def) {
            if ($def['required'] && $def['type'] !== 'secret' && ! array_key_exists($name, $typed)) {
                if (self::isTarget($name)) {
                    throw new SemaphoreException("This template needs to know where to run ('{$name}'). Tick the servers or groups to run it on.");
                }

                throw new SemaphoreException("The template needs the variable '{$name}' ({$def['title']}).");
            }
        }

        $options['extra_vars'] = $typed ?: null;

        return $options;
    }
}
