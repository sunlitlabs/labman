<?php

namespace App\Mcp\Tools;

use App\Config\ConfigException;
use App\Config\YamlConfig;
use App\Domain\ServerInput;
use App\Domain\ServiceInput;
use App\Domain\SettingsInput;
use App\Domain\UserAdmin;
use App\Mcp\McpContext;
use App\Mcp\Present;
use App\Mcp\Tool;
use App\Mcp\ToolException;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Monitoring\Diagnoser;
use App\Monitoring\HealthChecker;
use App\Monitoring\StatusView;

/**
 * Everything an admin can do in the UI apart from API-token management and NPM:
 * read the catalog and health, change servers, services, settings and users, import/export YAML.
 */
final class CatalogTools
{
    private const IDENT = [
        'id' => ['type' => 'integer', 'description' => 'Numeric id (use this or name).'],
        'name' => ['type' => 'string', 'description' => 'Exact name (use this or id).'],
    ];

    /** @return list<Tool> */
    public static function all(): array
    {
        $server = [
            'name' => Tool::str('Unique server name.'),
            'address' => Tool::str('IP address (IPv6 allowed) or host name that health checks connect to.'),
            'domain' => Tool::str('Optional public domain, e.g. nas.example.com.'),
            'group' => Tool::str('Optional group heading.'),
            'ports' => Tool::ints('Extra TCP ports to check besides SSH.'),
            'ssh_user' => Tool::str('SSH user for Copy SSH (overrides the default).'),
            'ssh_port' => Tool::int('SSH port, default 22.'),
            'notes' => Tool::str('Free text, max 500 characters.'),
            'monitor' => Tool::bool('Health-check this server (default true). False hides its status ("not monitored").'),
            'check_ssh' => Tool::bool('Count the SSH port as a health check (default true).'),
            'icon' => Tool::str('Icon name from the icon library, e.g. proxmox. Empty for none.'),
        ];

        $service = [
            'name' => Tool::str('Unique service name.'),
            'domain' => Tool::str('Optional public domain; enables the public HTTPS check and is what clicking the service opens.'),
            'server' => Tool::str('Name of the server it runs on (use this OR address).'),
            'address' => Tool::str('Its own IP/host name when it is not on a listed server (use this OR server).'),
            'port' => Tool::int('Backend port; default 80, or 443 for https.'),
            'scheme' => Tool::str('Backend scheme.', ['http', 'https']),
            'path' => Tool::str('URL path, must start with /. Default /.'),
            'group' => Tool::str('Optional group heading.'),
            'expect' => Tool::str('Healthy HTTP statuses: a number, a range, or a comma list, e.g. 200, 401 or 200-399,401. Default 200-499 (any answer except a server error).'),
            'health_path' => Tool::str('Path to request for the health check if different from `path` (e.g. /health). Must start with /.'),
            'monitor' => Tool::bool('Health-check this service (default true). False hides its status ("not monitored").'),
            'check_tcp' => Tool::bool('An open backend port counts as up even if the web check fails (default true).'),
            'icon' => Tool::str('Icon name from the icon library, e.g. jellyfin. Empty for none.'),
            'verify_tls' => Tool::bool('Verify the certificate of the public check (default true).'),
            'public_check' => Tool::bool('Also check https://<domain><path> (default true).'),
            'websockets' => Tool::bool('Informational flag.'),
            'notes' => Tool::str('Free text, max 500 characters.'),
        ];

        $settings = [
            'title' => Tool::str('Site title (1-60 characters).'),
            'check_interval' => Tool::num('Seconds between health-check rounds, minimum 5.'),
            'timeout' => Tool::num('Per-check timeout in seconds, 0.5 to 60.'),
            'ssh_user' => Tool::str('Default SSH user for Copy SSH.'),
            'ssh_target' => Tool::str('Which name Copy SSH connects to.', ['address', 'domain']),
            'registration_mode' => Tool::str('Who may register: off, open, or approval (open, admin approval required).', config('labman.registration_modes')),
            'visibility' => [
                'type' => 'object',
                'description' => 'Who may see each screen: everyone, users (signed-in), or admins.',
                'properties' => array_fill_keys(config('labman.screens'), Tool::str('Visibility level.', config('labman.visibility_levels'))),
                'additionalProperties' => false,
            ],
        ];

        $userFields = [
            'name' => Tool::str('Display name.'),
            'email' => Tool::str('Email address (used to sign in).'),
            'role' => Tool::str('Role.', ['user', 'admin']),
            'status' => Tool::str('Account status.', ['active', 'pending', 'disabled']),
            'password' => Tool::str('Password, minimum 8 characters.'),
        ];

        return [
            // ------------------------------------------------------------------ read
            new Tool('list_services', 'List services', 'List all services with their stored health (state, per-check details, URLs). Optional filters.',
                ['group' => Tool::str('Only this group.'), 'state' => Tool::str('Only this state.', ['up', 'degraded', 'down', 'unknown', 'off'])],
                [], 'read', true, false, function (array $a) {
                    $rows = Service::query()->with('server')->orderBy('group')->orderBy('name')->get()->map(fn ($s) => Present::service($s));
                    $rows = $rows->when($a['group'] ?? null, fn ($c, $g) => $c->where('group', $g))
                        ->when($a['state'] ?? null, fn ($c, $s) => $c->where('state', $s));

                    return ['services' => $rows->values()->all()];
                }),

            new Tool('list_servers', 'List servers', 'List all servers with their stored health and ready-to-use SSH commands. Optional filters.',
                ['group' => Tool::str('Only this group.'), 'state' => Tool::str('Only this state.', ['up', 'degraded', 'down', 'unknown'])],
                [], 'read', true, false, function (array $a) {
                    $rows = Server::query()->orderBy('group')->orderBy('name')->get()->map(fn ($s) => Present::server($s));
                    $rows = $rows->when($a['group'] ?? null, fn ($c, $g) => $c->where('group', $g))
                        ->when($a['state'] ?? null, fn ($c, $s) => $c->where('state', $s));

                    return ['servers' => $rows->values()->all()];
                }),

            new Tool('get_status', 'Get status summary', 'Counts of up/degraded/down/unknown for services and servers, plus the name and state of every item. Cheap way to see what is wrong.',
                [], [], 'read', true, false, function () {
                    $services = Service::orderBy('name')->get();
                    $servers = Server::orderBy('name')->get();
                    $line = fn ($m) => ['name' => $m->name, 'state' => StatusView::of($m)['state']];

                    return [
                        'summary' => ['services' => StatusView::summary($services), 'servers' => StatusView::summary($servers)],
                        'services' => $services->map($line)->all(),
                        'servers' => $servers->map($line)->all(),
                    ];
                }),

            new Tool('get_settings', 'Get settings', 'Current settings: title, check interval/timeout, SSH defaults, registration mode and per-screen visibility.',
                [], [], 'read', true, false, fn () => ['settings' => SettingsInput::current()]),

            new Tool('export_yaml', 'Export configuration as YAML', 'The whole configuration (settings, servers, services) in the LabMan YAML format.',
                [], [], 'read', true, false, fn () => ['yaml' => YamlConfig::export()]),

            // ----------------------------------------------------------------- admin
            new Tool('run_health_check', 'Run a health check now', 'Run one health-check round immediately (every server and service) and return the new states. Takes up to a couple of timeouts.',
                [], [], 'admin', false, false, function () {
                    @set_time_limit(180);
                    $summary = app(HealthChecker::class)->run();

                    return $summary + ['servers_status' => StatusView::summary(Server::all()), 'services_status' => StatusView::summary(Service::all())];
                }),

            new Tool('diagnose_service', 'Diagnose a service health check', 'Probe one service several ways (port, http and https, with and without its domain as Host header, common health paths, other common web ports on the same address) and say what would make its check pass. Changes nothing unless apply=true, which saves the recommended settings.',
                self::IDENT + ['apply' => Tool::bool('Save the recommended settings (default false).')],
                [], 'admin', false, false, function (array $a) {
                    @set_time_limit(120);
                    $model = self::service($a)->load('server');
                    $result = (new Diagnoser)->run($model, \App\Support\Settings::timeout());

                    if (($a['apply'] ?? false) && ! empty($result['changes'])) {
                        $model->update(ServiceInput::validate($result['changes'], $model));
                        $result['applied'] = true;
                    }

                    return ['service' => $model->name, 'diagnosis' => $result];
                }),

            new Tool('create_server', 'Add a server', 'Add a server. Validation is identical to the admin form.',
                $server, ['name', 'address'], 'admin', false, false,
                fn (array $a) => ['server' => Present::server(Server::create(ServerInput::validate($a)))]),

            new Tool('update_server', 'Update a server', 'Change fields of an existing server; omitted fields keep their value. Use new_name to rename.',
                self::IDENT + ['new_name' => Tool::str('Rename to this.')] + array_diff_key($server, ['name' => 1]),
                [], 'admin', false, false, function (array $a) {
                    $model = self::server($a);
                    $changes = self::changes($a);
                    $model->update(ServerInput::validate($changes, $model));

                    return ['server' => Present::server($model->fresh())];
                }),

            new Tool('delete_server', 'Delete a server', 'Delete a server. Services on it keep existing but lose their address until you point them elsewhere.',
                self::IDENT, [], 'admin', false, true, function (array $a) {
                    $model = self::server($a);
                    $model->delete();

                    return ['deleted' => $model->name];
                }),

            new Tool('create_service', 'Add a service', 'Add a service. Give either `server` (a server name) or `address`, not both.',
                $service, ['name'], 'admin', false, false,
                fn (array $a) => ['service' => Present::service(Service::create(ServiceInput::validate(self::serviceInput($a)))->load('server'))]),

            new Tool('update_service', 'Update a service', 'Change fields of an existing service; omitted fields keep their value. Use new_name to rename. Setting server clears address and vice versa.',
                self::IDENT + ['new_name' => Tool::str('Rename to this.')] + array_diff_key($service, ['name' => 1]),
                [], 'admin', false, false, function (array $a) {
                    $model = self::service($a);
                    $model->update(ServiceInput::validate(self::serviceInput(self::changes($a)), $model));

                    return ['service' => Present::service($model->fresh('server'))];
                }),

            new Tool('delete_service', 'Delete a service', 'Delete a service. (This never touches Nginx Proxy Manager.)',
                self::IDENT, [], 'admin', false, true, function (array $a) {
                    $model = self::service($a);
                    $model->delete();

                    return ['deleted' => $model->name];
                }),

            new Tool('update_settings', 'Update settings', 'Change settings; omitted ones keep their value. This includes registration mode and who may see each screen, so changing it can expose or hide data.',
                $settings, [], 'admin', false, false, fn (array $a) => ['settings' => SettingsInput::update($a)]),

            new Tool('import_yaml', 'Import YAML configuration', 'Validate and import a LabMan YAML document (upsert by name). With replace=true, servers and services missing from the document are DELETED. Use dry_run=true to validate only.',
                [
                    'yaml' => Tool::str('The YAML document with servers and services (and optional settings).'),
                    'replace' => Tool::bool('Delete servers and services that are not in the document. Default false.'),
                    'dry_run' => Tool::bool('Only validate; change nothing. Default false.'),
                ], ['yaml'], 'admin', false, true, function (array $a) {
                    try {
                        $config = YamlConfig::parse((string) $a['yaml']);
                    } catch (ConfigException $e) {
                        throw new ToolException("The YAML is not valid:\n- ".implode("\n- ", $e->errors));
                    }

                    if ($a['dry_run'] ?? false) {
                        return ['valid' => true, 'servers' => count($config['servers']), 'services' => count($config['services'])];
                    }

                    return YamlConfig::import($config, (bool) ($a['replace'] ?? false));
                }),

            new Tool('list_users', 'List users', 'List all user accounts (id, name, email, role, status).',
                [], [], 'admin', true, false, fn () => ['users' => User::orderBy('role')->orderBy('name')->get()->map(fn ($u) => Present::user($u))->all()]),

            new Tool('create_user', 'Create a user', 'Create a user account (active user by default).',
                $userFields, ['name', 'email', 'password'], 'admin', false, false,
                fn (array $a, McpContext $ctx) => ['user' => Present::user(UserAdmin::save($a, null, $ctx->actor()))]),

            new Tool('update_user', 'Update a user', 'Change a user\'s name, email, role, status or password (a password reset). Identify by id or email. You cannot demote or disable the admin who owns this token, nor the last admin.',
                ['id' => Tool::int('User id (use this or current_email).'), 'current_email' => Tool::str('Current email (use this or id).')] + $userFields,
                [], 'admin', false, true, function (array $a, McpContext $ctx) {
                    $user = self::user($a);

                    return ['user' => Present::user(UserAdmin::save(array_diff_key($a, ['id' => 1, 'current_email' => 1]), $user, $ctx->actor()))];
                }),

            new Tool('approve_user', 'Approve a pending user', 'Activate a user whose account is waiting for approval.',
                ['id' => Tool::int('User id (use this or current_email).'), 'current_email' => Tool::str('Current email (use this or id).')],
                [], 'admin', false, false, fn (array $a) => ['user' => Present::user(UserAdmin::approve(self::user($a)))]),
        ];
    }

