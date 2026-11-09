<?php

namespace App\Models;

use App\Support\TransactionLock;
use App\Tenancy\BelongsToCommunity;
use App\Tenancy\CurrentCommunity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['id', 'name', 'receipt_prefix', 'registered_by', 'is_active', 'last_seen_at'])]
class Device extends Model
{
    use BelongsToCommunity, HasUuids;

    /** Igual que el default de la columna, para que la respuesta al crear no traiga null. */
    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * Siguiente prefijo libre dentro de la comunidad actual: E01, E02, ... E99, E100.
     * Se llama dentro de la transacción del alta; el candado evita que dos equipos que se
     * registran a la vez reciban el mismo prefijo.
     */
    public static function nextReceiptPrefix(): string
    {
        TransactionLock::acquire('receipt-prefix:'.app(CurrentCommunity::class)->id());

        $max = static::query()
            ->pluck('receipt_prefix')
            ->map(fn (string $p) => (int) ltrim($p, 'E'))
            ->max() ?? 0;

        return 'E'.str_pad((string) ($max + 1), 2, '0', STR_PAD_LEFT);
    }
}
