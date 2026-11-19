<?php

namespace App\Monitoring;

use App\Models\Server;
use App\Models\Service;
use App\Support\Settings;

/** The stored health of a row, in the shape both the Blade pages and the JSON endpoint use. */
final class StatusView
{
    /** @return array{id: int, state: string, stale: bool, checked_at: string|null, checks: list<array<string, mixed>>} */
    public static function of(Server|Service $model): array
    {
        $staleAfter = max(60, 3 * Settings::interval());
        $stale = $model->checked_at !== null && $model->checked_at->lt(now()->subSeconds($staleAfter));

        return [
            'id' => $model->id,
            'state' => ! $model->monitor ? Verdict::OFF : ($model->checked_at === null ? Verdict::UNKNOWN : $model->state),
            'stale' => $stale,
            'checked_at' => $model->checked_at?->toIso8601String(),
            'checks' => $model->checks ?? [],
        ];
    }

    /** What a state is called on screen. */
    public static function label(string $state): string
    {
        return $state === Verdict::OFF ? 'not monitored' : $state;
    }

    /** @param iterable<Server|Service> $models @return array{up: int, degraded: int, down: int, unknown: int} */
    public static function summary(iterable $models): array
    {
        $counts = ['up' => 0, 'degraded' => 0, 'down' => 0, 'unknown' => 0];

        foreach ($models as $model) {
            $state = self::of($model)['state'];
            if (isset($counts[$state])) { // unmonitored items are not counted
                $counts[$state]++;
            }
        }

        return $counts;
    }
}
