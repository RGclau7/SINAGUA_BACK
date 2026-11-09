<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Equipo donde un administrador ya confirmó su entrada con el PIN del correo. */
class TrustedDevice extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['trusted_at' => 'datetime', 'last_used_at' => 'datetime'];
    }
}
