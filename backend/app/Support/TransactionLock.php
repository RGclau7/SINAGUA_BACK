<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Candado consultivo de PostgreSQL ligado a la transacción actual (pg_advisory_xact_lock).
 *
 * Sirve cuando hay que serializar algo que no es una fila existente, por ejemplo "el
 * siguiente folio": SELECT ... FOR UPDATE no bloquea filas que todavía no existen, así que
 * dos transacciones podían leer el mismo último folio y calcular el mismo siguiente.
 *
 * - Se libera solo al hacer COMMIT o ROLLBACK: no hay forma de olvidarlo (sin fugas de candados).
 * - Bloquea solo a quien pide la misma llave; lo demás sigue en paralelo.
 * - En SQLite (pruebas) no hace falta: SQLite ya serializa todas las escrituras.
 */
final class TransactionLock
{
    public static function acquire(string $key): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('TransactionLock::acquire() debe llamarse dentro de DB::transaction().');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', [$key]);
        }
    }
}
