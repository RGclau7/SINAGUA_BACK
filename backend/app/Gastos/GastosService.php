<?php

namespace App\Gastos;

use App\Autorizaciones\AutorizacionesService;
use App\Exceptions\ApiException;
use App\Models\AuditLog;
use App\Models\CategoriaIngreso;
use App\Models\Gasto;
use App\Models\SyncChange;
use App\Models\User;
use App\Tenancy\CurrentCommunity;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Gastos (M9): el administrador registra lo que sale y elige de qué fondo. La usan la API REST y
 * la sincronización; la sincronización publica sus propios cambios y la API llama a publish().
 */
class GastosService
{
    public const MAX_AMOUNT_CENTS = 100_000_000;

    public const COMPROBANTE_MIMES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];

    public const MAX_COMPROBANTE_KB = 10_240;

    /** Equipo cuya mutación provocó el cambio (lo fija quien sube el comprobante desde un equipo). */
    public ?string $originDeviceId = null;

    public function __construct(private readonly CurrentCommunity $context) {}

    public function find(string $id): Gasto
    {
        $this->communityId();

        return Gasto::find($id) ?? throw new ApiException('not_found', 'Gasto no encontrado.', 404);
    }

    /**
     * @param  array{fondo_id?: mixed, concepto?: mixed, amount_cents?: mixed, spent_on?: mixed, note?: mixed}  $data
     * @return array{gasto: Gasto, notices: list<array{code: string, message: string}>}
     */
    public function create(User $actor, array $data, ?string $id = null, ?string $deviceId = null): array
    {
        $this->communityId();
        $v = Validator::make($data, [
            'fondo_id' => ['nullable', 'uuid'],
            'concepto' => ['required', 'string', 'min:3', 'max:160'],
            'amount_cents' => ['required', 'integer', 'min:1', 'max:'.self::MAX_AMOUNT_CENTS],
            'spent_on' => ['required', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:255'],
            'autorizacion_id' => ['nullable', 'uuid'],
        ], [
            'concepto.required' => 'Escriba en qué se gastó.',
            'concepto.min' => 'Escriba en qué se gastó.',
            'amount_cents.required' => 'Escriba el monto.',
            'amount_cents.min' => 'El monto debe ser mayor a cero.',
            'amount_cents.max' => 'El monto es demasiado grande.',
            'spent_on.required' => 'Escriba la fecha.',
            'spent_on.date_format' => 'La fecha no es válida.',
        ])->validate();

        $fondo = null;
        if (! empty($v['fondo_id'])) {
            $fondo = CategoriaIngreso::find($v['fondo_id']) ?? throw new ApiException('not_found', 'Fondo no encontrado.', 404);
        }
        if (CarbonImmutable::parse($v['spent_on'])->gt(CarbonImmutable::today()->addDay())) {
            throw new ApiException('validation_error', 'La fecha del gasto no puede ser futura.', 422);
        }
        if ($id !== null && $this->context->runAsSystem(fn () => Gasto::whereKey($id)->exists())) {
            throw new ApiException('id_taken', 'El identificador del registro ya existe.', 409);
        }

        $gasto = new Gasto;
        $gasto->id = $id ?? (string) Str::uuid();

        // Fase 3: un gasto del cobrador que pasa el tope necesita una autorización ya canjeada.
        $autorizacion = app(AutorizacionesService::class)->paraGasto($actor, $gasto->id, (int) $v['amount_cents'], $v['autorizacion_id'] ?? null);

        $note = trim(preg_replace('/\s+/', ' ', (string) ($v['note'] ?? '')));
        $gasto->forceFill([
            'fondo_id' => $fondo?->id,
            'concepto' => trim(preg_replace('/\s+/', ' ', $v['concepto'])),
            'amount_cents' => (int) $v['amount_cents'],
            'spent_on' => $v['spent_on'],
            'note' => $note === '' ? null : $note,
            'recorded_by' => $actor->id,
            'device_id' => $deviceId,
            'autorizacion_id' => $autorizacion?->id,
        ])->save();

        $fondoName = $fondo?->name ?? Fondos::CUOTAS;
        AuditLog::record('gasto.created', [
            'user_id' => $actor->id,
            'device_id' => $deviceId,
            'entity' => Gasto::ENTITY,
            'entity_id' => $gasto->id,
            'data' => ['concepto' => $gasto->concepto, 'fondo' => $fondoName, 'amount_cents' => $gasto->amount_cents, 'spent_on' => $v['spent_on'],
                ...($autorizacion === null ? [] : ['autorizacion_id' => $autorizacion->id, 'autorizado_por' => $autorizacion->autor?->name])],
        ]);
        if ($autorizacion !== null) {
            AuditLog::record('autorizacion.used', [
                'user_id' => $actor->id,
                'device_id' => $deviceId,
                'entity' => 'autorizaciones_egreso',
                'entity_id' => $autorizacion->id,
                'data' => ['gasto_id' => $gasto->id, 'concepto' => $gasto->concepto, 'amount_cents' => $gasto->amount_cents,
                    'autorizado_por' => $autorizacion->autor?->name, 'cobrador' => $actor->name],
            ]);
        }

        // Sin conexión no se puede impedir: se registra y se avisa si el fondo quedó en negativo.
        $saldo = Fondos::saldoCents($fondo?->id);
        $notices = $saldo < 0
            ? [['code' => 'fondo_negativo', 'message' => "El fondo {$fondoName} quedó en negativo (".self::money($saldo).').']]
            : [];

        return ['gasto' => $gasto, 'notices' => $notices];
    }

    /** Adjunta (o cambia) la foto o PDF del comprobante. */
    public function attachComprobante(User $actor, Gasto $gasto, UploadedFile $file): Gasto
    {
        $mime = $file->getMimeType();
        $ext = self::COMPROBANTE_MIMES[$mime] ?? throw new ApiException('validation_error', 'El comprobante debe ser una foto (JPG o PNG) o un PDF.', 422);
        if ($file->getSize() > self::MAX_COMPROBANTE_KB * 1024) {
            throw new ApiException('validation_error', 'El comprobante pesa más de 10 MB.', 422);
        }
        if ($gasto->isVoided()) {
            throw new ApiException('already_voided', 'El gasto está anulado.', 409);
        }

        $path = "{$gasto->community_id}/gastos/{$gasto->id}.{$ext}";
        Storage::disk(self::disk())->putFileAs(dirname($path), $file, basename($path));
        if ($gasto->comprobante_path !== null && $gasto->comprobante_path !== $path) {
            Storage::disk(self::disk())->delete($gasto->comprobante_path);
        }
        $gasto->forceFill(['comprobante_path' => $path, 'comprobante_mime' => $mime])->save();

        AuditLog::record('gasto.comprobante', [
            'user_id' => $actor->id,
            'device_id' => $this->originDeviceId,
            'entity' => Gasto::ENTITY,
            'entity_id' => $gasto->id,
            'data' => ['concepto' => $gasto->concepto],
        ]);

        return $gasto;
    }

    public static function disk(): string
    {
        return config('sinagua.comprobantes_disk', 's3');
    }

    /** Publica en el feed un cambio hecho fuera de /sync/push (REST, comprobante o anulación). */
    public function publish(Gasto $gasto, string $op): void
    {
        (new SyncChange)->forceFill([
            'community_id' => $gasto->community_id,
            'entity' => Gasto::ENTITY,
            'entity_id' => $gasto->id,
            'op' => $op,
            'payload' => self::present($gasto->fresh()),
            'origin_device_id' => $this->originDeviceId,
            'created_at' => now(),
        ])->save();
    }

    public static function present(Gasto $g): array
    {
        return [
            'id' => $g->id,
            'fondo_id' => $g->fondo_id,
            'concepto' => $g->concepto,
            'amount_cents' => $g->amount_cents,
            'spent_on' => $g->spent_on?->format('Y-m-d'),
            'note' => $g->note,
            'has_comprobante' => $g->comprobante_path !== null,
            'comprobante_mime' => $g->comprobante_mime,
            'recorded_by' => $g->recorded_by,
            'autorizacion_id' => $g->autorizacion_id,
            'voided_at' => $g->voided_at?->toIso8601String(),
            'voided_by' => $g->voided_by,
            'void_reason' => $g->void_reason,
            'created_at' => $g->created_at?->toIso8601String(),
            'updated_at' => $g->updated_at?->toIso8601String(),
        ];
    }

    private static function money(int $cents): string
    {
        return ($cents < 0 ? '-$' : '$').number_format(abs($cents) / 100, 2);
    }

    private function communityId(): string
    {
        return $this->context->id() ?? throw new ApiException('community_required', 'Esta acción es solo para usuarios de una comunidad.', 403);
    }
}
