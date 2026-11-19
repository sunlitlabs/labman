<?php

namespace App\Semaphore;

/**
 * Validates what an admin (or an AI client) asks Semaphore to run, and turns it into the task
 * payload. Everything that ends up in the request is checked here: host limits, extra variables, message.
 */
final class RunOptions
{
    /**
     * @param  array<string, mixed>  $input  project_id, template_id, limit, dry_run, diff, extra_vars (object or JSON text), message
     * @return array{project_id: int, template_id: int, limit: string, dry_run: bool, diff: bool, extra_vars: ?array, message: string}
     *
     * @throws SemaphoreException
     */
    public static function validate(array $input): array
    {
        $project = self::positiveInt($input['project_id'] ?? null, 'project');
        $template = self::positiveInt($input['template_id'] ?? null, 'template');

        return [
            'project_id' => $project,
            'template_id' => $template,
            'limit' => self::limit($input['limit'] ?? ''),
            'dry_run' => filter_var($input['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'diff' => filter_var($input['diff'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'extra_vars' => self::extraVars(self::withSurvey($input['extra_vars'] ?? null, $input['survey'] ?? [])),
            'message' => self::message($input['message'] ?? ''),
        ];
    }

    /** The body for POST /api/project/{id}/tasks. @param array<string, mixed> $options validated options @return array<string, mixed> */
    public static function payload(array $options): array
    {
        $payload = [
            'template_id' => $options['template_id'],
            'dry_run' => $options['dry_run'],
            'diff' => $options['diff'],
        ];

        if ($options['limit'] !== '') {
            $payload['limit'] = $options['limit'];
        }
        if ($options['extra_vars']) {
            $payload['environment'] = json_encode($options['extra_vars'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        if ($options['message'] !== '') {
            $payload['message'] = $options['message'];
        }

        return $payload;
    }

    /** A short human summary for the confirmation step. @param array<string, mixed> $options */
    public static function describe(array $options): string
    {
        return ($options['limit'] !== '' ? 'only on '.$options['limit'] : 'on EVERY host the template targets')
            .($options['dry_run'] ? ', check mode (dry run)' : ', for real')
            .($options['diff'] ? ', showing diffs' : '');
    }

    private static function positiveInt(mixed $value, string $what): int
    {
        if (! is_numeric($value) || (int) $value < 1 || (string) (int) $value !== (string) $value && ! is_int($value)) {
            throw new SemaphoreException("Choose a {$what}.");
        }

        return (int) $value;
    }

    /** Comma separated hosts/groups. Each may use * ! & : [ ] like Ansible patterns, nothing else. */
    private static function limit(mixed $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        if (strlen($value) > 500) {
            throw new SemaphoreException('The limit is too long (max 500 characters).');
        }

        $parts = array_values(array_filter(array_map('trim', explode(',', $value)), fn ($p) => $p !== ''));
        foreach ($parts as $part) {
            // A leading "-" would read as a command-line option to ansible-playbook, never as a host.
            if (! preg_match('/^[A-Za-z0-9_.\-*!&:\[\]]{1,100}$/', $part) || str_starts_with($part, '-')) {
                throw new SemaphoreException("'{$part}' is not a valid host or group name for the limit.");
            }
        }

        return implode(',', array_unique($parts));
    }

    /** Values typed into the template's survey fields win over the free-form JSON. */
    private static function withSurvey(mixed $extra, mixed $survey): mixed
    {
        $survey = array_filter((array) $survey, fn ($v) => is_scalar($v) && trim((string) $v) !== '');
        if ($survey === []) {
            return $extra;
        }

        $base = is_string($extra) && trim($extra) !== '' ? json_decode($extra, true) : ($extra ?: []);
        if (! is_array($base)) {
            return $extra; // not valid: let extraVars() report it
        }

        return array_merge($base, array_map(fn ($v) => trim((string) $v), $survey));
    }

    /** @return array<string, mixed>|null */
    private static function extraVars(mixed $value): ?array
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (! is_array($decoded)) {
                throw new SemaphoreException('Extra variables must be a JSON object, for example {"version": "1.2"}.');
            }
            $value = $decoded;
        }

        if (! is_array($value) || array_is_list($value)) {
            throw new SemaphoreException('Extra variables must be a JSON object (name: value pairs), not a list.');
        }

        foreach (array_keys($value) as $name) {
            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', (string) $name)) {
                throw new SemaphoreException("'{$name}' is not a valid variable name (letters, digits and _, not starting with a digit).");
            }
        }

        if (strlen((string) json_encode($value)) > 10000) {
            throw new SemaphoreException('The extra variables are too large (max 10 KB).');
        }

        return $value;
    }

    private static function message(mixed $value): string
    {
        $value = trim((string) $value);

        if (preg_match('/[\r\n\x00]/', $value) || strlen($value) > 200) {
            throw new SemaphoreException('The run message must be one line of at most 200 characters.');
        }

        return $value;
    }
}
