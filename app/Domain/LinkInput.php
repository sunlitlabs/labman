<?php

namespace App\Domain;

use App\Models\Link;
use App\Rules\Check;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Validation and storage shape of a dashboard link, shared by the form and the MCP tools. */
final class LinkInput
{
    /** @return array<string, array<int, mixed>> */
    public static function rules(?Link $ignore = null): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('links', 'name')->ignore($ignore)],
            // http(s) only: a bookmark must never be a javascript: or data: address.
            'url' => ['required', 'string', 'max:2000', 'url:http,https'],
            'group' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:500'],
            'icon' => ['nullable', 'string', 'max:80', Check::icon()],
            'on_dashboard' => ['boolean'],
        ];
    }

    /**
     * Validate $input (merged over the existing link when updating) and return what gets stored.
     *
     * @return array<string, mixed>
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public static function validate(array $input, ?Link $existing = null): array
    {
        $base = $existing ? self::current($existing) : ['on_dashboard' => true];
        $data = array_merge($base, $input);
        if (array_key_exists('on_dashboard', $data)) {
            $data['on_dashboard'] = filter_var($data['on_dashboard'], FILTER_VALIDATE_BOOLEAN);
        }

        $valid = Validator::make($data, self::rules($existing))->validate();
        $valid['icon'] = filled($valid['icon'] ?? null) ? strtolower($valid['icon']) : null;

        return $valid;
    }

    /** @return array<string, mixed> */
    public static function current(Link $link): array
    {
        return ['name' => $link->name, 'url' => $link->url, 'group' => $link->group, 'notes' => $link->notes, 'icon' => $link->icon, 'on_dashboard' => $link->on_dashboard];
    }
}