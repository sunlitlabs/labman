<?php

namespace App\Support;

use App\Models\Zone;
use App\Npm\ProxyStatus;

/**
 * A zone's proxy status, remembered from the last check so the overview opens at once even when a proxy is
 * slow or down. "Refresh" asks every proxy again (a short timeout each) and keeps the answer. Holds no secrets.
 */
final class ZoneStatus
{
    private static function key(Zone $zone): string
    {
        return 'zone.status.'.$zone->id;
    }

    /** @return array<string, mixed>|null the last result with 'checked_at', or null when never checked */
    public static function cached(Zone $zone): ?array
    {
        $data = json_decode((string) Secrets::plain(self::key($zone)), true);

        return is_array($data) && isset($data['state'], $data['checked_at']) ? $data : null;
    }

    /** Ask the proxy now and remember the answer. @return array<string, mixed> */
    public static function refresh(Zone $zone): array
    {
        $status = ProxyStatus::of($zone);
        unset($status['zone']);
        $status['checked_at'] = now()->toIso8601String();
        Secrets::putPlain(self::key($zone), json_encode($status));

        return $status;
    }

    public static function forget(Zone $zone): void
    {
        Secrets::forget([self::key($zone)]);
    }
}
