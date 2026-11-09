<?php

namespace App\Entregas;

use App\Exceptions\ApiException;
use App\Models\AuditLog;
use App\Models\Entrega;
use App\Models\EntregaPago;
use App\Models\Pago;
use App\Models\SyncChange;
use App\Models\User;
use App\Tenancy\CurrentCommunity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Entregas y cortes (M10). El corte de un cobrador son sus recibos vigentes que todavía no ha
 * entregado; la entrega la registra quien recibe el dinero (dinero.confirmar_recepcion).
 */
class EntregasService
{
    public function __construct(private readonly CurrentCommunity $context) {}

    /**
     * Recibos vigentes de un cobrador que aún no están en una entrega vigente.
     *
     * @return Collection<int, Pago>
     */
    public function porEntregar(?string $cobradorId = null): Collection
    {
        $this->communityId();

        return Pago::query()
            ->whereNull('voided_at')
            ->when($cobradorId, fn ($q, $id) => $q->where('cobrador_id', $id))
            ->whereNotIn('id', self::entregados())
            ->with(['cobrador', 'familia.head'])
            ->orderBy('paid_at')
            ->get();
    }

    /**
     * Cortes: por cada persona que cobró, lo que tiene por entregar.
     *
     * @return list<array{cobrador_id: string, cobrador: ?string, recibos: int, total_cents: int, desde: ?string}>
     */
    public function cortes(?string $cobradorId = null): array
    {
        return $this->porEntregar($cobradorId)
            ->groupBy('cobrador_id')
            ->map(fn (Collection $pagos, string $id) => [
                'cobrador_id' => $id,
                'cobrador' => $pagos->first()->cobrador?->name,
                'recibos' => $pagos->count(),
                'total_cents' => (int) $pagos->sum('total_cents'),
                'desde' => $pagos->first()->paid_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * Registra que se recibió el dinero de unos recibos de un cobrador.
     *
     * @param  array{cobrador_id?: mixed, pago_ids?: mixed, received_cents?: mixed, note?: mixed, received_at?: mixed}  $data
     * @return array{entrega: Entrega, notices: list<array{code: string, message: string}>}
     */
    public function create(User $actor, array $data, ?string $id = null, ?string $deviceId = null): array
    {
        $this->communityId();
        $cobrador = User::where('community_id', $this->communityId())->find((string) ($data['cobrador_id'] ?? ''))
            ?? throw new ApiException('validation_error', 'Elija quién entrega el dinero.', 422);
        $pagoIds = collect((array) ($data['pago_ids'] ?? []))->map(fn ($v) => (string) $v)->unique()->values();
        if ($pagoIds->isEmpty()) {
            throw new ApiException('validation_error', 'La entrega debe incluir al menos un recibo.', 422);
        }
        $received = filter_var($data['received_cents'] ?? null, FILTER_VALIDATE_INT);
        if ($received === false || $received < 0 || $received > 100_000_000) {
            throw new ApiException('validation_error', 'Escriba cuánto dinero se recibió.', 422);
        }
        $note = trim(preg_replace('/\s+/', ' ', (string) ($data['note'] ?? '')));
        $receivedAt = isset($data['received_at']) ? CarbonImmutable::parse((string) $data['received_at']) : CarbonImmutable::now();
        if ($id !== null && $this->context->runAsSystem(fn () => Entrega::whereKey($id)->exists())) {
            throw new ApiException('id_taken', 'El identificador del registro ya existe.', 409);
        }

        return DB::transaction(function () use ($actor, $cobrador, $pagoIds, $received, $note, $receivedAt, $id, $deviceId) {
            $pagos = Pago::whereIn('id', $pagoIds)->lockForUpdate()->get();
            if ($pagos->count() !== $pagoIds->count()) {
                throw new ApiException('not_found', 'Uno de los recibos no existe en esta comunidad.', 404);
            }
            if ($pagos->contains(fn (Pago $p) => $p->cobrador_id !== $cobrador->id)) {
                throw new ApiException('validation_error', "Todos los recibos deben ser cobros de {$cobrador->name}.", 422);
            }
            if ($voided = $pagos->first(fn (Pago $p) => $p->isVoided())) {
                throw new ApiException('pago_voided', "El recibo {$voided->folio} está anulado; no se entrega.", 409);
            }
            $taken = self::entregados()->intersect($pagoIds);
            if ($taken->isNotEmpty()) {
                $folio = $pagos->firstWhere('id', $taken->first())?->folio;
                throw new ApiException('already_delivered', "El recibo {$folio} ya se entregó en otra entrega.", 409);
            }

            $expected = (int) $pagos->sum('total_cents');
            if ($received !== $expected && $note === '') {
                throw new ApiException('note_required', 'Lo recibido no coincide con el corte; explique la diferencia en la nota.', 422);
            }

            $entrega = new Entrega;
            if ($id !== null) {
                $entrega->id = $id;
            }
            $entrega->forceFill([
                'cobrador_id' => $cobrador->id,
                'received_by' => $actor->id,
                'expected_cents' => $expected,
                'received_cents' => $received,
                'note' => $note === '' ? null : $note,
                'received_at' => $receivedAt,
                'device_id' => $deviceId,
            ])->save();
            foreach ($pagos as $pago) {
                (new EntregaPago)->forceFill(['entrega_id' => $entrega->id, 'pago_id' => $pago->id])->save();
            }

            AuditLog::record('entrega.created', [
                'user_id' => $actor->id,
                'device_id' => $deviceId,
                'entity' => Entrega::ENTITY,
                'entity_id' => $entrega->id,
                'data' => [
                    'cobrador' => $cobrador->name,
                    'recibos' => $pagos->count(),
                    'expected_cents' => $expected,
                    'received_cents' => $received,
                    'note' => $entrega->note,
                ],
            ]);

            $difference = $received - $expected;
            $notices = $difference === 0 ? [] : [[
                'code' => 'diferencia',
                'message' => ($difference < 0 ? 'Faltaron ' : 'Sobraron ').self::money(abs($difference))." en la entrega de {$cobrador->name}.",
            ]];

            return ['entrega' => $entrega, 'notices' => $notices];
        });
    }

    public function find(string $id): Entrega
    {
        $this->communityId();

        return Entrega::find($id) ?? throw new ApiException('not_found', 'Entrega no encontrada.', 404);
    }

    /** Publica en el feed un cambio hecho fuera de /sync/push (REST o anulación). */
    public function publish(Entrega $entrega, string $op): void
    {
        (new SyncChange)->forceFill([
            'community_id' => $entrega->community_id,
            'entity' => Entrega::ENTITY,
            'entity_id' => $entrega->id,
            'op' => $op,
            'payload' => self::present($entrega->fresh()),
            'created_at' => now(),
        ])->save();
    }

    public static function present(Entrega $e): array
    {
        return [
            'id' => $e->id,
            'cobrador_id' => $e->cobrador_id,
            'received_by' => $e->received_by,
            'pago_ids' => $e->detalles()->pluck('pago_id')->all(),
            'expected_cents' => $e->expected_cents,
            'received_cents' => $e->received_cents,
            'note' => $e->note,
            'received_at' => $e->received_at?->toIso8601String(),
            'voided_at' => $e->voided_at?->toIso8601String(),
            'voided_by' => $e->voided_by,
            'void_reason' => $e->void_reason,
            'created_at' => $e->created_at?->toIso8601String(),
        ];
    }

    /** Ids de los recibos que ya están en una entrega vigente. */
    private static function entregados(): Collection
    {
        return EntregaPago::query()
            ->whereIn('entrega_id', Entrega::whereNull('voided_at')->select('id'))
            ->pluck('pago_id');
    }

    private static function money(int $cents): string
    {
        return '$'.number_format($cents / 100, 2);
    }

    private function communityId(): string
    {
        return $this->context->id() ?? throw new ApiException('community_required', 'Esta acción es solo para usuarios de una comunidad.', 403);
    }
}
