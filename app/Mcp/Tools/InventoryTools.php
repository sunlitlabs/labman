<?php

namespace App\Mcp\Tools;

use App\Inventory\Connector;
use App\Inventory\InventoryException;
use App\Inventory\InventorySync;
use App\Inventory\RepoConfig;
use App\Mcp\Tool;
use App\Mcp\ToolException;
use Closure;

/**
 * Inventory sync for MCP clients: connect the repository, compare, review, apply. Like the UI it is
 * three steps: `inventory_compare` shows what matches and what does not, `inventory_review` turns the
 * user's choices into an exact diff plus a fingerprint, and `inventory_apply` only applies that reviewed result.
 */
final class InventoryTools
{
    private const CHOICES = [
        'type' => 'object',
        'description' => 'The decisions, keyed by the item key from inventory_compare. '
            .'For keys "pair:<id>" give {"fields": {"address"|"ssh_user"|"ssh_port"|"group": "labman"|"inventory"|"skip"}} ("labman" writes the LabMan value into the inventory, "inventory" copies the inventory value into LabMan). '
            .'For keys "srv:<id>" (only in LabMan) give {"action": "leave"|"add_inventory"|"delete_labman"}. '
            .'For keys "host:<name>" (only in the inventory) give {"action": "leave"|"add_labman"|"remove_inventory", "setup": {"name","address","group","ssh_user","ssh_port"}} where setup overrides the suggested values. '
            .'Anything left out is left alone.',
        'additionalProperties' => [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => ['leave', 'add_inventory', 'delete_labman', 'add_labman', 'remove_inventory']],
                'fields' => ['type' => 'object', 'additionalProperties' => ['type' => 'string', 'enum' => ['labman', 'inventory', 'skip']]],
                'setup' => ['type' => 'object'],
            ],
            'additionalProperties' => false,
        ],
    ];

    /** @return list<Tool> */
    public static function all(): array
    {
        return [
            new Tool('inventory_get_connection', 'Show the inventory repository connection', 'Whether LabMan is connected to the git repository holding the Ansible inventory, and its address, branch and file. The token is never shown.',
                [], [], 'admin', true, false, fn () => ['connected' => RepoConfig::exists(), 'connection' => RepoConfig::summary()]),

            new Tool('inventory_set_connection', 'Connect the inventory repository', 'Test and save the repository (https address, branch, inventory file path) and a personal access token (stored encrypted). The token passes through this conversation; entering it in the LabMan UI avoids that. The token may be omitted when only changing other settings for the same repository.',
                [
                    'repo_url' => Tool::str('The repository address, or simply a link to the inventory file in a git web UI (GitHub, GitLab, Gitea, Forgejo, Codeberg, Bitbucket), e.g. https://github.com/org/repo/blob/main/ansible/inventory/hosts.ini. Branch and path are taken from such a link.'),
                    'branch' => Tool::str('Branch to read and push to. Taken from the link when it has one, else main.'),
                    'path' => Tool::str('Inventory file path inside the repository (YAML or INI). Taken from the link when it has one.'),
                    'username' => Tool::str('Git user name. Normally omit it: it is worked out from the token.'),
                    'token' => Tool::str('Personal access token with read and write access to the repository contents.'),
                    'author_name' => Tool::str('Name on LabMan\'s commits (default LabMan).'),
                    'author_email' => Tool::str('E-mail on LabMan\'s commits (default labman@localhost).'),
                ], ['repo_url'], 'admin', false, false,
                fn (array $a) => self::guard(fn () => ['connected' => true] + Connector::connect($a) + ['connection' => RepoConfig::summary()])),

            new Tool('inventory_remove_connection', 'Disconnect the inventory repository', 'Delete the saved repository settings and token from LabMan. Nothing in the repository changes.',
                [], [], 'admin', false, true, function () {
                    RepoConfig::forget();

                    return ['connected' => false];
                }),

            new Tool('inventory_compare', 'Compare LabMan with the inventory', 'Pull the repository and line LabMan\'s servers up with the Ansible inventory hosts. Returns: in_sync (matching pairs), differences (matched servers whose address/ssh_user/ssh_port/group differ, with the allowed options per field), only_labman (servers missing from the inventory) and only_inventory (hosts missing from LabMan, with suggested setup values). Read-only. Present it to the user and ask what to do with each item before calling inventory_review.',
                [], [], 'admin', true, false, fn () => self::guard(function () {
                    $plan = InventorySync::fromStored()->compare();
                    unset($plan['candidates']);

                    return $plan;
                })),

            new Tool('inventory_review', 'Review inventory sync choices', 'Read-only. Turn the user\'s choices into the exact result: the git diff that would be pushed, the LabMan changes, the commit message and a fingerprint. Show the diff to the user and get approval before inventory_apply. An INI inventory is converted to a YAML file when written; delete_old also removes the INI file (Semaphore must then be pointed at the YAML file).',
                ['choices' => self::CHOICES, 'delete_old' => Tool::bool('When converting INI to YAML, also delete the old INI file in the same commit. Default false.')],
                ['choices'], 'admin', true, false, fn (array $a) => self::guard(fn () => InventorySync::fromStored()->review((array) $a['choices'], (bool) ($a['delete_old'] ?? false)))),

            new Tool('inventory_apply', 'Apply reviewed inventory sync', 'WRITES: commits and pushes the reviewed inventory change to the git repository, then updates LabMan (create/update/delete servers). Pass the same choices and delete_old as the review, and its fingerprint. Refused, with nothing applied, if the repository or LabMan changed since the review.',
                ['choices' => self::CHOICES, 'fingerprint' => Tool::str('The fingerprint returned by inventory_review.'), 'delete_old' => Tool::bool('Same value as in the review.')],
                ['choices', 'fingerprint'], 'admin', false, true,
                fn (array $a) => self::guard(fn () => InventorySync::fromStored()->apply((array) $a['choices'], (string) $a['fingerprint'], (bool) ($a['delete_old'] ?? false)))),
        ];
    }

    /** @param Closure(): array<string, mixed> $run */
    private static function guard(Closure $run): array
    {
        try {
            return $run();
        } catch (InventoryException $e) {
            throw new ToolException($e->getMessage());
        }
    }
}
