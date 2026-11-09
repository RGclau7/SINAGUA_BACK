<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;

/**
 * Demostración en vivo para la exposición: N cobros del MISMO mes enviados al mismo tiempo
 * a la API (peticiones HTTP en paralelo, atendidas por varios workers de PHP-FPM).
 * Resultado esperado: un 201 y el resto 409 already_paid; nunca un cobro doble.
 */
class DemoConcurrencia extends Command
{
    protected $signature = 'sinagua:demo-concurrencia
        {--url= : URL base de la API (por omisión APP_URL)}
        {--email=admin@sanisidro.test}
        {--password=prueba12345}
        {--solicitudes=10 : cuántos cobros simultáneos enviar}';

    protected $description = 'Envía varios cobros simultáneos del mismo mes y muestra que solo uno se registra';

    public function handle(): int
    {
        $api = rtrim($this->option('url') ?: config('app.url'), '/').'/api/v1';
        $n = max(2, (int) $this->option('solicitudes'));

        $token = Http::acceptJson()->post("{$api}/auth/login", ['email' => $this->option('email'), 'password' => $this->option('password')])
            ->throw()->json('data.token');
        $http = fn () => Http::acceptJson()->withToken($token);

        // Primera familia con algo por pagar y su primer cargo pendiente.
        [$familia, $cargo] = [null, null];
        foreach ($http()->get("{$api}/cobranza/buscar")->throw()->json('data') as $vivienda) {
            foreach ($vivienda['familias'] as $f) {
                if ($f['owed_cents'] > 0) {
                    $pendiente = collect($http()->get("{$api}/cobranza/familias/{$f['id']}")->throw()->json('data') ?? [])
                        ->firstWhere('status', 'por_pagar');
                    if ($pendiente !== null) {
                        [$familia, $cargo] = [$f, $pendiente];
                        break 2;
                    }
                }
            }
        }
        if ($cargo === null) {
            $this->warn('No hay familias con meses por pagar. Genere cargos o reinicie los datos de prueba (DemoSeeder).');

            return self::FAILURE;
        }

        $this->info("Familia: {$familia['name']} · Cargo: ".($cargo['label'] ?? $cargo['id'])." · {$n} cobros al mismo tiempo");

        $start = hrtime(true);
        $responses = Http::pool(fn (Pool $pool) => array_map(
            fn ($i) => $pool->as("cobro-{$i}")->acceptJson()->withToken($token)
                ->post("{$api}/pagos", ['familia_id' => $familia['id'], 'cargo_ids' => [$cargo['id']]]),
            range(1, $n),
        ));
        $ms = (int) ((hrtime(true) - $start) / 1e6);

        $rows = [];
        foreach ($responses as $name => $r) {
            $rows[] = [$name, $r->status(), $r->json('data.folio') ?? $r->json('error.code'), mb_strimwidth((string) $r->json('error.message'), 0, 60, '…')];
        }
        $this->table(['Petición', 'HTTP', 'Folio / código', 'Mensaje'], $rows);

        $created = collect($responses)->filter(fn ($r) => $r->status() === 201)->count();
        $this->line("Tiempo total: {$ms} ms · Registrados: {$created} · Rechazados: ".($n - $created));
        $created === 1
            ? $this->info('✔ Solo un cobro quedó registrado: la transacción con SELECT … FOR UPDATE serializó las peticiones.')
            : $this->error("✘ Se esperaba 1 cobro registrado y hubo {$created}.");

        return $created === 1 ? self::SUCCESS : self::FAILURE;
    }
}
