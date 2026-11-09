<?php

namespace App\Sync\Handlers;

use App\Entregas\EntregasService;
use App\Exceptions\ApiException;
use App\Models\Entrega;
use App\Models\Permissions;
use App\Models\User;
use App\Movements\VoidsMovements;
use App\Sync\Mutation;
use App\Sync\RestrictsRecords;
use App\Sync\RestrictsVisibility;
use App\Sync\SyncApplied;
use App\Sync\SyncHandler;
use App\Sync\SyncPolicy;
use App\Sync\SyncRejected;

/**
 * Entregas registradas en el escritorio, quizá sin conexión (M10). Solo se crean o se anulan:
 * create {cobrador_id, pago_ids, received_cents, note, received_at}; void {void_reason}.
 * Quien confirma dinero las descarga todas; el cobrador, solo las suyas (para ver su corte).
 */
class EntregasSyncHandler implements RestrictsRecords, RestrictsVisibility, SyncHandler
{
    use VoidsMovements;

    public function __construct(private readonly EntregasService $service) {}

    public function policy(): SyncPolicy
    {
        return SyncPolicy::AppendOnly;
    }

    public function visibleTo(User $user): bool
    {
        return $user->can(Permissions::DINERO_CONFIRMAR) || $user->can(Permissions::CORTES_GENERAR_PROPIO);
    }

    public function visibleIdsFor(User $user): ?array
    {
        return $user->can(Permissions::DINERO_CONFIRMAR)
            ? null
            : Entrega::where('cobrador_id', $user->id)->pluck('id')->all();
    }

    public function apply(Mutation $mutation): SyncApplied
    {
        if ($mutation->op === Mutation::OP_VOID) {
            return new SyncApplied(EntregasService::present(Entrega::findOrFail($this->voidFromSync($mutation, Entrega::class)['id'])));
        }

        $actor = User::find($mutation->userId);
        if ($actor === null || ! $actor->can(Permissions::DINERO_CONFIRMAR)) {
            throw new SyncRejected('forbidden', 'No tiene permiso para recibir entregas de dinero.');
        }

        try {
            $result = $this->service->create($actor,
                array_intersect_key($mutation->payload, array_flip(['cobrador_id', 'pago_ids', 'received_cents', 'note', 'received_at'])),
                $mutation->entityId, $mutation->deviceId);
        } catch (ApiException $e) {
            throw new SyncRejected($e->errorCode, $e->getMessage());
        }

        return new SyncApplied(EntregasService::present($result['entrega']->fresh()), $result['notices']);
    }
}
