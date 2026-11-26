<?php

return [

    /*
    | Optional shared secret the setup wizard must be given. Set LABMAN_SETUP_TOKEN
    | before first start if the instance is reachable by people you do not trust.
    */
    'setup_token' => env('LABMAN_SETUP_TOKEN'),

    /*
    | "main" (default) is the full app. "node" is a read-only dashboard for one zone that a main LabMan pushes to;
    | it needs LABMAN_NODE_TOKEN, the secret the main LabMan sends with every push.
    */
    'mode' => env('LABMAN_MODE', 'main') === 'node' ? 'node' : 'main',
    'node_token' => env('LABMAN_NODE_TOKEN'),

    /*
    | The four screens whose visibility an admin can configure.
    */
    'screens' => ['services', 'servers', 'settings', 'users'],

    /*
    | Visibility levels, least to most restrictive.
    */
    'visibility_levels' => ['everyone', 'users', 'admins'],

    'registration_modes' => ['off', 'open', 'approval'],

    /*
    | Defaults for every runtime setting (stored in the `settings` table).
    */
    'defaults' => [
        'title' => 'LabMan',
        'check_interval' => 30,
        'timeout' => 4,
        'ssh_user' => '',
        'ssh_target' => 'address',
        'registration_mode' => 'approval',
        'icons' => '1',
        'local_domains' => '',
        'public_domains' => '',
        'npm_cert_local' => '',
        'npm_cert_public' => '',
        'visibility.services' => 'users',
        'visibility.servers' => 'users',
        'visibility.settings' => 'admins',
        'visibility.users' => 'admins',
    ],

    /*
    | The MCP endpoint (POST /mcp). Tokens are created by admins under Settings.
    | LABMAN_MCP_ALLOWED_ORIGINS: comma separated browser origins allowed in addition to our own.
    */
    'mcp' => [
        'enabled' => (bool) env('LABMAN_MCP_ENABLED', true),
        'allowed_origins' => env('LABMAN_MCP_ALLOWED_ORIGINS', ''),
    ],

    /*
    | Git (inventory sync). Only http(s) remotes are accepted. LABMAN_GIT_ALLOW_LOCAL lets tests use
    | local file remotes; never enable it in production.
    */
    'git' => [
        'allow_local' => (bool) env('LABMAN_GIT_ALLOW_LOCAL', false),
        'timeout' => 90,
        'askpass' => base_path('docker/git-askpass.sh'),
    ],

    /*
    | Icons are downloaded once from the public Dashboard Icons library via jsDelivr and cached on disk.
    | LABMAN_ICONS_FETCH=false stops every download (cached icons are still shown); the Settings page has a switch too.
    */
    'icons' => [
        'fetch' => (bool) env('LABMAN_ICONS_FETCH', true),
        'path' => storage_path('app/icons'),
    ],

    'limits' => [
        'check_interval_min' => 5,
        'timeout_min' => 0.5,
        'timeout_max' => 60,
    ],

];
