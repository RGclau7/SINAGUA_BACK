<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Sesión de la API. Su id es el claim "jti" del JWT que la representa.
 *
 * @property string $id
 * @property string $user_id
 * @property ?string $device_id
 * @property Carbon|null $last_used_at
 * @property Carbon $expires_at
 * @property Carbon|null $revoked_at
 */
class AuthSession extends Model
{
    use HasUuids;

    /** Cada cuántos segundos, como máximo, se guarda last_used_at (evita una escritura por petición). */
    public const TOUCH_EVERY_SECONDS = 60;

    protected $fillable = ['id', 'user_id', 'device_id', 'name', 'ip', 'user_agent', 'last_used_at', 'expires_at'];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Sesiones que todavía pueden usarse (sin revocar, sin vencer y sin pasar el tiempo de inactividad). */
    public function scopeActive(Builder $query): Builder
    {
        $idleLimit = now()->subMinutes(config('sinagua.auth.idle_minutes'));

        return $query->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->where(fn (Builder $q) => $q->where('last_used_at', '>', $idleLimit)
                ->orWhere(fn (Builder $q) => $q->whereNull('last_used_at')->where('created_at', '>', $idleLimit)));
    }

    public function isUsable(): bool
    {
        $lastActivity = $this->last_used_at ?? $this->created_at;

        return $this->revoked_at === null
            && $this->expires_at->isFuture()
            && $lastActivity->gt(now()->subMinutes(config('sinagua.auth.idle_minutes')));
    }

    /**
     * Marca el uso de la sesión con un UPDATE condicional y atómico: si llegan varias
     * peticiones a la vez, solo la primera escribe y las demás no esperan ni se pisan
     * (no hay lectura-modificación-escritura en PHP, así que no hay condición de carrera).
     */
    public function touchIfStale(): void
    {
        $now = now();
        $threshold = $now->copy()->subSeconds(self::TOUCH_EVERY_SECONDS);

        $updated = static::query()
            ->whereKey($this->getKey())
            ->where(fn (Builder $q) => $q->whereNull('last_used_at')->orWhere('last_used_at', '<', $threshold))
            ->update(['last_used_at' => $now]);

        if ($updated > 0) {
            $this->last_used_at = $now;
            $this->syncOriginalAttribute('last_used_at');
        }
    }

    public function revoke(): void
    {
        if ($this->revoked_at === null) {
            $this->forceFill(['revoked_at' => now()])->save();
        }
    }
}
