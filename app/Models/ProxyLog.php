<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One applied change set on a zone's proxy (a preview apply, an import, the guided sync or an automatic update). */
class ProxyLog extends Model
{
    protected $table = 'proxy_log';

    public const UPDATED_AT = null;

    protected $fillable = ['zone_id', 'source', 'who', 'applied', 'failed', 'results'];

    protected function casts(): array
    {
        return ['results' => 'array', 'created_at' => 'datetime'];
    }

    /**
     * Remember what an apply did for a zone. Nothing is written when nothing happened.
     *
     * @param  list<array<string, string>>  $results  as the apply returned them (title, outcome, message)
     */
    public static function record(Zone $zone, string $source, array $results): void
    {
        $applied = count(array_filter($results, fn ($r) => ($r['outcome'] ?? '') === 'applied'));
        $failed = count(array_filter($results, fn ($r) => ($r['outcome'] ?? '') === 'failed'));
        if ($applied + $failed === 0) {
            return;
        }

        self::create([
            'zone_id' => $zone->id, 'source' => $source, 'who' => $source === 'automatic' ? null : auth()->user()?->name,
            'applied' => $applied, 'failed' => $failed,
            'results' => array_map(fn ($r) => ['title' => mb_substr((string) ($r['title'] ?? ''), 0, 200), 'outcome' => $r['outcome'] ?? '', 'message' => mb_substr((string) ($r['message'] ?? ''), 0, 300)], array_slice($results, 0, 30)),
        ]);
    }
}
