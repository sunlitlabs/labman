<?php

namespace App\Inventory;

use Illuminate\Http\Request;

/**
 * Turns the compare page's form into the `choices` structure InventorySync works with, and
 * back into a single JSON string the review page can carry to the apply step.
 *
 *   action[<key>]            = leave | add_inventory | delete_labman | add_labman | remove_inventory
 *   fields[<key>][<field>]   = labman | inventory | skip
 *   setup[<key>][<field>]    = name | address | group | ssh_user | ssh_port (for add_labman)
 */
final class ChoiceForm
{
    /** @return array<string, array<string, mixed>> */
    public static function fromRequest(Request $request): array
    {
        $choices = [];

        foreach ((array) $request->input('action', []) as $key => $action) {
            if (is_string($action)) {
                $choices[(string) $key]['action'] = $action;
            }
        }

        foreach ((array) $request->input('fields', []) as $key => $fields) {
            if (is_array($fields)) {
                $choices[(string) $key]['fields'] = array_filter($fields, 'is_string');
            }
        }

        foreach ((array) $request->input('setup', []) as $key => $setup) {
            if (is_array($setup) && (($choices[(string) $key]['action'] ?? null) === 'add_labman')) {
                $choices[(string) $key]['setup'] = array_filter($setup, fn ($v) => is_string($v) || is_int($v));
            }
        }

        return InventorySync::normalize($choices);
    }

    /** @return array<string, array<string, mixed>> */
    public static function fromJson(?string $json): array
    {
        $data = json_decode((string) $json, true);

        return is_array($data) ? InventorySync::normalize($data) : [];
    }

    /** @param array<string, array<string, mixed>> $choices */
    public static function toJson(array $choices): string
    {
        return json_encode($choices, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
