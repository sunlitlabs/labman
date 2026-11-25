<?php

namespace App\Mcp\Tools;

use App\Dns\AdguardClient;
use App\Dns\AdguardConnection;
use App\Dns\AdguardException;
use App\Dns\AdguardSync;
use App\Mcp\Tool;
use App\Mcp\ToolException;
use Closure;

/**
 * AdGuard Home tools: connect, then preview and apply the inside records of the DNS plan as DNS rewrites. Writing is
 * always two steps (a preview with fingerprints, then an apply naming the approved items), and nothing is ever
 * deleted. The saved login is never returned by any tool.
 */
final class AdguardTools
{
    /** @return list<Tool> */
    public static function all(): array
    {
        return [
            new Tool('adguard_get_connection', 'Show the AdGuard Home connection', 'Whether LabMan is connected to AdGuard Home, and to which address and user. The password is never shown.',
                [], [], 'admin', true, false, fn () => ['connected' => AdguardConnection::exists(), 'connection' => AdguardConnection::summary()]),

            new Tool('adguard_set_connection', 'Connect to AdGuard Home', 'Test and save the AdGuard Home address and login (stored encrypted). Note the password passes through this conversation; entering it in the LabMan UI avoids that. user name and password may be left out when AdGuard Home has no login.',
                [
                    'url' => Tool::str('AdGuard Home address, e.g. http://192.0.2.53:3000 (no path).'),
                    'username' => Tool::str('AdGuard Home user name (optional).'),
                    'password' => Tool::str('AdGuard Home password (optional).'),
                    'verify_tls' => Tool::bool('Verify the TLS certificate when the address is https (default true).'),
                ], ['url'], 'admin', false, false, fn (array $a) => self::run(function () use ($a) {
                    $url = AdguardConnection::cleanUrl((string) $a['url']);
                    $verify = (bool) ($a['verify_tls'] ?? true);
                    $user = (string) ($a['username'] ?? '');
                    $pass = (string) ($a['password'] ?? '');
                    $version = (new AdguardClient(['url' => $url, 'username' => $user, 'password' => $pass, 'verify_tls' => $verify]))->test();
                    AdguardConnection::save($url, $user, $pass, $verify);

                    return ['connected' => true, 'adguard_version' => $version, 'connection' => AdguardConnection::summary(), 'next' => 'adguard_preview shows which rewrites would be written.'];
                })),

            new Tool('adguard_test_connection', 'Test the AdGuard Home connection', 'Call AdGuard Home with the saved login and report its version.',
                [], [], 'admin', true, false, fn () => self::run(fn () => ['ok' => true, 'adguard_version' => AdguardClient::fromStored()->test()])),

            new Tool('adguard_remove_connection', 'Disconnect from AdGuard Home', 'Delete the saved AdGuard Home address and login from LabMan. Nothing in AdGuard Home is changed.',
                [], [], 'admin', false, true, function () {
                    AdguardConnection::forget();

                    return ['connected' => false];
                }),

            new Tool('adguard_preview', 'Preview writing the DNS plan to AdGuard Home', 'Read-only. Compare the inside records of the DNS plan with the DNS rewrites in AdGuard Home and list every rewrite that would be added or changed. Rewrites AdGuard has that the plan does not mention are only counted and left alone. Show the user the items, then call adguard_apply with what they approve.',
                [], [], 'admin', true, false, fn () => self::run(function () {
                    $plan = (new AdguardSync(AdguardClient::fromStored()))->plan();
                    $items = array_map(fn ($i) => array_intersect_key($i, array_flip(['key', 'title', 'action', 'zone', 'changes', 'warnings', 'errors', 'fingerprint'])), $plan['items']);

                    return ['items' => $items, 'other_rewrites_left_alone' => $plan['others'], 'next' => 'Show the user these items, then adguard_apply with the ones they approve (key + fingerprint). Nothing is written until then.'];
                })),

            new Tool('adguard_apply', 'Write approved rewrites to AdGuard Home', 'WRITES to the live AdGuard Home: adds or changes the DNS rewrites of the previewed items the user approved. Never deletes. Refused if anything changed since the preview, and for an item with an error.',
                ['items' => [
                    'type' => 'array',
                    'description' => 'The preview items to apply, copied exactly from the preview: {key, fingerprint}.',
                    'items' => ['type' => 'object', 'properties' => ['key' => ['type' => 'string'], 'fingerprint' => ['type' => 'string']], 'required' => ['key', 'fingerprint'], 'additionalProperties' => false],
                ]], ['items'], 'admin', false, true, fn (array $a) => self::run(function () use ($a) {
                    $selected = [];
                    foreach ($a['items'] as $item) {
                        if (! is_array($item) || ! isset($item['key'], $item['fingerprint'])) {
                            throw new ToolException('Each item needs a key and a fingerprint, exactly as returned by the preview.');
                        }
                        $selected[(string) $item['key']] = (string) $item['fingerprint'];
                    }

                    return (new AdguardSync(AdguardClient::fromStored()))->apply($selected);
                })),
        ];
    }

    /** Run an AdGuard operation and turn its failures into tool errors. */
    private static function run(Closure $run): array
    {
        try {
            return $run();
        } catch (AdguardException $e) {
            throw new ToolException($e->getMessage());
        }
    }
}
