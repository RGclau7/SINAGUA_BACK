<?php

namespace App\Http\Controllers\Api\V1;

use App\Entregas\EntregasService;
use App\Http\Controllers\Controller;
use App\Models\Entrega;
use App\Models\Pago;
use App\Models\Permissions;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Entregas y cortes (M10). El escritorio usa /sync; estas rutas sirven a clientes en línea.
 * Una entrega mal registrada se anula con POST /movements/entregas/{id}/void.
 */
class EntregasController extends Controller
{
    public function __construct(private readonly EntregasService $service) {}

    /** Cortes: lo que cada quien tiene por entregar (el cobrador solo ve el suyo). */
    public function cortes(Request $request): JsonResponse
    {
        return ApiResponse::ok($this->service->cortes($this->scope($request)));
    }

    /** Recibos por entregar de un cobrador (el detalle de su corte). */
    public function corte(Request $request, string $cobradorId): JsonResponse
    {
        abort_if($this->scope($request) !== null && $this->scope($request) !== $cobradorId, 403);

        return ApiResponse::ok($this->service->porEntregar($cobradorId)->map(fn (Pago $p) => [
            'id' => $p->id,
            'folio' => $p->folio,
            'familia' => $p->familia?->head?->name ?? $p->familia?->name,
            'total_cents' => $p->total_cents,
            'paid_at' => $p->paid_at?->toIso8601String(),
        ])->all());
    }

    public function index(Request $request): JsonResponse
    {
        $rows = Entrega::query()
            ->with(['cobrador', 'receptor'])
            ->when($this->scope($request), fn ($q, $id) => $q->where('cobrador_id', $id))
            ->orderByDesc('received_at')
            ->limit(500)
            ->get();

        return ApiResponse::ok($rows->map(fn (Entrega $e) => [
            ...EntregasService::present($e),
            'cobrador' => $e->cobrador?->name,
            'receptor' => $e->receptor?->name,
        ])->all());
    }

    public function store(Request $request): JsonResponse
    {
        $result = $this->service->create($request->user(), $request->only(['cobrador_id', 'pago_ids', 'received_cents', 'note', 'received_at']));
        $this->service->publish($result['entrega'], 'create');

        return response()->json(['data' => EntregasService::present($result['entrega']->fresh()), 'notices' => $result['notices']], 201);
    }

    /** null = ve a todos; si no confirma dinero, solo a sí mismo. */
    private function scope(Request $request): ?string
    {
        return $request->user()->can(Permissions::DINERO_CONFIRMAR) ? null : $request->user()->id;
    }
}
