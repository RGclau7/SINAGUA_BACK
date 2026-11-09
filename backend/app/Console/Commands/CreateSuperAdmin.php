<?php

namespace App\Console\Commands;

use App\Models\Roles;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Role;

class CreateSuperAdmin extends Command
{
    protected $signature = 'sinagua:superadmin {email?} {name?} {--desde-entorno : Usa SINAGUA_SUPERADMIN_EMAIL, _NOMBRE y _PASSWORD; si ya existe no hace nada}';

    protected $description = 'Crea un superadministrador (gestiona comunidades, no ve sus movimientos)';

    public function handle(): int
    {
        // En la nube (sin consola) el primer superadministrador se crea al arrancar con variables
        // de entorno. Solo se crea si no existe: nunca cambia una cuenta que ya está.
        if ($this->option('desde-entorno')) {
            $email = mb_strtolower(trim((string) env('SINAGUA_SUPERADMIN_EMAIL', '')));
            $password = (string) env('SINAGUA_SUPERADMIN_PASSWORD', '');
            $name = (string) env('SINAGUA_SUPERADMIN_NOMBRE', 'Superadministrador');
            if ($email === '' || $password === '') {
                return self::SUCCESS;
            }
            if (User::where('email', $email)->exists()) {
                $this->line('El superadministrador ya existe; no se cambió nada.');

                return self::SUCCESS;
            }
            $this->input->setArgument('name', $name);
        } else {
            $email = $this->argument('email');
            if ($email === null || $this->argument('name') === null) {
                $this->error('Uso: php artisan sinagua:superadmin correo@ejemplo.mx "Nombre"');

                return self::FAILURE;
            }
            $password = $this->secret('Contraseña (mínimo 10 caracteres)');
        }

        $validator = Validator::make(
            ['email' => $email, 'password' => $password],
            ['email' => ['required', 'email', 'unique:users,email'], 'password' => ['required', 'min:10']],
        );
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::create(['name' => $this->argument('name'), 'email' => $email, 'password' => $password]);
        $user->assignRole(Role::findOrCreate(Roles::SUPERADMIN, 'web'));

        $this->info("Superadministrador creado: {$user->email}");

        return self::SUCCESS;
    }
}
