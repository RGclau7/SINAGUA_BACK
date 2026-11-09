<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/** Si el superadministrador olvida su PIN: lo borra para que cree uno nuevo en la app. */
class ResetSecurityPin extends Command
{
    protected $signature = 'sinagua:borrar-pin {email}';

    protected $description = 'Borra el PIN de seguridad de un superadministrador (lo vuelve a crear en la app)';

    public function handle(): int
    {
        $user = User::where('email', mb_strtolower(trim($this->argument('email'))))->first();
        if ($user === null || ! $user->isSuperAdmin()) {
            $this->error('No hay un superadministrador con ese correo.');

            return self::FAILURE;
        }

        $user->forceFill(['security_pin' => null, 'pin_failed_attempts' => 0, 'pin_locked_until' => null]);
        User::withoutTimestamps(fn () => $user->save());
        $this->info('PIN borrado. La próxima vez que lo necesite, la app le pedirá crear uno nuevo.');

        return self::SUCCESS;
    }
}
