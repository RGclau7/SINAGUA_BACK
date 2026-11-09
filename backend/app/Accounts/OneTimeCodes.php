<?php

namespace App\Accounts;

use App\Exceptions\ApiException;
use App\Models\OneTimeCode;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Hash;

/**
 * PIN temporales de 6 números: se guardan con hash, vencen, sirven una sola vez y se anulan
 * tras varios intentos fallidos. Al pedir uno nuevo, el anterior deja de servir.
 */
class OneTimeCodes
{
    /** Crea el PIN y devuelve el texto (solo para enviarlo por correo; nunca se guarda así). */
    public function issue(User $user, string $purpose, CarbonInterface $expiresAt, ?string $deviceId = null): string
    {
        OneTimeCode::where('user_id', $user->id)->where('purpose', $purpose)->whereNull('used_at')
            ->update(['used_at' => now(), 'attempts' => 999]);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        OneTimeCode::create([
            'user_id' => $user->id,
            'purpose' => $purpose,
            'code_hash' => Hash::make($code),
            'device_id' => $deviceId,
            'expires_at' => $expiresAt,
            'created_at' => now(),
        ]);

        return $code;
    }

    /**
     * Revisa el PIN sin gastarlo (para validar lo demás antes). Devuelve el registro, que se marca
     * como usado con consume().
     *
     * @throws ApiException
     */
    public function check(User $user, string $purpose, ?string $code, ?string $deviceId = null): OneTimeCode
    {
        $record = OneTimeCode::where('user_id', $user->id)->where('purpose', $purpose)
            ->orderByDesc('created_at')->orderByDesc('id')->first();
        $max = (int) config('sinagua.codes.max_attempts');

        if ($record === null) {
            throw new ApiException('pin_invalid', 'El PIN no es correcto.', 422, ['attempts_left' => 0]);
        }
        if ($record->used_at !== null) {
            throw $record->attempts >= $max
                ? $this->exhausted($purpose)
                : new ApiException('pin_used', 'Ese PIN ya se usó. '.$this->askNew($purpose), 410);
        }
        if ($record->expires_at->isPast()) {
            throw new ApiException('pin_expired', 'Ese PIN ya venció. '.$this->askNew($purpose), 410);
        }
        if ($deviceId !== null && $record->device_id !== null && $record->device_id !== $deviceId) {
            throw new ApiException('pin_invalid', 'Ese PIN es para otro equipo.', 422, ['attempts_left' => 0]);
        }

        $code = $code === null ? '' : preg_replace('/\D/', '', $code);
        if ($code === '' || ! Hash::check($code, $record->code_hash)) {
            $record->attempts++;
            if ($record->attempts >= $max) {
                $record->used_at = now();
                $record->save();
                throw $this->exhausted($purpose);
            }
            $record->save();
            $left = $max - $record->attempts;

            throw new ApiException('pin_invalid', $left === 1
                ? 'El PIN no es correcto. Le queda 1 intento.'
                : "El PIN no es correcto. Le quedan {$left} intentos.", 422, ['attempts_left' => $left]);
        }

        return $record;
    }

    public function consume(OneTimeCode $record): void
    {
        $record->forceFill(['used_at' => now()])->save();
    }

    private function exhausted(string $purpose): ApiException
    {
        return new ApiException('pin_locked', 'Se equivocó de PIN demasiadas veces y ya no sirve. '.$this->askNew($purpose), 423);
    }

    private function askNew(string $purpose): string
    {
        return $purpose === OneTimeCode::ACTIVATION
            ? 'Pida uno nuevo al superadministrador.'
            : 'Vuelva a entrar con su contraseña para recibir otro.';
    }
}
