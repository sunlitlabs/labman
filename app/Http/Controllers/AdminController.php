<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Support\Access;
use App\Support\AdminNav;
use App\Support\Secrets;

/** The admin panel's landing page: what needs attention and where each integration stands. */
class AdminController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $admin = Access::isAdmin($user);

        $cards = [];

        if ($admin) {
            $cards[] = [
                'title' => 'Inventory (Ansible)', 'route' => 'inventory.index',
                'on' => Secrets::plain('inventory.repo_url') !== null,
                'detail' => Secrets::plain('inventory.repo_url') !== null ? ($this->shortRepo((string) Secrets::plain('inventory.repo_url')).' · '.Secrets::plain('inventory.path')) : 'Connect the git repository Semaphore reads its inventory from, and keep it lined up with your servers.',
                'action' => Secrets::plain('inventory.repo_url') !== null ? 'Compare now' : 'Set up', 'to' => Secrets::plain('inventory.repo_url') !== null ? 'inventory.compare' : 'inventory.index',
            ];
            $cards[] = [
                'title' => 'Semaphore', 'route' => 'semaphore.index', 'on' => Secrets::plain('semaphore.url') !== null,
                'detail' => Secrets::plain('semaphore.url') ?: 'Run your Ansible playbooks, with a confirmation first.',
                'action' => Secrets::plain('semaphore.url') !== null ? 'Open' : 'Set up', 'to' => 'semaphore.index',
            ];
            $cards[] = [
                'title' => 'Nginx Proxy Manager', 'route' => 'npm.index', 'on' => Secrets::plain('npm.url') !== null,
                'detail' => Secrets::plain('npm.url') ?: 'Import proxy hosts as services and push your edits back, with a preview.',
                'action' => Secrets::plain('npm.url') !== null ? 'Open' : 'Set up', 'to' => 'npm.index',
            ];
            $cards[] = [
                'title' => 'AdGuard Home', 'route' => 'dns.adguard', 'on' => Secrets::plain('adguard.url') !== null,
                'detail' => Secrets::plain('adguard.url') ?: 'Write the DNS plan\'s inside records to AdGuard Home as rewrites, with a preview.',
                'action' => Secrets::plain('adguard.url') !== null ? 'Open' : 'Set up', 'to' => 'dns.adguard',
            ];
            $cards[] = [
                'title' => 'AI access (MCP)', 'route' => 'settings.mcp', 'on' => AdminNav::tokenCount() > 0,
                'detail' => AdminNav::tokenCount() > 0 ? AdminNav::tokenCount().' API token(s)' : 'Create a token to let an AI assistant manage LabMan.',
                'action' => AdminNav::tokenCount() > 0 ? 'Manage' : 'Create a token', 'to' => 'settings.mcp',
            ];
        }

        return view('admin.index', [
            'cards' => $cards,
            'admin' => $admin,
            'pending' => $admin ? User::query()->where('status', User::STATUS_PENDING)->count() : 0,
            'counts' => ['servers' => Server::count(), 'services' => Service::count(), 'users' => Access::allows($user, 'users') ? User::count() : null],
            'canSettings' => Access::allows($user, 'settings'),
            'canUsers' => Access::allows($user, 'users'),
        ]);
    }

    private function shortRepo(string $url): string
    {
        return preg_replace('#^https?://#', '', rtrim($url, '/')) ?? $url;
    }
}
