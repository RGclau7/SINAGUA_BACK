<?php

namespace App\Models;

use App\Movements\Voidable;
use App\Tenancy\BelongsToCommunity;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dinero que sale de un fondo (M9): de una categoría de ingresos extras o, si fondo_id es
 * null, de las cuotas del agua. Movimiento: solo se anula con motivo; el comprobante se
 * puede adjuntar después.
 */
class Gasto extends Model
{
    use BelongsToCommunity, HasUuids, Voidable;

    public const ENTITY = 'gastos';

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'spent_on' => 'date',
            'voided_at' => 'datetime',
        ];
    }

    public function movementEntity(): string
    {
        return self::ENTITY;
    }

    /** Lo único que cambia además de la anulación: el comprobante que se adjunta después. */
    public function mutableFields(): array
    {
        return ['comprobante_path', 'comprobante_mime'];
    }

    public function fondo(): BelongsTo
    {
        return $this->belongsTo(CategoriaIngreso::class, 'fondo_id');
    }
}
