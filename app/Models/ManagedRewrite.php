<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A DNS rewrite LabMan wrote to AdGuard Home (added, or changed by an approved update). Only these can be offered for deletion. */
class ManagedRewrite extends Model
{
    protected $table = 'adguard_managed';

    protected $fillable = ['domain', 'answer'];
}
