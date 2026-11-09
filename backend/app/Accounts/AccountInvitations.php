<?php

namespace App\Accounts;

use App\Exceptions\ApiException;
use App\Mail\ActivationPinMail;
use App\Models\AuditLog;
use App\Models\Community;
use App\Models\OneTimeCode;
use App\Models\Roles;
use App\Models\User;
use App\Tenancy\CurrentCommunity;
use App\Users\UserChangePublisher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Alta por correo: el superadministrador escribe nombre y correo del nuevo administrador o
 * cobrador; la cuenta queda "pendiente" y le llega un PIN de 6 números para activarla.
 * Invitar otra vez a un correo pendiente le manda un PIN nuevo (el anterior deja de servir).
 */
class AccountInvitations
{
    public const ROLES = [Roles::ADMIN, Roles::COBRADOR];

    public function __construct(
        private readonly OneTimeCodes $codes,
        private readonly CurrentCommunity $context,
        private readonly UserChangePublisher $publisher,
    ) {}

    /**
     * @param  array{given_names?: string, paternal_surname?: string, maternal_surname?: ?string, phone?: string, address?: string}  $person
     */
    public function invite(Community $community, string $name, string $email, string $role, User $actor, array $person = []): User
    {
        $email = mb_strtolower(trim($email));
        if (! in_array($role, self::ROLES, true)) {
            throw new ApiException('validation_error', 'Elija si será administrador o cobrador.', 422);
        }

        $user = $this->context->runAsSystem(fn () => User::where('email', $email)->first());
        $resend = false;
        if ($user !== null) {
            if ($user->activated_at !== null || $user->community_id !== $community->id) {
                throw new ApiException('validation_error', 'Ese correo ya está registrado.', 422, ['email' => ['Ese correo ya está registrado.']]);
            }
            $resend = true;
        }

        return DB::transaction(fn () => $this->context->runAsSystem(function () use ($community, $name, $email, $role, $actor, $person, $user, $resend) {
            if ($user === null) {
                $user = new User([
                    'name' => trim($name),
                    'given_names' => $person['given_names'] ?? trim($name),
                    'paternal_surname' => $person['paternal_surname'] ?? null,
                    'maternal_surname' => $person['maternal_surname'] ?? null,
                    'phone' => $person['phone'] ?? null,
                    'address' => $person['address'] ?? null,
                    'email' => $email,
                    // Nadie la conoce: la persona pone la suya al activar.
                    'password' => Str::random(48),
                ]);
                $user->community_id = $community->id;
            }
            $user->invited_at = now();
            $user->activated_at = null;
            $user->save();
            $user->syncRoles([Role::findOrCreate($role, 'web')]);

            $hours = (int) config('sinagua.codes.activation_hours');
            $pin = $this->codes->issue($user, OneTimeCode::ACTIVATION, now()->addHours($hours));

            AuditLog::record($resend ? 'user.invitation_resent' : 'user.invited', [
                'community_id' => $community->id,
                'user_id' => $actor->id,
                'entity' => 'users',
                'entity_id' => $user->id,
                'data' => ['name' => $user->name, 'email' => $email, 'role' => $role, 'expires_hours' => $hours],
            ]);
            $this->publisher->publish($user, $resend ? 'update' : 'create');

            Mail::to($email)->send(new ActivationPinMail(
                $user->name,
                $pin,
                self::activationLink($email),
                $hours,
                $community->name,
                $role === Roles::ADMIN ? 'administrador' : 'cobrador',
            ));

            return $user;
        }));
    }

    public static function activationLink(string $email): string
    {
        return config('sinagua.public_url').'/activar?correo='.rawurlencode($email);
    }
}
