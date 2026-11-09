<?php

namespace App\Sync\Handlers;

use App\Exceptions\ApiException;
use App\Gastos\GastosService;
use App\Models\Gasto;
use App\Models\Permissions;
use App\Models\User;
use App\Movements\VoidsMovements;
use App\Sync\Mutation;
use App\Sync\RestrictsVisibility;
use App\Sync\SyncApplied;
use App\Sync\SyncHandler;
use App\Sync\SyncPolicy;
use App\Sync\SyncRejected;
use Illuminate\Validation\ValidationException;

/**
 * Gastos hechos en el escritorio, quizá sin conexión (M9). Solo se crean o se anulan:
 * create {fondo_id (null = cuotas del agua), concepto, amount_cents, spent_on, note}; void {void_reason}.
 * El comprobante se sube aparte con POST /gastos/{id}/comprobante cuando hay internet.
 * Solo los descarga quien registra ingresos y gastos (administrador).
 */
class GastosSyncHandler implements RestrictsVisibility, SyncHandler
{
    use VoidsMovements;

    public function __construct(private readonly GastosService $service) {}

    public function policy(): SyncPolicy
    {
        return SyncPolicy::AppendOnly;
    }

    public function visibleTo(User $user): bool
    {
        return $user->can(Permissions::INGRESOS_GASTOS_REGISTRAR);
    }

    public function apply(Mutation $mutation): SyncApplied
    {
        if ($mutation->op === Mutation::OP_VOID) {
            return new SyncApplied(GastosService::present(Gasto::findOrFail($this->voidFromSync($mutation, Gasto::class)['id'])));
        }

        $actor = User::find($mutation->userId);
        if ($actor === null || ! $this->visibleTo($actor)) {
            throw new SyncRejected('forbidden', 'No tiene permiso para registrar gastos.');
        }

        try {
            $result = $this->service->create($actor,
                array_intersect_key($mutation->payload, array_flip(['fondo_id', 'concepto', 'amount_cents', 'spent_on', 'note', 'autorizacion_id'])),
                $mutation->entityId, $mutation->deviceId);
        } catch (ValidationException $e) {
            throw new SyncRejected('validation_error', collect($e->errors())->flatten()->implode(' '));
        } catch (ApiException $e) {
            throw new SyncRejected($e->errorCode, $e->getMessage());
        }

        return new SyncApplied(GastosService::present($result['gasto']->fresh()), $result['notices']);
    }
}
