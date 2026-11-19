<?php

namespace App\Inventory;

use App\Support\Secrets;

/** What the last inventory apply did (pushed a commit, or why it failed), so the pages can show it. Holds no secrets. */
final class SyncStatus
{
    private const KEY = 'inventory.last_sync';

    /** @param array{pushed?: bool, commit?: ?string, labman?: int} $result */
    public static function succeeded(array $result, string $branch, string $path): void
    {
        self::store([
            'ok' => true, 'at' => now()->toIso8601String(), 'branch' => $branch, 'path' => $path,
            'pushed' => (bool) ($result['pushed'] ?? false), 'commit' => $result['commit'] ?? null, 'labman' => (int) ($result['labman'] ?? 0),
            'message' => null,
        ]);
    }

    public static function failed(string $message, string $branch, string $path): void
    {
        self::store(['ok' => false, 'at' => now()->toIso8601String(), 'branch' => $branch, 'path' => $path, 'pushed' => false, 'commit' => null, 'labman' => 0, 'message' => mb_substr($message, 0, 500)]);
    }

    /** @return array{ok: bool, at: string, branch: string, path: string, pushed: bool, commit: ?string, labman: int, message: ?string}|null */
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

    /** @param array<string, mixed> $data */
    private static function store(array $data): void
    {
        Secrets::putPlain(self::KEY, json_encode($data));
    }
}
