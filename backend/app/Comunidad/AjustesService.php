<?php

namespace App\Comunidad;

use App\Cuotas\CuotasPublisher;
use App\Cuotas\CuotasService;
use App\Exceptions\ApiException;
use App\Models\AuditLog;
use App\Models\Community;
use App\Models\Cuota;
use App\Models\Periodo;
use App\Models\Roles;
use App\Models\SyncChange;
use App\Models\User;
use App\Models\Vivienda;
use App\Padron\PadronService;
use App\Tenancy\CurrentCommunity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Ajustes de cobro de la comunidad: multa fija por mes no pagado, día límite y meses que se
 * pueden pagar por adelantado. Todos los usuarios de la comunidad los descargan (entidad
 * "ajustes") junto con los nombres de los cobradores, que se imprimen en los recibos.
 */
class AjustesService
{
    public const ENTITY = 'ajustes';

    public const FIELDS = ['cuota_cents', 'periodicidad_meses', 'dias_pago', 'cobro_fuera_de_dias', 'multa_cents', 'meses_adelanto', 'paquetes', 'tope_egreso_cents'];

    /** Equipo cuya mutación provocó el cambio (lo fija la sincronización). */
    public ?string $originDeviceId = null;

    public function __construct(private readonly CurrentCommunity $context) {}

    public function current(): Community
    {
        $id = $this->context->id() ?? throw new ApiException('community_required', 'Esta acción es solo para usuarios de una comunidad.', 403);

        return $this->context->runAsSystem(fn () => Community::findOrFail($id));
    }

    /**
     * "Cómo se cobra": cuánto paga cada titular, cada cuántos meses, qué días se recibe el pago,
     * si se puede cobrar fuera de esos días, la multa y cuántos periodos se pueden adelantar.
     */
    public function update(User $actor, array $data): Community
    {
        $community = $this->current();
        $data = Validator::make(array_intersect_key($data, array_flip(self::FIELDS)), [
            'cuota_cents' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10000000'],
            'periodicidad_meses' => ['sometimes', 'integer', 'in:'.implode(',', Calendario::PERIODICIDADES)],
            'dias_pago' => ['sometimes', 'array', 'min:1', 'max:31'],
            'dias_pago.*' => ['integer', 'min:1', 'max:31'],
            'cobro_fuera_de_dias' => ['sometimes', 'boolean'],
            'multa_cents' => ['sometimes', 'integer', 'min:0', 'max:10000000'],
            'meses_adelanto' => ['sometimes', 'integer', 'min:0', 'max:12'],
            'paquetes' => ['sometimes', 'array', 'min:1', 'max:20'],
            // Hasta cuánto gasta un cobrador sin autorización (null = sin tope).
            'tope_egreso_cents' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000000'],
        ], [
            'cuota_cents.min' => 'La cuota debe ser mayor a cero.',
            'periodicidad_meses.in' => 'Elija cada cuántos meses se cobra: 1, 2, 3, 4, 6 o 12.',
            'dias_pago.min' => 'Marque al menos un día de pago.',
            'meses_adelanto.max' => 'Se pueden adelantar como máximo 12 periodos.',
        ])->validate();

        $changes = [];
        if (isset($data['dias_pago'])) {
            $days = array_values(array_unique(array_map('intval', $data['dias_pago'])));
            sort($days);
            $data['dias_pago'] = implode(',', $days);
        }
        if (isset($data['paquetes'])) {
            // Si no cambiaron (el escritorio los manda siempre), no se vuelven a exigir los precios.
            if (Paquetes::normalize($data['paquetes'], false) === Paquetes::normalize(Paquetes::of($community), false)) {
                unset($data['paquetes']);
            }
        }
        if (isset($data['paquetes'])) {
            $normalized = Paquetes::normalize($data['paquetes']);
            // Un paquete que ya tiene casas no se quita: se desactiva.
            $kept = array_column($normalized, 'id');
            $inUse = Vivienda::where('is_active', true)->whereNotNull('paquete_id')->pluck('paquete_id')->unique()->all();
            $lost = array_diff($inUse, $kept);
            if ($lost !== []) {
                throw ValidationException::withMessages(['paquetes' => ['Hay casas con un paquete que no puede quitarse; desactívelo en lugar de borrarlo.']]);
            }
            $data['paquetes'] = json_encode($normalized, JSON_UNESCAPED_UNICODE);

            // La cuota base del mes sale del paquete de menos familias.
            $active = array_values(array_filter($normalized, fn ($p) => $p['activo']));
            usort($active, fn ($a, $b) => $a['familias'] <=> $b['familias']);
            $data['cuota_cents'] = $active[0]['cuota_cents'];
        }
        $cuota = $data['cuota_cents'] ?? null;
        unset($data['cuota_cents']);

        foreach ($data as $field => $value) {
            $before = $community->{$field};
            $same = is_bool($value) ? (bool) $before === $value : ($before === null) === ($value === null) && (string) $before === (string) $value;
            if (! $same) {
                $changes[$field] = ['from' => $before, 'to' => $value];
                $community->{$field} = $value;
            }
        }

        if ($changes !== []) {
            $this->context->runAsSystem(fn () => $community->save());
        }

        // La cuota: si cambia, aplica desde el periodo en curso; si ese periodo ya se cobró,
        // desde el siguiente (lo ya cargado no cambia).
        if ($cuota !== null && self::cuotaActual($community) !== (int) $cuota) {
            $meses = max(1, (int) $community->periodicidad_meses);
            $target = CarbonImmutable::create((int) now()->format('Y'), Calendario::inicio((int) now()->format('n'), $meses), 1);
            if (Periodo::where('year', $target->year)->where('month', $target->month)->exists()) {
                $target = $target->addMonths($meses);
            }

            $cuotas = app(CuotasService::class);
            $existing = Cuota::whereDate('valid_from', $target->format('Y-m-d'))->first();
            $model = $existing !== null
                ? $cuotas->updateCuota($actor, $existing, ['amount_cents' => (int) $cuota])
                : $cuotas->createCuota($actor, ['amount_cents' => (int) $cuota, 'valid_from' => $target->format('Y-m-d')]);
            app(CuotasPublisher::class)->publish($model, 'update');
            $changes['cuota_cents'] = ['from' => self::cuotaActual($community), 'to' => (int) $cuota, 'desde' => $target->format('Y-m')];
        }

        if ($changes !== []) {
            AuditLog::record('ajustes.updated', [
                'user_id' => $actor->id,
                'entity' => self::ENTITY,
                'entity_id' => $community->id,
                'data' => ['changes' => $changes],
            ]);
            $this->publish($community);
        }

        return $community;
    }

