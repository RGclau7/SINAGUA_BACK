<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'given_names', 'paternal_surname', 'maternal_surname', 'phone', 'address', 'email', 'password', 'is_active'])]
#[Hidden(['password', 'remember_token', 'failed_login_attempts', 'locked_until', 'security_pin', 'pin_failed_attempts', 'pin_locked_until'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, HasUuids, Notifiable;

    /**
     * Roles y permisos (spatie) viven en el guard "web" aunque la API autentique con el
     * guard "api" (JWT): así la matriz de permisos es una sola.
     */
    protected string $guard_name = 'web';

    /** Iguales a los defaults de las columnas, para que un usuario recién creado no los tenga en null. */
    protected $attributes = ['is_active' => true, 'failed_login_attempts' => 0];

    /** Las cuentas creadas directamente (no por invitación) nacen activas. */
    protected static function booted(): void
    {
        static::creating(function (User $user) {
            if ($user->invited_at === null && $user->activated_at === null) {
                $user->activated_at = now();
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
            'pin_locked_until' => 'datetime',
            'invited_at' => 'datetime',
            'activated_at' => 'datetime',
        ];
    }

    /** El correo se guarda en minúsculas; es único en todo el sistema. */
    protected function email(): Attribute
    {
        return Attribute::set(fn (string $value) => mb_strtolower(trim($value)));
    }

    /**
     * Panel que ve el usuario al entrar, según su rol de mayor jerarquía.
     */
    public function panel(): ?string
    {
        foreach (Roles::PANEL_PRIORITY as $role) {
            if ($this->hasRole($role)) {
                return $role;
            }
        }

        return null;
    }

    /**
     * Comunidad a la que pertenece. Se asigna en el servidor (nunca es fillable);
     * es null solo para el superadministrador.
     */
    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class);
    }

    /** Dado de alta por correo y todavía sin activar su cuenta (sin contraseña ni foto). */
    public function isPending(): bool
    {
        return $this->exists && $this->activated_at === null;
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(Roles::SUPERADMIN);
    }

    /** Sesiones JWT de la API (una por inicio de sesión). */
    public function authSessions(): HasMany
    {
        return $this->hasMany(AuthSession::class);
    }

    /** Cierra todas las sesiones: sus JWT dejan de servir en la siguiente petición. */
    public function revokeAllSessions(): void
    {
        $this->authSessions()->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }
}
