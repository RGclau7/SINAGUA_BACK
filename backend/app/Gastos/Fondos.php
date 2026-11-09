<?php

namespace App\Gastos;

use App\Models\CategoriaIngreso;
use App\Models\Gasto;
use App\Models\Pago;

/**
 * Fondos de la comunidad y su saldo: el de las cuotas del agua (lo cobrado menos sus gastos) y
 * uno por cada categoría de ingresos extras (lo que entró menos sus gastos). Nada anulado cuenta.
 */
class Fondos
{
    public const CUOTAS = 'Cuotas del agua';

    public static function cuotasCents(): int
    {
        return (int) Pago::whereNull('voided_at')->sum('total_cents')
            - (int) Gasto::whereNull('fondo_id')->whereNull('voided_at')->sum('amount_cents');
    }

    public static function saldoCents(?string $fondoId): int
    {
        return $fondoId === null ? self::cuotasCents() : (CategoriaIngreso::find($fondoId)?->saldoCents() ?? 0);
    }

    /** @return list<array{id: ?string, name: string, is_active: bool, saldo_cents: int}> */
    public static function all(): array
    {
        return [
            ['id' => null, 'name' => self::CUOTAS, 'is_active' => true, 'saldo_cents' => self::cuotasCents()],
            ...CategoriaIngreso::orderBy('name')->get()->map(fn (CategoriaIngreso $c) => [
                'id' => $c->id, 'name' => $c->name, 'is_active' => (bool) $c->is_active, 'saldo_cents' => $c->saldoCents(),
            ])->all(),
        ];
    }
}
