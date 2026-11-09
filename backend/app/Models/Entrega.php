<?php

namespace App\Models;

use App\Movements\Voidable;
use App\Tenancy\BelongsToCommunity;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Dinero que un cobrador entrega al encargado (M10): los recibos que incluye, lo que se
 * esperaba y lo que se contó. Movimiento: solo se anula con motivo.
 */
class Entrega extends Model
{
    use BelongsToCommunity, HasUuids, Voidable;

    public const ENTITY = 'entregas';

    protected function casts(): array
    {
        return [
            'expected_cents' => 'integer',
            'received_cents' => 'integer',
            'received_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function movementEntity(): string
    {
        return self::ENTITY;
    }

    public function differenceCents(): int
    {
        return $this->received_cents - $this->expected_cents;
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(EntregaPago::class);
    }

    public function cobrador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cobrador_id');
    }

    public function receptor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