    // ------------------------------------------------------------- helpers

    private static function server(array $a): Server
    {
        $model = isset($a['id']) ? Server::find($a['id']) : (isset($a['name']) ? Server::where('name', $a['name'])->first() : null);

        return $model ?? throw new ToolException('Server not found: give an existing id or exact name.');
    }

    private static function service(array $a): Service
    {
        $model = isset($a['id']) ? Service::find($a['id']) : (isset($a['name']) ? Service::where('name', $a['name'])->first() : null);

        return $model ?? throw new ToolException('Service not found: give an existing id or exact name.');
    }

    private static function user(array $a): User
    {
        $model = isset($a['id']) ? User::find($a['id']) : (isset($a['current_email']) ? User::where('email', mb_strtolower($a['current_email']))->first() : null);

        return $model ?? throw new ToolException('User not found: give an existing id or current_email.');
    }

    /** Turn update arguments into field changes: drop the identifiers, apply new_name. */
    private static function changes(array $a): array
    {
        $changes = array_diff_key($a, ['id' => 1, 'new_name' => 1]);
        // `name` identifies the record in update calls; a rename goes through new_name.
        unset($changes['name']);
        if (isset($a['new_name'])) {
            $changes['name'] = $a['new_name'];
        }

        return $changes;
    }

    /** Map the friendly `server` (name) argument to server_id. */
    private static function serviceInput(array $a): array
    {
        if (array_key_exists('server', $a)) {
            $name = $a['server'];
            unset($a['server']);

            if ($name !== null && $name !== '') {
                $id = Server::where('name', $name)->value('id');
                if ($id === null) {
                    throw new ToolException("Server '{$name}' does not exist.");
                }
                $a['server_id'] = $id;
            }
        }

        return $a;
    }
}
