<?php

namespace App\Mcp;

use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Monitoring\StatusView;

/** Shapes models into the plain arrays MCP tools return. Never includes secrets. */
final class Present
{
    /** @return array<string, mixed> */
    public static function server(Server $server): array
    {
        $status = StatusView::of($server);

        return [
            'id' => $server->id,
            'name' => $server->name,
            'address' => $server->address,
            'domain' => $server->domain,
            'group' => $server->group,
            'ports' => $server->ports ?? [],
            'ssh_user' => $server->ssh_user,
            'ssh_port' => (int) $server->ssh_port,
            'ssh_command' => $server->sshCommand(),
            'notes' => $server->notes,
            'monitor' => (bool) $server->monitor,
            'check_ssh' => (bool) $server->check_ssh,
            'icon' => $server->icon,
            'state' => $status['state'],
            'stale' => $status['stale'],
            'checked_at' => $status['checked_at'],
            'checks' => $status['checks'],
        ];
    }

    /** @return array<string, mixed> */
    public static function service(Service $service): array
    {
        $status = StatusView::of($service);

        return [
            'id' => $service->id,
            'name' => $service->name,
            'domain' => $service->domain,
            'server' => $service->server?->name,
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
            'health_path' => $service->health_path,
            'monitor' => (bool) $service->monitor,
            'check_tcp' => (bool) $service->check_tcp,
            'icon' => $service->icon,
            'open_url' => $service->openUrl(),
            'backend_url' => $service->backendUrl(),
            'npm_host_id' => $service->npm_host_id,
            'state' => $status['state'],
            'stale' => $status['stale'],
            'checked_at' => $status['checked_at'],
            'checks' => $status['checks'],
        ];
    }

    /** @return array<string, mixed> */
    public static function user(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'status' => $user->status,
        ];
    }
}
