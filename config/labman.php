<?php

return [

    /*
    | Optional shared secret the setup wizard must be given. Set LABMAN_SETUP_TOKEN
    | before first start if the instance is reachable by people you do not trust.
    */
    'setup_token' => env('LABMAN_SETUP_TOKEN'),

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

    'limits' => [
        'check_interval_min' => 5,
        'timeout_min' => 0.5,
        'timeout_max' => 60,
    ],

];
