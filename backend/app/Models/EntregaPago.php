<?php

namespace App\Models;

use App\Tenancy\BelongsToCommunity;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Un recibo (pago) incluido en una entrega. No se modifica ni se borra: si la entrega está mal,
 * se anula la entrega.
 */
class EntregaPago extends Model
{
    use BelongsToCommunity, HasUuids;

    protected $table = 'entrega_pagos';

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('El detalle de una entrega no se modifica.'));
        static::deleting(fn () => throw new LogicException('El detalle de una entrega no se borra.'));
    }

    public function pago(): BelongsTo
    {
        return $this->belongsTo(Pago::class);
    }
}
