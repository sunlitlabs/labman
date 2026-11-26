<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A LabMan node (another install in node mode) that this one pushes a dashboard to. */
class Node extends Model
{
    protected $fillable = ['name', 'url', 'token', 'zone_id', 'enabled', 'pushed_at', 'push_ok', 'push_message'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['token' => 'encrypted', 'enabled' => 'boolean', 'push_ok' => 'boolean', 'pushed_at' => 'datetime'];
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    /** Where the node listens for pushes. */
    public function pushUrl(): string
    {
        return rtrim($this->url, '/').'/node/push';
    }
}
