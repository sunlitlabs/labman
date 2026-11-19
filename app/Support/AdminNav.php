<?php

namespace App\Support;

use App\Models\ApiToken;
use App\Models\User;

/**
 * The admin panel's navigation: which sections and pages the current viewer may see, and a small
 * status for each integration (connected or not) taken from what is stored, with no network calls.
 */
final class AdminNav
{
    /** Route-name patterns that belong to the admin panel (the main nav highlights "Admin" on any of them). */
    public const ROUTES = ['admin.*', 'settings.*', 'users.*', 'inventory.*', 'semaphore.*', 'npm.*', 'tokens.*'];

    /**
     * @return list<array{title: string, items: list<array{label: string, route: string, match: list<string>, status: ?bool, badge: int, hint: string}>}>
     */
    public static function sections(?User $user): array
    {
        $admin = Access::isAdmin($user);
        $sections = [];

        $site = [self::item('Overview', 'admin.index', ['admin.*'], 'Status of everything at a glance')];
        if (Access::allows($user, 'settings')) {
            $site[] = self::item('General', 'settings.index', ['settings.index'], 'Title, health checks, SSH defaults');
            $site[] = self::item('Access', 'settings.access', ['settings.access'], 'Registration and who sees what');
        }
        if (Access::allows($user, 'users')) {
            $site[] = self::item('Users', 'users.index', ['users.*'], 'Accounts, roles and approvals', badge: $admin ? User::query()->where('status', User::STATUS_PENDING)->count() : 0);
        }
        $sections[] = ['title' => 'Site', 'items' => $site];

        if ($admin) {
            $sections[] = ['title' => 'Integrations', 'items' => [
                self::item('Inventory (Ansible)', 'inventory.index', ['inventory.*'], 'Keep servers and the inventory in git in sync', status: Secrets::plain('inventory.repo_url') !== null),
                self::item('Semaphore', 'semaphore.index', ['semaphore.*'], 'Run playbooks', status: Secrets::plain('semaphore.url') !== null),
                self::item('Nginx Proxy Manager', 'npm.index', ['npm.*'], 'Import and push proxy hosts', status: Secrets::plain('npm.url') !== null),
            ]];

            $sections[] = ['title' => 'Tools', 'items' => [
                self::item('AI access (MCP)', 'settings.mcp', ['settings.mcp', 'tokens.*'], 'API tokens for AI assistants', badge: 0),
                self::item('Import and export', 'settings.data', ['settings.data'], 'YAML configuration'),
            ]];
        }

        return $sections;
    }

    /** Is any admin page open to this viewer? */
    public static function canEnter(?User $user): bool
    {
        return Access::isAdmin($user) || Access::allows($user, 'settings') || Access::allows($user, 'users');
    }

    public static function tokenCount(): int
    {
        return ApiToken::query()->count();
    }

    /** @param list<string> $match */
    private static function item(string $label, string $route, array $match, string $hint, ?bool $status = null, int $badge = 0): array
    {
        return compact('label', 'route', 'match', 'status', 'badge', 'hint');
    }
}
