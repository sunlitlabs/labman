<?php

namespace App\Node;

use App\Models\Link;
use App\Models\Server;
use App\Models\Service;
use App\Models\Zone;
use App\Support\Settings;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** Node side: replace what this node shows with a snapshot from the main LabMan. */
final class NodeReceiver
{
    /** @return array{servers: int, services: int, links: int} @throws \InvalidArgumentException */
    public static function apply(array $payload): array
    {
        if (($payload['version'] ?? null) !== 1) {
            throw new \InvalidArgumentException('Unsupported snapshot version.');
        }
        foreach (['zones', 'servers', 'services', 'links'] as $key) {
            if (! is_array($payload[$key] ?? null)) {
                throw new \InvalidArgumentException("The snapshot has no '{$key}' list.");
            }
        }

        DB::transaction(function () use ($payload) {
            // Children first, so nothing points at a row that is already gone.
            Service::query()->delete();
            Server::query()->delete();
            Link::query()->delete();
            Zone::query()->delete();

            $insert = function (string $model, array $columns, array $rows) {
                $model::unguarded(function () use ($model, $columns, $rows) {
                    foreach ($rows as $row) {
                        if (is_array($row) && isset($row['id'], $row['name'])) {
                            $model::query()->create(Arr::only($row, $columns));
                        }
                    }
                });
            };

            $insert(Zone::class, NodeSnapshot::ZONE, $payload['zones']);
            $insert(Server::class, NodeSnapshot::SERVER, $payload['servers']);
            $insert(Service::class, NodeSnapshot::SERVICE, $payload['services']);
            $insert(Link::class, NodeSnapshot::LINK, $payload['links']);

            $settings = Arr::only((array) ($payload['settings'] ?? []), NodeSnapshot::SETTINGS);
            if ($settings !== []) {
                Settings::set(array_map(fn ($v) => (string) $v, $settings));
            }
        });

        return ['servers' => Server::query()->count(), 'services' => Service::query()->count(), 'links' => Link::query()->count()];
    }
}
