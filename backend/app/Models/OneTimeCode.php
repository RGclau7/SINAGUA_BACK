<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** PIN de un solo uso (solo se guarda su hash). */
class OneTimeCode extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    public const ACTIVATION = 'activation';

    public const LOGIN = 'login';

    protected $guarded = [];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime', 'attempts' => 'integer'];
    }
}
