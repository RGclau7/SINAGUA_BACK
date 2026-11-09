<?php

namespace App\Http\Controllers\Api\V1;

use App\Gastos\Fondos;
use App\Gastos\GastosService;
use App\Http\Controllers\Controller;
use App\Models\Gasto;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Gastos y fondos (M9). El escritorio registra los gastos por /sync y sube aquí el comprobante.
 * Un gasto mal registrado se anula con POST /movements/gastos/{id}/void.
 */
class GastosController extends Controller
{
    public function __construct(private readonly GastosService $service) {}

    /** Fondos con su saldo: cuotas del agua y cada categoría de ingresos extras. */
    public function fondos(): JsonResponse
    {
        return ApiResponse::ok(Fondos::all());
    }

    /** Gastos, el más reciente primero; filtros: fondo (cuotas o id), desde, hasta (Y-m-d). */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fondo' => ['nullable', 'string'],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $rows = Gasto::query()
            ->with('fondo')
            ->when(($data['fondo'] ?? null) === 'cuotas', fn ($q) => $q->whereNull('fondo_id'))
            ->when(($data['fondo'] ?? 'cuotas') !== 'cuotas', fn ($q) => $q->where('fondo_id', $data['fondo']))
            ->when($data['desde'] ?? null, fn ($q, $d) => $q->whereDate('spent_on', '>=', $d))
            ->when($data['hasta'] ?? null, fn ($q, $d) => $q->whereDate('spent_on', '<=', $d))
            ->orderByDesc('spent_on')->orderByDesc('created_at')
            ->limit(500)
            ->get();

        return ApiResponse::ok($rows->map(fn (Gasto $g) => [
            ...GastosService::present($g),
            'fondo' => $g->fondo?->name ?? Fondos::CUOTAS,
        ])->all());
    }

    public function store(Request $request): JsonResponse
    {
        $result = $this->service->create($request->user(), $request->only(['fondo_id', 'concepto', 'amount_cents', 'spent_on', 'note']));
        $this->service->publish($result['gasto'], 'create');

        return response()->json(['data' => GastosService::present($result['gasto']->fresh()), 'notices' => $result['notices']], 201);
    }

    /** Sube la foto o el PDF del comprobante (campo "archivo"); X-Device-Id indica el equipo. */
    public function uploadComprobante(Request $request, string $id): JsonResponse
    {
        $request->validate(['archivo' => ['required', 'file']], ['archivo.required' => 'Elija la foto o el PDF del comprobante.']);
        $gasto = $this->service->find($id);
        $this->service->originDeviceId = $request->header('X-Device-Id');
        $gasto = $this->service->attachComprobante($request->user(), $gasto, $request->file('archivo'));
        $this->service->publish($gasto, 'update');

        return ApiResponse::ok(GastosService::present($gasto->fresh()));
    }

    public function downloadComprobante(string $id): StreamedResponse
    {
        $gasto = $this->service->find($id);
        abort_if($gasto->comprobante_path === null, 404);

        return Storage::disk(GastosService::disk())->download($gasto->comprobante_path,
            'comprobante-'.$gasto->id.'.'.pathinfo($gasto->comprobante_path, PATHINFO_EXTENSION),
            ['Content-Type' => $gasto->comprobante_mime]);
    }
}
