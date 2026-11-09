<?php

namespace App\Security;

use App\Exceptions\ApiException;
use App\Models\AuditLog;
use App\Models\User;
use App\Tenancy\CurrentCommunity;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * PIN de seguridad del superadministrador: 6 dígitos, se guarda con hash y se pide antes de
 * acciones delicadas (cambiar el administrador de una comunidad). Tras varios PIN incorrectos
 * se bloquea un rato, aparte del bloqueo de la contraseña.
 */
class SecurityPin
{
    public const PATTERN = '/^\d{6}$/';

    public function isSet(User $user): bool
    {
        return $user->security_pin !== null;
    }

    /**
     * Crea o cambia el PIN. Pide la contraseña de la cuenta para que nadie lo cambie con la
     * sesión abierta de otro.
     */
    public function set(User $user, string $password, string $pin): void
    {
        if (! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['password' => ['La contraseña no es correcta.']]);
        }
        if (! preg_match(self::PATTERN, $pin)) {
            throw ValidationException::withMessages(['pin' => ['El PIN debe tener 6 números.']]);
        }
        if (preg_match('/^(\d)\1{5}$/', $pin) || in_array($pin, ['123456', '654321', '012345'], true)) {
            throw ValidationException::withMessages(['pin' => ['Ese PIN es muy fácil de adivinar; elija otro.']]);
        }

        $created = $user->security_pin === null;
        $user->forceFill(['security_pin' => Hash::make($pin), 'pin_failed_attempts' => 0, 'pin_locked_until' => null]);
        User::withoutTimestamps(fn () => $user->save());

        $this->audit($user, $created ? 'security_pin.created' : 'security_pin.changed', []);
    }

    /**
     * Revisa el PIN; si es incorrecto suma un intento y, al llegar al máximo, lo bloquea.
     *
     * @throws ApiException
     */
    public function verify(User $user, ?string $pin): void
    {
        if ($user->security_pin === null) {
            throw new ApiException('pin_not_set', 'Primero cree su PIN de seguridad.', 409);
        }
        if ($user->pin_locked_until?->isFuture()) {
            throw $this->locked($user->pin_locked_until->diffInSeconds(now(), true));
        }

        if ($pin !== null && Hash::check($pin, $user->security_pin)) {
            if ($user->pin_failed_attempts > 0 || $user->pin_locked_until !== null) {
                $user->forceFill(['pin_failed_attempts' => 0, 'pin_locked_until' => null]);
                User::withoutTimestamps(fn () => $user->save());
            }

            return;
        }

        $max = (int) config('sinagua.pin.max_attempts');
        $attempts = $user->pin_locked_until !== null ? 1 : $user->pin_failed_attempts + 1;
        $lockedUntil = $attempts >= $max ? now()->addMinutes((int) config('sinagua.pin.lockout_minutes')) : null;
        $user->forceFill(['pin_failed_attempts' => $attempts, 'pin_locked_until' => $lockedUntil]);
        User::withoutTimestamps(fn () => $user->save());

        $this->audit($user, $lockedUntil ? 'security_pin.locked' : 'security_pin.failed', ['attempt' => $attempts]);

        if ($lockedUntil !== null) {
            throw $this->locked((int) config('sinagua.pin.lockout_minutes') * 60);
        }

        $left = $max - $attempts;
        throw new ApiException('pin_invalid', $left === 1
            ? 'El PIN no es correcto. Le queda 1 intento.'
            : "El PIN no es correcto. Le quedan {$left} intentos.", 422, ['attempts_left' => $left]);
    }

    private function audit(User $user, string $action, array $data): void
    {
        app(CurrentCommunity::class)->runAsSystem(fn () => AuditLog::record($action, [
            'community_id' => $user->community_id,
            'user_id' => $user->id,
            'entity' => 'users',
            'entity_id' => $user->id,
            'data' => $data === [] ? null : $data,
        ]));
    }

    private function locked(int $seconds): ApiException
    {
        $minutes = max(1, (int) ceil($seconds / 60));

        return new ApiException('pin_locked', "Se equivocó de PIN varias veces. Intente de nuevo en {$minutes} min.", 423, ['retry_after_seconds' => $seconds]);
    }
}
