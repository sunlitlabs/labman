<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** LabMan's record of a mirror it created in a zone's proxy (see App\Npm\Mirrors). */
class Mirror extends Model
{
    protected $fillable = ['service_id', 'service_name', 'zone_id', 'domain', 'npm_host_id', 'upstream'];

    protected function casts(): array
    {
        return ['npm_host_id' => 'integer'];
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }
}
