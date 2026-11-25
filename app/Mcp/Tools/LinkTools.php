<?php

namespace App\Mcp\Tools;

use App\Domain\LinkInput;
use App\Mcp\Present;
use App\Mcp\Tool;
use App\Mcp\ToolException;
use App\Models\Link;

/** Dashboard links (bookmarks for other web pages): list, add, change, delete. Validated exactly like the form. */
final class LinkTools
{
    private const IDENT = [
        'id' => ['type' => 'integer', 'description' => 'Numeric id (use this or name).'],
        'name' => ['type' => 'string', 'description' => 'Exact name (use this or id).'],
    ];

    /** @return list<Tool> */
    public static function all(): array
    {
        $fields = [
            'name' => Tool::str('Unique link name.'),
            'url' => Tool::str('The web address, http or https only, e.g. https://controller.example.com.'),
            'group' => Tool::str('Optional group heading.'),
            'notes' => Tool::str('Free text, max 500 characters.'),
            'icon' => Tool::str('Icon name from the icon library. Empty for none.'),
            'on_dashboard' => Tool::bool('Show this link on the dashboard (default true).'),
        ];

        return [
            new Tool('list_links', 'List links', 'The dashboard links (bookmarks for other web pages), including ones hidden from the dashboard (on_dashboard false).',
                [], [], 'read', true, false, fn () => ['links' => Link::query()->orderBy('group')->orderBy('name')->get()->map(fn (Link $l) => Present::link($l))->values()]),

            new Tool('create_link', 'Add a link', 'Add a dashboard link. Validation is identical to the admin form.',
                $fields, ['name', 'url'], 'admin', false, false,
                fn (array $a) => ['link' => Present::link(Link::create(LinkInput::validate($a)))]),

            new Tool('update_link', 'Update a link', 'Change fields of an existing link; omitted fields keep their value. Use new_name to rename.',
                self::IDENT + ['new_name' => Tool::str('Rename to this.')] + array_diff_key($fields, ['name' => 1]),
                [], 'admin', false, false, function (array $a) {
                    $link = self::find($a);
                    $changes = array_diff_key($a, ['id' => 1, 'name' => 1, 'new_name' => 1]);
                    if (isset($a['new_name'])) {
                        $changes['name'] = $a['new_name'];
                    }
                    $link->update(LinkInput::validate($changes, $link));

                    return ['link' => Present::link($link->fresh())];
                }),

            new Tool('delete_link', 'Delete a link', 'Delete a dashboard link. Nothing else is affected.',
                self::IDENT, [], 'admin', false, true, function (array $a) {
                    $link = self::find($a);
                    $link->delete();

                    return ['deleted' => $link->name];
                }),
        ];
    }

    /** @throws ToolException */
    private static function find(array $a): Link
    {
        $link = isset($a['id']) ? Link::query()->find((int) $a['id']) : (isset($a['name']) ? Link::query()->where('name', $a['name'])->first() : null);

        return $link ?? throw new ToolException('No such link. Give an exact name or an id (list_links shows them).');
    }
}