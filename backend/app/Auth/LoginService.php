<?php

namespace App\Auth;

use App\Auth\Jwt\IssuedToken;
use App\Auth\Jwt\JwtService;
use App\Exceptions\ApiException;
use App\Models\AuditLog;
use App\Accounts\OneTimeCodes;
use App\Mail\LoginPinMail;
use App\Models\Device;
use App\Models\OneTimeCode;
use App\Models\Roles;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Tenancy\CurrentCommunity;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Inicio de sesión con bloqueo tras N intentos fallidos.
 *
 * Correo inexistente y contraseña incorrecta responden igual (mismo código y mismo
 * bloqueo), para no revelar qué correos existen.
 */
class LoginService
{
    public function __construct(
        private readonly CurrentCommunity $context,
        private readonly JwtService $jwt,
        private readonly OneTimeCodes $codes,
    ) {}

    /**
     * @return array{0: User, 1: IssuedToken}
     *
     * @throws ApiException
     */
    public function attempt(string $email, string $password, ?string $deviceId, ?string $ip, ?string $userAgent = null, ?string $twoFactorCode = null): array
    {
        $email = mb_strtolower(trim($email));
        $user = User::where('email', $email)->first();

        if ($user === null) {
            $this->failUnknownEmail($email, $password);
        }

        if ($user->locked_until?->isFuture()) {
            throw $this->locked($user->locked_until->diffInSeconds(now(), true));
        }

        // Dado de alta por correo y sin activar: todavía no tiene contraseña propia.
        if ($user->activated_at === null) {
            throw new ApiException('account_pending', 'Tu cuenta todavía no está activa. Actívala con el PIN que te llegó al correo (botón «Activar mi cuenta»).', 403);
        }

        if (! Hash::check($password, $user->password)) {
            $this->failForUser($user, $ip);
        }

        if (! $user->is_active) {
            $this->audit($user, 'auth.rejected_inactive', ['ip' => $ip]);
            throw new ApiException('account_inactive', 'La cuenta está desactivada. Consulte al administrador.', 403);
        }
        if ($user->community_id !== null && ! $user->community?->is_active) {
            throw new ApiException('community_inactive', 'Tu comunidad está desactivada por ahora. Habla con el superadministrador para volver a activarla.', 403);
        }
        // El superadministrador no pertenece a ninguna comunidad: puede entrar en cualquier equipo
        // (no ve ni sincroniza datos de comunidades).
        if ($deviceId !== null && $user->community_id !== null) {
            $this->assertDeviceUsable($user, $deviceId);
        }

        // El administrador confirma con un PIN al correo la primera vez que entra en un equipo.
        $trusted = $this->secondFactor($user, $deviceId, $twoFactorCode, $ip);

        $user->forceFill(['failed_login_attempts' => 0, 'locked_until' => null, 'last_login_at' => now()]);
        // Contraseñas creadas en el escritorio llegan con su propio costo de bcrypt.
        if (Hash::needsRehash($user->password)) {
            $user->password = $password;
        }
        // El acceso no cuenta como edición del usuario (updated_at es su versión para sincronizar).
        User::withoutTimestamps(fn () => $user->save());

        $token = $this->jwt->issue($user, $deviceId, $ip, $userAgent);

        $this->audit($user, 'auth.login', ['ip' => $ip, 'device_id' => $deviceId, 'two_factor' => $trusted]);

        return [$user, $token];
    }

