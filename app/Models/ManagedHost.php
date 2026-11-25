<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A proxy host LabMan created in a zone's NPM (not mirrors: those are tracked by Mirror). Only these can be offered for deletion. */
class ManagedHost extends Model
{
    protected $table = 'npm_managed_hosts';

    protected $fillable = ['zone_id', 'host_id', 'domain'];

    protected function casts(): array
    {
        return ['host_id' => 'integer', 'zone_id' => 'integer'];
    }
}
