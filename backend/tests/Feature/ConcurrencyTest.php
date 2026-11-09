<?php

namespace Tests\Feature;

use App\Models\Cargo;
use App\Models\Community;
use App\Models\Pago;
use App\Models\PagoDetalle;
use App\Models\Roles;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Concurrencia real: varios procesos PHP (como varios workers de PHP-FPM, cada uno con su
 * conexión) cobran al mismo instante. Sin candados habría pagos dobles o folios repetidos;
 * con transacciones + SELECT ... FOR UPDATE + candados consultivos no ocurre.
 *
 * Solo en PostgreSQL: SQLite serializa toda escritura y no reproduce la carrera.
 */
class ConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private const WORKERS = 8;

    private Community $community;

    private User $admin;

    protected function setUp(): void
    {
        // Se revisa antes de migrar: en SQLite ni siquiera se crean las tablas.
        if ((getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? null)) !== 'pgsql') {
            $this->markTestSkipped('La prueba de concurrencia necesita PostgreSQL (varias conexiones reales).');
        }
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->community = Community::factory()->create(['cobro_fuera_de_dias' => true]);
        $this->admin = User::factory()->forCommunity($this->community)->create()->assignRole(Roles::ADMIN);
        $this->useCommunity($this->community);
        $this->actingAs($this->admin, 'api');
    }

    private function family(string $name): string
    {
        return $this->postJson('/api/v1/padron/registro', [
            'vivienda' => ['address' => "Calle {$name}"],
            'titular' => ['given_names' => $name, 'paternal_surname' => 'Pérez'],
            'familia' => ['name' => "Familia {$name}"],
        ])->assertCreated()->json('data.familia.id');
    }

    private function generateMonth(): void
    {
        $this->postJson('/api/v1/periodos/generar', ['year' => (int) now()->format('Y'), 'month' => (int) now()->format('n'), 'amount_cents' => 15000])
            ->assertSuccessful();
    }

    /**
     * Lanza los procesos a la vez y devuelve lo que respondió cada uno.
     *
     * @param  list<array{0: string, 1: list<string>}>  $intentos  [familia, cargos]
     * @return list<array<string, mixed>>
     */
    private function race(array $intentos): array
    {
        $start = microtime(true) + 1.5; // tiempo para que todos los procesos arranquen
        $env = [
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => config('database.connections.pgsql.host'),
            'DB_PORT' => (string) config('database.connections.pgsql.port'),
            'DB_DATABASE' => config('database.connections.pgsql.database'),
            'DB_USERNAME' => config('database.connections.pgsql.username'),
            'DB_PASSWORD' => config('database.connections.pgsql.password'),
            'APP_ENV' => 'testing',
        ];

        $results = Process::pool(function ($pool) use ($intentos, $start, $env) {
            foreach ($intentos as [$familia, $cargos]) {
                $pool->path(base_path())->env($env)->timeout(60)
                    ->command(['php', 'artisan', 'sinagua:intento-cobro', $this->admin->id, $familia, ...$cargos, '--start='.$start]);
            }
        })->start()->wait();

        return collect($results)->map(fn ($r) => json_decode(trim($r->output()), true) ?? ['ok' => false, 'code' => 'crash', 'message' => $r->errorOutput()])->values()->all();
    }

    public function test_same_month_paid_at_the_same_time_from_many_devices_is_charged_once(): void
    {
        $familia = $this->family('Ana');
        $this->generateMonth();
        $cargo = Cargo::where('familia_id', $familia)->value('id');

        $results = $this->race(array_fill(0, self::WORKERS, [$familia, [$cargo]]));

        $ok = array_values(array_filter($results, fn ($r) => $r['ok']));
        $this->assertCount(1, $ok, 'Exactamente un cobro gana: '.json_encode($results));
        $this->assertSame(
            array_fill(0, self::WORKERS - 1, 'already_paid'),
            array_column(array_filter($results, fn ($r) => ! $r['ok']), 'code'),
        );
        $this->assertSame(1, PagoDetalle::where('cargo_id', $cargo)->count(), 'El mes no se cobra dos veces.');
    }

    public function test_simultaneous_payments_of_different_families_get_unique_consecutive_folios(): void
    {
        $familias = array_map(fn ($i) => $this->family("F{$i}"), range(1, self::WORKERS));
        $this->generateMonth();

        $results = $this->race(array_map(fn ($f) => [$f, [Cargo::where('familia_id', $f)->value('id')]], $familias));

        $this->assertSame(array_fill(0, self::WORKERS, true), array_column($results, 'ok'), json_encode($results));
        $folios = Pago::orderBy('folio')->pluck('folio')->all();
        $this->assertSame(array_map(fn ($i) => sprintf('W-%06d', $i), range(1, self::WORKERS)), $folios);
    }
}
