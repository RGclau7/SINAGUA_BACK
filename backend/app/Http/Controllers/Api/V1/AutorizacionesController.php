<?php

namespace App\Http\Controllers\Api\V1;

use App\Autorizaciones\AutorizacionesService;
use App\Http\Controllers\Controller;
use App\Models\AutorizacionEgreso;
use App\Models\Roles;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Autorizaciones de gastos del cobrador que pasan el tope (Fase 3).
 * El administrador las genera y ve; el cobrador canjea el código al registrar el gasto.
 */
class AutorizacionesController extends Controller
{
    public function __construct(private readonly AutorizacionesService $service) {}

    /** Las de los últimos 30 días, más recientes primero, y los cobradores para elegir. */
    public function index(): JsonResponse
    {
        $list = AutorizacionEgreso::with(['cobrador', 'autor'])->where('created_at', '>=', now()->subDays(30))
            ->orderByDesc('created_at')->limit(200)->get();

        return ApiResponse::ok([
            'tope_egreso_cents' => $this->service->tope(),
            'cobradores' => User::role(Roles::COBRADOR)->where('community_id', app(\App\Tenancy\CurrentCommunity::class)->id())
                ->where('is_active', true)->whereNotNull('activated_at')->orderBy('name')->get(['id', 'name'])
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->all(),
            'autorizaciones' => $list->map(fn ($a) => AutorizacionesService::present($a))->all(),
        ]);
    }

    /** Genera el código (se muestra una sola vez para dictarlo o mandarlo por WhatsApp). */
    public function store(Request $request): JsonResponse
    {
        ['autorizacion' => $a, 'codigo' => $codigo] = $this->service->generar($request->user(), $request->all());

        return ApiResponse::ok([...AutorizacionesService::present($a->load(['cobrador', 'autor'])), 'codigo' => $codigo], 201);
    }

    /** El cobrador canjea el código antes de guardar el gasto en su equipo. */
    public function redeem(Request $request): JsonResponse
    {
        $a = $this->service->canjear($request->user(), $request->all());

        return ApiResponse::ok(AutorizacionesService::present($a->load(['cobrador', 'autor'])));
    }
}