    public function publish(?Community $community = null): void
    {
        $community ??= $this->current();
        (new SyncChange)->forceFill([
            'community_id' => $community->id,
            'entity' => self::ENTITY,
            'entity_id' => $community->id,
            'op' => 'update',
            'payload' => self::present($community),
            'origin_device_id' => $this->originDeviceId,
            'created_at' => now(),
        ])->save();
    }

    public static function present(Community $community): array
    {
        return [
            'id' => $community->id,
            'name' => $community->name,
            // Ubicación y teléfono de la comunidad (para recibos y reportes del equipo).
            'state' => $community->state,
            'municipality' => $community->municipality,
            'locality' => $community->locality,
            'zone' => $community->zone,
            'contact_phone' => $community->contact_phone,
            'cuota_cents' => self::cuotaActual($community),
            'periodicidad_meses' => (int) $community->periodicidad_meses,
            'dias_pago' => Calendario::dias($community),
            'cobro_fuera_de_dias' => (bool) $community->cobro_fuera_de_dias,
            'multa_cents' => (int) $community->multa_cents,
            'meses_adelanto' => (int) $community->meses_adelanto,
            'paquetes' => Paquetes::of($community),
            'cobradores' => self::cobradores($community->id),
            // Montos de la cuota con su fecha, para calcular en el equipo los meses por adelantado.
            'cuotas' => Cuota::withoutGlobalScopes()->where('community_id', $community->id)->orderBy('valid_from')->get()
                ->map(fn ($c) => ['valid_from' => $c->valid_from->format('Y-m-d'), 'amount_cents' => $c->amount_cents])->all(),
            'tope_egreso_cents' => $community->tope_egreso_cents === null ? null : (int) $community->tope_egreso_cents,
            'multa_label' => (int) $community->multa_cents > 0 ? PadronService::money((int) $community->multa_cents) : null,
            'updated_at' => $community->updated_at?->toIso8601String(),
        ];
    }

    /** Cuota del periodo en curso, o null si todavía no hay. */
    public static function cuotaActual(Community $community): ?int
    {
        $now = now();
        $start = Calendario::inicio((int) $now->format('n'), (int) $community->periodicidad_meses);

        return Cuota::withoutGlobalScopes()->where('community_id', $community->id)
            ->whereDate('valid_from', '<=', sprintf('%04d-%02d-01', (int) $now->format('Y'), $start))
            ->orderByDesc('valid_from')->value('amount_cents');
    }

    /** Nombres de los cobradores activos (para el recibo). */
    public static function cobradores(string $communityId): array
    {
        return User::role(Roles::COBRADOR)->where('community_id', $communityId)->where('is_active', true)
            ->orderBy('name')->pluck('name')->all();
    }
}
