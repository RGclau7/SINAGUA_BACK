<?php

namespace App\Models;

use App\Tenancy\BelongsToCommunity;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Autorización del administrador para que un cobrador registre un gasto que pasa el tope:
 * código de 6 números (solo su hash), para un cobrador, hasta un monto, de corta vigencia y
 * de un solo uso. Al usarse queda ligada al gasto.
 */
class AutorizacionEgreso extends Model
{
    use BelongsToCommunity, HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'autorizaciones_egreso';

    protected $guarded = ['community_id'];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return [
            'max_amount_cents' => 'integer',
            'used_amount_cents' => 'integer',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function cobrador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cobrador_id');
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function estado(): string
    {
        return match (true) {
            $this->used_at !== null && $this->gasto_id !== null => 'usada',
            $this->used_at !== null => 'anulada',
            $this->expires_at->isPast() => 'vencida',
            default => 'vigente',
        };
    }
}
