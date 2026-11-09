<?php

namespace App\Jobs;

use App\Audit\AuditChain;
use App\Tenancy\CurrentCommunity;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Verificación de la bitácora en segundo plano (cola "database", worker `queue:work`).
 *
 * Recorrer toda la cadena de huellas puede tardar con miles de registros; hacerlo dentro
 * de la petición HTTP ocuparía un worker de PHP-FPM. La API responde 202 al instante y un
 * worker de la cola hace el trabajo.
 *
 * Concurrencia:
 * - ShouldBeUnique: si el administrador pulsa "verificar" varias veces, solo queda un
 *   trabajo en cola por comunidad (candado atómico en caché hasta que termina).
 * - WithoutOverlapping: aunque haya varios workers, nunca corren dos verificaciones de la
 *   misma comunidad a la vez; el candado expira solo si un worker muere (sin bloqueos eternos).
 * Memoria: AuditChain::verify() recorre por bloques (lazyById) y el worker se reinicia con
 * --max-jobs/--max-time/--memory (ver docker-compose.yml), así una fuga no se acumula.
 */
class VerificarBitacora implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    /** Segundos que dura el candado de unicidad si el trabajo nunca termina. */
    public int $uniqueFor = 600;

    public function __construct(public readonly string $communityId) {}

    public function uniqueId(): string
    {
        return $this->communityId;
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('bitacora:'.$this->communityId))->releaseAfter(30)->expireAfter($this->timeout)];
    }

    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(CurrentCommunity $context): void
    {
        $previous = $context->id();
        $context->set($this->communityId);

        try {
            Cache::put(self::cacheKey($this->communityId), [
                'status' => 'done',
                ...AuditChain::verify($this->communityId),
                'finished_at' => now()->toIso8601String(),
            ], now()->addDay());
        } finally {
            // Un worker atiende muchos trabajos: no debe quedarse con la comunidad de este.
            $context->set($previous);
        }
    }

    public static function cacheKey(string $communityId): string
    {
        return "bitacora:verificacion:{$communityId}";
    }
}