    /**
     * Verificación en dos pasos del administrador (lo menos molesto): solo en un equipo que no ha
     * confirmado antes. Sin código, manda un PIN al correo y responde two_factor_required; con el
     * código correcto, el equipo queda de confianza y ya no se pide (ni en línea ni sin internet,
     * porque la entrada sin internet usa la credencial guardada tras esta primera entrada).
     *
     * @return string|null "verified" si se confirmó ahora, "trusted" si el equipo ya era de confianza
     */
    private function secondFactor(User $user, ?string $deviceId, ?string $code, ?string $ip): ?string
    {
        if (! config('sinagua.two_factor.enabled') || ! $user->hasRole(Roles::ADMIN)) {
            return null;
        }

        $days = (int) config('sinagua.two_factor.trusted_days');
        $trusted = $deviceId === null ? null : TrustedDevice::where('user_id', $user->id)->where('device_id', $deviceId)
            ->where('trusted_at', '>', now()->subDays($days))->first();
        if ($trusted !== null) {
            $trusted->forceFill(['last_used_at' => now()])->save();

            return 'trusted';
        }

        if ($code === null || trim($code) === '') {
            $minutes = (int) config('sinagua.codes.login_minutes');
            $pin = $this->codes->issue($user, OneTimeCode::LOGIN, now()->addMinutes($minutes), $deviceId);
            Mail::to($user->email)->send(new LoginPinMail($user->name, $pin, $minutes));
            $this->audit($user, 'auth.two_factor_sent', ['ip' => $ip, 'device_id' => $deviceId]);
            // La contraseña fue correcta: no cuenta como intento fallido.
            $user->forceFill(['failed_login_attempts' => 0, 'locked_until' => null]);
            User::withoutTimestamps(fn () => $user->save());

            throw new ApiException('two_factor_required', 'Es la primera vez que entras en este equipo. Te enviamos un código de 6 números a '.self::maskEmail($user->email).'.', 403, [
                'email_hint' => self::maskEmail($user->email),
                'expires_minutes' => $minutes,
            ]);
        }

        try {
            $record = $this->codes->check($user, OneTimeCode::LOGIN, $code, $deviceId);
        } catch (ApiException $e) {
            $this->audit($user, 'auth.two_factor_failed', ['ip' => $ip, 'device_id' => $deviceId, 'code' => $e->errorCode]);
            throw $e;
        }
        $this->codes->consume($record);

        if ($deviceId !== null) {
            TrustedDevice::updateOrCreate(['user_id' => $user->id, 'device_id' => $deviceId], ['trusted_at' => now(), 'last_used_at' => now()]);
        }

        return 'verified';
    }

    /** "lupita@sanisidro.mx" → "lu•••@sanisidro.mx" */
    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, min(2, mb_strlen($local))).'•••@'.$domain;
    }

    private function failForUser(User $user, ?string $ip): never
    {
        $max = config('sinagua.auth.max_attempts');

        // Un bloqueo vencido empieza un conteo nuevo.
        $attempts = $user->locked_until !== null ? 1 : $user->failed_login_attempts + 1;
        $lockedUntil = $attempts >= $max ? now()->addMinutes(config('sinagua.auth.lockout_minutes')) : null;

        $user->forceFill(['failed_login_attempts' => $attempts, 'locked_until' => $lockedUntil]);
        User::withoutTimestamps(fn () => $user->save());

        if ($lockedUntil !== null) {
            $this->audit($user, 'auth.locked', ['ip' => $ip, 'until' => $lockedUntil->toIso8601String()]);
            throw $this->locked(config('sinagua.auth.lockout_minutes') * 60);
        }

        $this->audit($user, 'auth.failed', ['ip' => $ip, 'attempt' => $attempts]);
        throw $this->invalid();
    }

    private function failUnknownEmail(string $email, string $password): never
    {
        // Mismo costo que verificar una contraseña real.
        static $dummyHash;
        $dummyHash ??= Hash::make(Str::random(40));
        Hash::check($password, $dummyHash);

        $key = 'login:'.sha1($email);
        $max = config('sinagua.auth.max_attempts');
        $decay = config('sinagua.auth.lockout_minutes') * 60;

        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw $this->locked(RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, $decay);

        throw RateLimiter::tooManyAttempts($key, $max) ? $this->locked($decay) : $this->invalid();
    }

    /**
     * Una cuenta entra desde cualquier equipo. Si el equipo era de otra comunidad, la app de
     * escritorio borra sus datos locales y se registra de nuevo con otro identificador, así que
     * aquí solo se revisa que un equipo de su propia comunidad no esté desactivado.
     */
    private function assertDeviceUsable(User $user, string $deviceId): void
    {
        $device = $this->context->runAsSystem(fn () => Device::find($deviceId));
        if ($device !== null && $device->community_id === $user->community_id && ! $device->is_active) {
            throw new ApiException('device_inactive', 'El equipo fue desactivado por el administrador.', 403);
        }
    }

    private function invalid(): ApiException
    {
        return new ApiException('invalid_credentials', 'Correo o contraseña incorrectos.', 401);
    }

    private function locked(int $seconds): ApiException
    {
        $minutes = max(1, (int) ceil($seconds / 60));

        return new ApiException(
            'account_locked',
            "Demasiados intentos fallidos. Intente de nuevo en {$minutes} min.",
            423,
            ['retry_after_seconds' => $seconds],
        );
    }

    private function audit(User $user, string $action, array $data): void
    {
        $this->context->runAsSystem(fn () => AuditLog::record($action, [
            'community_id' => $user->community_id,
            'user_id' => $user->id,
            'entity' => 'users',
            'entity_id' => $user->id,
            'data' => $data,
        ]));
    }
}
