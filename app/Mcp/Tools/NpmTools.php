<?php

namespace App\Mcp\Tools;

use App\Mcp\Tool;
use App\Mcp\ToolException;
use App\Npm\Connection;
use App\Npm\NpmClient;
use App\Npm\NpmException;
use App\Npm\NpmSync;
use Closure;

/**
 * Nginx Proxy Manager tools. Import/push are always two steps: a *_preview that lists
 * every change with a fingerprint, then a *_apply naming the items (key + fingerprint)
 * the user approved. The saved NPM password is never returned by any tool.
 */
final class NpmTools
{
    private const SELECTION = [
        'type' => 'array',
        'description' => 'The preview items to apply, copied exactly from the preview: {key, fingerprint}. Items whose fingerprint no longer matches are refused.',
        'items' => [
            'type' => 'object',
            'properties' => ['key' => ['type' => 'string'], 'fingerprint' => ['type' => 'string']],
            'required' => ['key', 'fingerprint'],
            'additionalProperties' => false,
        ],
    ];

    /** @return list<Tool> */
    public static function all(): array
    {
        return [
            new Tool('npm_get_connection', 'Show the NPM connection', 'Whether LabMan is connected to Nginx Proxy Manager, and to which address and account. The password is never shown.',
                [], [], 'admin', true, false, fn () => ['connected' => Connection::exists(), 'connection' => Connection::summary()]),

            new Tool('npm_set_connection', 'Connect to Nginx Proxy Manager', 'Test and save the NPM address and login (stored encrypted). Note the password passes through this conversation; entering it in the LabMan UI avoids that. Use a dedicated NPM user without two-factor authentication.',
                [
                    'url' => Tool::str('NPM admin address, e.g. http://192.0.2.5:81 (no path).'),
                    'email' => Tool::str('NPM account e-mail.'),
                    'password' => Tool::str('NPM account password.'),
                    'verify_tls' => Tool::bool('Verify the TLS certificate when the address is https (default true).'),
                ], ['url', 'email', 'password'], 'admin', false, false, function (array $a) {
                    return self::npm(function () use ($a) {
                        $url = Connection::cleanUrl((string) $a['url']);
                        $verify = (bool) ($a['verify_tls'] ?? true);
                        $version = (new NpmClient(['url' => $url, 'email' => (string) $a['email'], 'password' => (string) $a['password'], 'verify_tls' => $verify]))->test();
                        Connection::save($url, (string) $a['email'], (string) $a['password'], $verify);

                        return ['connected' => true, 'npm_version' => $version, 'connection' => Connection::summary()];
                    });
                }),

            new Tool('npm_test_connection', 'Test the NPM connection', 'Log in to NPM with the saved credentials and report its version.',
                [], [], 'admin', true, false, fn () => self::npm(fn () => ['ok' => true, 'npm_version' => NpmClient::fromStored()->test()])),

            new Tool('npm_remove_connection', 'Disconnect from NPM', 'Delete the saved NPM address and login from LabMan. Nothing in NPM is changed.',
                [], [], 'admin', false, true, function () {
                    Connection::forget();

                    return ['connected' => false];
                }),

            new Tool('npm_import_preview', 'Preview importing from NPM', 'Read-only. Compare NPM proxy hosts with LabMan services and list every service that would be created or updated (no NPM or LabMan data is changed). Show the result to the user, then call npm_import_apply with what they approve.',
                [], [], 'admin', true, false, fn () => self::npm(fn () => ['items' => self::present((new NpmSync(NpmClient::fromStored()))->importPlan())])),

            new Tool('npm_import_apply', 'Apply an NPM import', 'Create/update LabMan services for the previewed items the user approved. Does not write to NPM.',
                ['items' => self::SELECTION], ['items'], 'admin', false, false,
                fn (array $a) => self::npm(fn () => (new NpmSync(NpmClient::fromStored()))->applyImport(self::selection($a)))),

            new Tool('npm_push_preview', 'Preview pushing to NPM', 'Read-only. Compare LabMan services that have a domain with NPM proxy hosts and list every proxy host that would be updated or created in NPM. Show the user the exact diff, then call npm_push_apply with what they approve.',
                [], [], 'admin', true, false, fn () => self::npm(fn () => ['items' => self::present((new NpmSync(NpmClient::fromStored()))->pushPlan())])),

            new Tool('npm_push_apply', 'Apply a push to NPM', 'WRITES to the live Nginx Proxy Manager: updates or creates proxy hosts for the previewed items the user approved. Never deletes. Refused if anything changed since the preview.',
                ['items' => self::SELECTION], ['items'], 'admin', false, true,
                fn (array $a) => self::npm(fn () => (new NpmSync(NpmClient::fromStored()))->applyPush(self::selection($a)))),
        ];
    }

    /** Run an NPM operation and turn its failures into tool errors. */
    private static function npm(Closure $run): array
    {
        try {
            return $run();
        } catch (NpmException $e) {
            throw new ToolException($e->getMessage());
        }
    }

    /** @param list<array<string, mixed>> $plan @return list<array<string, mixed>> */
    private static function present(array $plan): array
    {
        return array_map(
            fn ($i) => array_intersect_key($i, array_flip(['key', 'title', 'action', 'changes', 'warnings', 'fingerprint'])),
            $plan,
        );
    }

    /** @return array<string, string> key => fingerprint */
    private static function selection(array $a): array
    {
        $selected = [];
        foreach ($a['items'] as $item) {
            if (! is_array($item) || ! isset($item['key'], $item['fingerprint'])) {
                throw new ToolException('Each item needs a key and a fingerprint, exactly as returned by the preview.');
            }
            $selected[(string) $item['key']] = (string) $item['fingerprint'];
        }

        if ($selected === []) {
            throw new ToolException('Select at least one previewed item to apply.');
        }

        return $selected;
    }
}
