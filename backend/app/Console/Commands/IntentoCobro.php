<?php

namespace App\Console\Commands;

use App\Cobranza\CobranzaService;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Tenancy\CurrentCommunity;
use Illuminate\Console\Command;
use Throwable;

/**
 * Proceso trabajador para la prueba de concurrencia (tests/Feature/ConcurrencyTest.php).
 *
 * Cada proceso es un PHP independiente con su propia conexión a PostgreSQL, igual que dos
 * peticiones atendidas por dos workers de PHP-FPM. Todos esperan a la misma marca de tiempo
 * (--start) para intentar cobrar al mismo tiempo y escriben su resultado en JSON.
 */
class IntentoCobro extends Command
{
    protected $signature = 'sinagua:intento-cobro {user} {familia} {cargos*} {--start= : microtime(true) en que todos arrancan}';

    protected $description = 'Intenta registrar un pago (uso interno de la prueba de concurrencia)';

    protected $hidden = true;

    public function handle(CurrentCommunity $context, CobranzaService $cobranza): int
    {
        $user = User::findOrFail($this->argument('user'));
        $context->set($user->community_id);

        // Barrera: todos los procesos salen juntos para provocar la carrera.
        $start = (float) $this->option('start');
        while ($start > 0 && microtime(true) < $start) {
            usleep(200);
        }

        try {
            $pago = $cobranza->pay($user, $this->argument('familia'), $this->argument('cargos'));
            $this->line(json_encode(['ok' => true, 'folio' => $pago->folio]));
        } catch (ApiException $e) {
            $this->line(json_encode(['ok' => false, 'code' => $e->errorCode]));
        } catch (Throwable $e) {
            $this->line(json_encode(['ok' => false, 'code' => 'exception', 'message' => $e->getMessage()]));
        }

        return self::SUCCESS;
    }
}
