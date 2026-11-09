<?php

namespace App\Autorizaciones;

use App\Exceptions\ApiException;
use App\Models\AuditLog;
use App\Models\AutorizacionEgreso;
use App\Models\Community;
use App\Models\Permissions;
use App\Models\Roles;
use App\Models\User;
use App\Tenancy\CurrentCommunity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;

/**
 * Tope de salidas del cobrador y sus autorizaciones.
 *
 * Entrega propuesta: el administrador genera el código en la pantalla Autorizaciones y se lo
 * dicta por teléfono o se lo manda por WhatsApp al cobrador. El código sirve una sola vez, solo
 * para ese cobrador, hasta el monto que se autorizó y durante unos minutos. El cobrador lo
 * escribe al registrar el gasto (con internet): el servidor lo canjea y lo liga al gasto.
 */
class AutorizacionesService
{
    public function __construct(private readonly CurrentCommunity $context) {}

    /** Tope vigente de la comunidad actual (null = sin tope). */
    public function tope(): ?int
    {
        $id = $this->context->id();
        $tope = $id === null ? null : $this->context->runAsSystem(fn () => Community::whereKey($id)->value('tope_egreso_cents'));

        return $tope === null ? null : (int) $tope;
    }

    /** ¿Este gasto necesita autorización? Solo para quien no autoriza (el cobrador) y sobre el tope. */
    public function requiere(User $actor, int $amountCents): bool
    {
        $tope = $this->tope();

        return $tope !== null && $amountCents > $tope && ! $actor->can(Permissions::EGRESOS_AUTORIZAR);
    }

    /** @return array{autorizacion: AutorizacionEgreso, codigo: string} */
    public function generar(User $admin, array $data): array
    {
        $v = Validator::make($data, [
            'cobrador_id' => ['required', 'uuid'],
            'max_amount_cents' => ['required', 'integer', 'min:1', 'max:100000000'],
            'motivo' => ['nullable', 'string', 'max:160'],
        ], [
            'cobrador_id.required' => 'Elija al cobrador.',
            'max_amount_cents.required' => 'Escriba hasta cuánto autoriza.',
            'max_amount_cents.min' => 'El monto debe ser mayor a cero.',
        ])->validate();

        $cobrador = User::where('community_id', $this->context->id())->where('is_active', true)->find($v['cobrador_id']);
        if ($cobrador === null || ! $cobrador->hasRole(Roles::COBRADOR)) {
            throw new ApiException('not_found', 'Ese cobrador no existe o está desactivado.', 404);
        }

        $minutes = (int) config('sinagua.autorizaciones.minutes');
        $codigo = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $motivo = trim(preg_replace('/\s+/', ' ', (string) ($v['motivo'] ?? '')));

        $autorizacion = DB::transaction(function () use ($admin, $cobrador, $v, $codigo, $minutes, $motivo) {
            $a = AutorizacionEgreso::create([
                'cobrador_id' => $cobrador->id,
                'max_amount_cents' => (int) $v['max_amount_cents'],
                'motivo' => $motivo === '' ? null : $motivo,
                'code_hash' => Hash::make($codigo),
                'expires_at' => now()->addMinutes($minutes),
                'created_by' => $admin->id,
                'created_at' => now(),
            ]);
            AuditLog::record('autorizacion.created', [
                'user_id' => $admin->id,
                'entity' => 'autorizaciones_egreso',
                'entity_id' => $a->id,
                'data' => ['cobrador' => $cobrador->name, 'max_amount_cents' => $a->max_amount_cents, 'motivo' => $a->motivo, 'minutes' => $minutes],
            ]);

            return $a;
        });

        return ['autorizacion' => $autorizacion, 'codigo' => $codigo];
    }

