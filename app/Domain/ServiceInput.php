<?php

namespace App\Domain;

use App\Models\Service;
use App\Rules\Check;
use App\Support\ExpectRange;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** The single definition of what a valid service looks like (admin form, MCP tools). */
final class ServiceInput
{
    private const FLAGS = ['verify_tls' => true, 'public_check' => true, 'websockets' => false];

    /**
     * Normalise raw input. With $form, an absent checkbox means "off"; otherwise
     * absent flags are left for the caller's defaults.
     */
    public static function prepare(array $in, bool $form = false): array
    {
        if (! isset($in['path']) || $in['path'] === '') {
            $in['path'] = '/';
        }
        if (! isset($in['expect']) || $in['expect'] === '') {
            $in['expect'] = ExpectRange::DEFAULT;
        }

        foreach (self::FLAGS as $flag => $default) {
            if (array_key_exists($flag, $in)) {
                $in[$flag] = filter_var($in[$flag], FILTER_VALIDATE_BOOLEAN);
            } elseif ($form) {
                $in[$flag] = false;
            }
        }

        return $in;
    }

    /** @return array<string, array<int, mixed>> */
    public static function rules(Service|int|null $ignore = null): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('services', 'name')->ignore($ignore)],
            'domain' => ['nullable', 'string', 'max:253', Check::hostname()],
            'server_id' => ['nullable', 'integer', 'exists:servers,id'],
            'address' => ['nullable', 'string', 'max:253', Check::hostOrIp()],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'scheme' => ['required', Rule::in(['http', 'https'])],
            'path' => ['required', 'string', 'max:500', Check::path()],
            'group' => ['nullable', 'string', 'max:60'],
            'expect' => ['required', Check::expect()],
            'verify_tls' => ['boolean'],
            'public_check' => ['boolean'],
            'websockets' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** A service runs on a server OR has its own address: exactly one. */
    public static function exactlyOne(Validator $validator, array $data): void
    {
        $hasServer = filled($data['server_id'] ?? null);
        $hasAddress = filled($data['address'] ?? null);

        if ($hasServer === $hasAddress) {
            $validator->errors()->add('server_id', 'Choose a server or enter an address — exactly one of the two.');
        }
    }

    /** @return array<string, mixed> */
    public static function payload(array $validated): array
    {
        $validated['expect'] = (string) ExpectRange::parse($validated['expect']);
        $validated['server_id'] = filled($validated['server_id'] ?? null) ? (int) $validated['server_id'] : null;
        $validated['address'] = $validated['server_id'] ? null : ($validated['address'] ?? null);

        return $validated;
    }

    /**
     * Validate $input (merged over the existing service when updating) and return the payload.
     * Giving a server clears the own address and vice versa, so a partial update can switch.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public static function validate(array $input, ?Service $existing = null): array
    {
        $base = $existing ? self::current($existing) : ['scheme' => 'http'] + self::FLAGS;

        if (filled($input['server_id'] ?? null) && ! array_key_exists('address', $input)) {
            $base['address'] = null;
        }
        if (filled($input['address'] ?? null) && ! array_key_exists('server_id', $input)) {
            $base['server_id'] = null;
        }

        $data = self::prepare(array_merge($base, $input));

        $validator = ValidatorFacade::make($data, self::rules($existing));
        $validator->after(fn (Validator $v) => self::exactlyOne($v, $data));

        return self::payload($validator->validate());
    }

    /** @return array<string, mixed> */
    public static function current(Service $service): array
    {
        return [
            'name' => $service->name,
            'domain' => $service->domain,
            'server_id' => $service->server_id,
            'address' => $service->address,
            'port' => $service->port,
            'scheme' => $service->scheme,
            'path' => $service->path,
            'group' => $service->group,
            'expect' => $service->expect,
            'verify_tls' => $service->verify_tls,
            'public_check' => $service->public_check,
            'websockets' => $service->websockets,
            'notes' => $service->notes,
        ];
    }
}
