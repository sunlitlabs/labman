<?php

namespace App\Mcp\Tools;

use App\Mcp\McpContext;
use App\Mcp\Tool;
use App\Mcp\ToolException;
use App\Models\ProxyLog;
use App\Npm\Connection;
use App\Npm\NpmAuto;
use App\Npm\NpmClient;
use App\Npm\NpmException;
use App\Npm\NpmSync;
use App\Npm\PlanText;
use App\Support\ZoneStatus;
use Closure;

/**
 * Nginx Proxy Manager tools. Import/push are always two steps: a *_preview that lists
 * every change with a fingerprint, then a *_apply naming the items (key + fingerprint)
 * the user approved. The saved NPM password is never returned by any tool.
 *
 * Each zone has its own proxy. Every tool here takes an optional `zone`; without one it acts on the default zone
 * and, when there are several zones, says so in a `hint`. (npm_preview_all / npm_apply_all cover every proxy.)
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
        $zone = ZoneTools::zoneProperty();

        return [
            new Tool('npm_get_connection', 'Show the NPM connection', 'Whether LabMan is connected to Nginx Proxy Manager for a zone (the default zone when none is given), and to which address and account. The password is never shown.',
                $zone, [], 'admin', true, false, function (array $a) {
                    $z = ZoneTools::zone($a);

                    return ['zone' => $z->name, 'connected' => Connection::exists($z), 'connection' => Connection::summary($z)] + ZoneTools::hint($a, $z);
                }),

            new Tool('npm_set_connection', 'Connect to Nginx Proxy Manager', 'Test and save the NPM address and login for a zone (stored encrypted; each zone has its own proxy). Note the password passes through this conversation; entering it in the LabMan UI avoids that. Use a dedicated NPM user without two-factor authentication.',
                $zone + [
                    'url' => Tool::str('NPM admin address, e.g. http://192.0.2.5:81 (no path).'),
                    'email' => Tool::str('NPM account e-mail.'),
                    'password' => Tool::str('NPM account password.'),
                    'verify_tls' => Tool::bool('Verify the TLS certificate when the address is https (default true).'),
                ], ['url', 'email', 'password'], 'admin', false, false, function (array $a) {
                    $z = ZoneTools::zone($a);

                    return self::npm(function () use ($a, $z) {
                        $url = Connection::cleanUrl((string) $a['url']);
                        $verify = (bool) ($a['verify_tls'] ?? true);
                        $version = (new NpmClient(['url' => $url, 'email' => (string) $a['email'], 'password' => (string) $a['password'], 'verify_tls' => $verify]))->test();
                        Connection::save($url, (string) $a['email'], (string) $a['password'], $verify, $z);
                        ZoneStatus::forget($z);

                        return ['zone' => $z->name, 'connected' => true, 'npm_version' => $version, 'connection' => Connection::summary($z), 'next' => 'npm_push_preview (or npm_import_preview) with this zone shows what would change.'] + ZoneTools::hint($a, $z);
                    });
                }),

            new Tool('npm_test_connection', 'Test the NPM connection', 'Log in to a zone\'s NPM with the saved credentials and report its version.',
                $zone, [], 'admin', true, false, function (array $a) {
                    $z = ZoneTools::zone($a);

                    return self::npm(fn () => ['zone' => $z->name, 'ok' => true, 'npm_version' => NpmClient::fromStored($z)->test()] + ZoneTools::hint($a, $z));
                }),

            new Tool('npm_remove_connection', 'Disconnect from NPM', 'Delete the saved NPM address and login of a zone from LabMan. Nothing in NPM is changed.',
                $zone, [], 'admin', false, true, function (array $a) {
                    $z = ZoneTools::zone($a);
                    Connection::forget($z);
                    ZoneStatus::forget($z);

                    return ['zone' => $z->name, 'connected' => false] + ZoneTools::hint($a, $z);
                }),

            new Tool('npm_get_auto_update', 'Show the automatic NPM update setting', 'Whether LabMan updates NPM by itself whenever a service, its server\'s address or a certificate changes (one switch for every proxy), and the result of the last automatic update. Automatic updates only add and change proxy hosts; they never delete.',
                [], [], 'admin', true, false, fn () => self::autoState() + ['last' => NpmAuto::last()]),

            new Tool('npm_set_auto_update', 'Turn automatic NPM updates on or off', 'Switch automatic updates of the connected proxies on or off. On means LabMan writes proxy host changes to NPM by itself when something changes; ask the user first.',
                ['enabled' => Tool::bool('True to update NPM automatically, false to only use previews.')], ['enabled'], 'admin', false, false, function (array $a) {
                    NpmAuto::setEnabled((bool) $a['enabled']);

                    return self::autoState();
                }),

            new Tool('npm_import_preview', 'Preview importing from NPM', 'Read-only. Compare a zone\'s NPM proxy hosts with the zone\'s LabMan services and list every service that would be created or updated (no NPM or LabMan data is changed). Show the result to the user, then call npm_import_apply with what they approve.',
                $zone, [], 'admin', true, false, function (array $a) {
                    $z = ZoneTools::zone($a);

                    return self::npm(fn () => ['zone' => $z->name, 'items' => self::present((new NpmSync(NpmClient::fromStored($z), $z))->importPlan(), $z->name, true), 'next' => 'Show the user these items, then npm_import_apply with the ones they approve (key + fingerprint).'] + ZoneTools::hint($a, $z));
                }),

            new Tool('npm_import_apply', 'Apply an NPM import', 'Create/update LabMan services for the previewed items the user approved. Does not write to NPM.',
                $zone + ['items' => self::SELECTION], ['items'], 'admin', false, false, function (array $a, McpContext $ctx) {
                    $z = ZoneTools::zone($a);

                    return self::npm(function () use ($a, $z, $ctx) {
                        $result = (new NpmSync(NpmClient::fromStored($z), $z))->applyImport(self::selection($a));
                        ProxyLog::record($z, 'import', $result['results'], 'MCP ('.$ctx->token->name.')');
                        ZoneStatus::forget($z);

                        return ['zone' => $z->name] + $result + ZoneTools::hint($a, $z);
                    });
                }),

            new Tool('npm_push_preview', 'Preview pushing to NPM', 'Read-only. Compare the zone\'s LabMan services that have a domain with its NPM proxy hosts and list every proxy host that would be updated or created, including mirrors of other zones\' hosts and orphaned mirrors and unused hosts LabMan created (offered as action delete). Each item has a plain-language summary, warnings and, if it cannot be applied, errors. Show the user the exact diff, then call npm_push_apply with what they approve.',
                $zone, [], 'admin', true, false, function (array $a) {
                    $z = ZoneTools::zone($a);

                    return self::npm(fn () => ['zone' => $z->name, 'items' => self::present((new NpmSync(NpmClient::fromStored($z), $z))->pushPlan(), $z->name, false), 'next' => 'Show the user these items, then npm_push_apply (same zone) with the ones they approve (key + fingerprint). Nothing is written until then.'] + ZoneTools::hint($a, $z));
                }),

            new Tool('npm_push_apply', 'Apply a push to NPM', 'WRITES to the live Nginx Proxy Manager of a zone: updates or creates proxy hosts for the previewed items the user approved (and requests a certificate only where the previewed item says so). An item with action delete DELETES a proxy host from NPM, only one LabMan created, and only if the user approved that item. Refused if anything changed since the preview, and for an item with an error.',
                $zone + ['items' => self::SELECTION], ['items'], 'admin', false, true, function (array $a, McpContext $ctx) {
                    $z = ZoneTools::zone($a);

                    return self::npm(function () use ($a, $z, $ctx) {
                        $result = (new NpmSync(NpmClient::fromStored($z), $z))->applyPush(self::selection($a));
                        ProxyLog::record($z, 'preview', $result['results'], 'MCP ('.$ctx->token->name.')');
                        ZoneStatus::forget($z);

                        return ['zone' => $z->name] + $result + ZoneTools::hint($a, $z);
                    });
                }),
        ];
    }

    /** @return array{enabled: bool, active: bool, connected: bool} the switch, whether it is acting (needs a connected proxy), and whether any proxy is connected */
    private static function autoState(): array
    {
        return ['enabled' => \App\Support\Secrets::plain('npm.auto') !== '0', 'active' => NpmAuto::enabled(), 'connected' => Connection::anyExists()];
    }

    /** Run an NPM operation and turn its failures into tool errors. */
    private static function npm(Closure $run): array
    {
        return ZoneTools::npm($run);
    }

    /**
     * What a client needs from a preview item: its key and fingerprint, the changes, warnings, errors and one plain sentence.
     *
     * @param  list<array<string, mixed>>  $plan
     * @return list<array<string, mixed>>
     */
    public static function present(array $plan, string $proxy = 'Nginx Proxy Manager', bool $isImport = false): array
    {
        return array_map(function ($i) use ($proxy, $isImport) {
            $item = array_intersect_key($i, array_flip(['key', 'title', 'action', 'changes', 'warnings', 'fingerprint']));
            if (! empty($i['errors'])) {
                $item['errors'] = $i['errors'];
            }
            $item['summary'] = PlanText::describe($i, $proxy, $isImport);

            return $item;
        }, $plan);
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
