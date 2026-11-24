<?php

namespace App\Inventory;

use App\Support\Secrets;

/** What the last "pull and compare" found (or why it failed), so the pages can always state a result. Holds no secrets. */
final class CheckStatus
{
    private const KEY = 'inventory.last_check';

    /** @param array<string, mixed> $plan the result of InventorySync::compare() */
    public static function succeeded(array $plan, int $servers): void
    {
        self::store([
            'ok' => true, 'at' => $plan['checked_at'], 'branch' => $plan['branch'], 'path' => $plan['path'], 'head' => $plan['head'],
            'hosts' => $plan['host_count'], 'servers' => $servers,
            'in_sync' => count($plan['in_sync']), 'differ' => count($plan['differences']),
            'only_labman' => count($plan['only_labman']), 'only_inventory' => count($plan['only_inventory']),
            'message' => null,
        ]);
    }

    public static function failed(string $message, string $branch, string $path): void
    {
        self::store(['ok' => false, 'at' => now()->toIso8601String(), 'branch' => $branch, 'path' => $path, 'head' => null, 'message' => mb_substr($message, 0, 500)]);
    }

    /** @return array<string, mixed>|null */
    public static function last(): ?array
    {
        $raw = Secrets::plain(self::KEY);
        $data = $raw ? json_decode($raw, true) : null;

        return is_array($data) && isset($data['ok'], $data['at']) ? $data : null;
    }

    public static function forget(): void
    {
        Secrets::forget([self::KEY]);
    }

    /** One sentence, e.g. "Checked commit abc1234 on main: 11 in sync, 0 differences." */
    public static function sentence(array $c): string
    {
        return 'Checked commit '.substr((string) $c['head'], 0, 7).' on '.$c['branch'].': '.self::counts($c).'.';
    }

    /** "11 in sync, 0 differences", plus the one-sided counts when there are any. */
    public static function counts(array $c): string
    {
        $parts = [$c['in_sync'].' in sync', $c['differ'].' '.($c['differ'] === 1 ? 'difference' : 'differences')];
        if ($c['only_labman'] > 0) {
            $parts[] = $c['only_labman'].' only in LabMan';
        }
        if ($c['only_inventory'] > 0) {
            $parts[] = $c['only_inventory'].' only in the inventory';
        }

        return implode(', ', $parts);
    }

    /**
     * The stages of a pull and compare, in order, for the progress panel.
     *
     * @return list<string>
     */
    public static function steps(string $path, int $servers): array
    {
        return [
            'Connecting to the repository',
            'Pulling the latest commit',
            'Reading '.$path,
            'Comparing the inventory with '.$servers.' '.($servers === 1 ? 'server' : 'servers'),
        ];
    }

    /** @param array<string, mixed> $data */
    private static function store(array $data): void
    {
        Secrets::putPlain(self::KEY, json_encode($data));
    }
}