    /**
     * El cobrador canjea el código para un gasto que todavía no existe (gasto_id lo genera el
     * equipo). Queda usado y ligado a ese gasto; el gasto se acepta al sincronizar.
     */
    public function canjear(User $cobrador, array $data): AutorizacionEgreso
    {
        $v = Validator::make($data, [
            'codigo' => ['required', 'string', 'max:20'],
            'gasto_id' => ['required', 'uuid'],
            'amount_cents' => ['required', 'integer', 'min:1'],
        ], [
            'codigo.required' => 'Escriba el código de autorización.',
        ])->validate();

        $key = 'autorizacion:'.$cobrador->id;
        $max = (int) config('sinagua.codes.max_attempts');
        if (RateLimiter::tooManyAttempts($key, $max)) {
            $minutes = max(1, (int) ceil(RateLimiter::availableIn($key) / 60));
            throw new ApiException('pin_locked', "Se equivocó de código varias veces. Intente de nuevo en {$minutes} min.", 423);
        }

        $codigo = preg_replace('/\D/', '', $v['codigo']);
        $vigentes = AutorizacionEgreso::where('cobrador_id', $cobrador->id)->whereNull('used_at')
            ->where('expires_at', '>', now())->orderByDesc('created_at')->limit(10)->get();
        $match = $vigentes->first(fn (AutorizacionEgreso $a) => Hash::check($codigo, $a->code_hash));

        if ($match === null) {
            RateLimiter::hit($key, 15 * 60);
            $expired = AutorizacionEgreso::where('cobrador_id', $cobrador->id)->whereNull('used_at')->where('expires_at', '<=', now())
                ->orderByDesc('created_at')->limit(10)->get()->first(fn (AutorizacionEgreso $a) => Hash::check($codigo, $a->code_hash));
            if ($expired !== null) {
                throw new ApiException('autorizacion_vencida', 'Ese código ya venció. Pida otro al administrador.', 410);
            }
            throw new ApiException('autorizacion_invalida', 'El código de autorización no es correcto o ya se usó.', 422);
        }
        if ((int) $v['amount_cents'] > $match->max_amount_cents) {
            throw new ApiException('autorizacion_monto', 'El código autoriza hasta '.self::money($match->max_amount_cents).'; este gasto es mayor.', 422);
        }

        RateLimiter::clear($key);
        $match->forceFill(['used_at' => now(), 'gasto_id' => $v['gasto_id'], 'used_amount_cents' => (int) $v['amount_cents']])->save();

        return $match;
    }

    /** Autorización canjeada para este gasto, o error si el gasto la necesitaba y no la tiene. */
    public function paraGasto(User $actor, string $gastoId, int $amountCents, ?string $autorizacionId): ?AutorizacionEgreso
    {
        if (! $this->requiere($actor, $amountCents)) {
            return null;
        }

        $a = $autorizacionId === null ? null : AutorizacionEgreso::where('id', $autorizacionId)
            ->where('cobrador_id', $actor->id)->where('gasto_id', $gastoId)->whereNotNull('used_at')->first();
        if ($a === null || $amountCents > $a->max_amount_cents) {
            throw new ApiException('autorizacion_requerida',
                'Este gasto pasa del tope de '.self::money((int) $this->tope()).': necesita el código de autorización del administrador.', 422);
        }

        return $a;
    }

    public static function present(AutorizacionEgreso $a): array
    {
        return [
            'id' => $a->id,
            'cobrador_id' => $a->cobrador_id,
            'cobrador' => $a->cobrador?->name,
            'max_amount_cents' => $a->max_amount_cents,
            'motivo' => $a->motivo,
            'estado' => $a->estado(),
            'expires_at' => $a->expires_at->toIso8601String(),
            'created_at' => $a->created_at?->toIso8601String(),
            'autorizado_por' => $a->autor?->name,
            'used_at' => $a->used_at?->toIso8601String(),
            'used_amount_cents' => $a->used_amount_cents,
            'gasto_id' => $a->gasto_id,
        ];
    }

    private static function money(int $cents): string
    {
        return '$'.number_format($cents / 100, 2);
    }
}
