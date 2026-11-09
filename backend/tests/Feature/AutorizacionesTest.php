<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Community;
use App\Models\Device;
use App\Models\Gasto;
use App\Models\Roles;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Fase 3: el cobrador registra salidas; las que pasan el tope necesitan código del administrador. */
class AutorizacionesTest extends TestCase
{
    use RefreshDatabase;

    private Community $community;

    private User $admin;

    private User $cobrador;

    private string $deviceId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->community = Community::factory()->create();
        $this->admin = User::factory()->forCommunity($this->community)->create(['name' => 'Lupita Admin'])->assignRole(Roles::ADMIN);
        $this->cobrador = User::factory()->forCommunity($this->community)->create(['name' => 'Juan Cobrador'])->assignRole(Roles::COBRADOR);
        $this->useCommunity($this->community);
        $this->deviceId = (string) Str::uuid();
        Device::create(['id' => $this->deviceId, 'name' => 'Caja', 'receipt_prefix' => 'E01']);

        // Entra dinero al fondo de cuotas para poder gastar y fija el tope en $500.
        $this->actingAs($this->admin, 'api');
        $this->patchJson('/api/v1/ajustes', ['tope_egreso_cents' => 50000])->assertOk()->assertJsonPath('data.tope_egreso_cents', 50000);
    }

    private function pushGasto(int $cents, ?string $autorizacionId = null, ?string $id = null): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/sync/push', ['device_id' => $this->deviceId, 'mutations' => [[
            'mutation_id' => (string) Str::uuid(),
            'entity' => 'gastos',
            'entity_id' => $id ?? (string) Str::uuid(),
            'op' => 'create',
            'payload' => ['concepto' => 'Refacción de la bomba', 'amount_cents' => $cents, 'spent_on' => now()->toDateString(), 'autorizacion_id' => $autorizacionId],
            'occurred_at' => now()->toIso8601String(),
        ]]]);
    }

    private function generar(int $max = 80000): array
    {
        $this->actingAs($this->admin, 'api');

        return $this->postJson('/api/v1/autorizaciones', ['cobrador_id' => $this->cobrador->id, 'max_amount_cents' => $max, 'motivo' => 'Bomba'])
            ->assertCreated()->assertJsonPath('data.estado', 'vigente')->json('data');
    }

    public function test_under_the_limit_no_code_is_needed(): void
    {
        $this->actingAs($this->cobrador, 'api');
        $this->pushGasto(50000)->assertJsonPath('data.results.0.status', 'accepted');
    }

    public function test_over_the_limit_without_code_is_rejected(): void
    {
        $this->actingAs($this->cobrador, 'api');
        $this->pushGasto(50001)
            ->assertJsonPath('data.results.0.status', 'rejected')
            ->assertJsonPath('data.results.0.code', 'autorizacion_requerida')
            ->assertJsonPath('data.results.0.message', 'Este gasto pasa del tope de $500.00: necesita el código de autorización del administrador.');
        $this->assertSame(0, Gasto::count());
    }

    public function test_over_the_limit_with_a_redeemed_code_is_accepted_and_audited(): void
    {
        $a = $this->generar();
        $this->assertMatchesRegularExpression('/^\d{6}$/', $a['codigo']);
        $gastoId = (string) Str::uuid();

        $this->actingAs($this->cobrador, 'api');
        $this->postJson('/api/v1/autorizaciones/canjear', ['codigo' => $a['codigo'], 'gasto_id' => $gastoId, 'amount_cents' => 75000])
            ->assertOk()->assertJsonPath('data.estado', 'usada')->assertJsonPath('data.autorizado_por', 'Lupita Admin');
        $this->pushGasto(75000, $a['id'], $gastoId)->assertJsonPath('data.results.0.status', 'accepted');

        $this->assertSame($a['id'], Gasto::findOrFail($gastoId)->autorizacion_id);
        $log = AuditLog::where('action', 'gasto.created')->latest('id')->firstOrFail();
        $this->assertSame('Lupita Admin', $log->data['autorizado_por']);
        $this->assertTrue(AuditLog::where('action', 'autorizacion.used')->exists());
        $this->assertTrue(AuditLog::where('action', 'autorizacion.created')->exists());

        // Un solo uso.
        $this->postJson('/api/v1/autorizaciones/canjear', ['codigo' => $a['codigo'], 'gasto_id' => (string) Str::uuid(), 'amount_cents' => 75000])
            ->assertStatus(422)->assertJsonPath('error.code', 'autorizacion_invalida');
    }

    public function test_code_is_tied_to_amount_cobrador_and_time(): void
    {
        $a = $this->generar(60000);

        $this->actingAs($this->cobrador, 'api');
        $this->postJson('/api/v1/autorizaciones/canjear', ['codigo' => $a['codigo'], 'gasto_id' => (string) Str::uuid(), 'amount_cents' => 60001])
            ->assertStatus(422)->assertJsonPath('error.code', 'autorizacion_monto');

        $otro = User::factory()->forCommunity($this->community)->create()->assignRole(Roles::COBRADOR);
        $this->actingAs($otro, 'api');
        $this->postJson('/api/v1/autorizaciones/canjear', ['codigo' => $a['codigo'], 'gasto_id' => (string) Str::uuid(), 'amount_cents' => 100])
            ->assertStatus(422);

        $this->travel(31)->minutes();
        $this->actingAs($this->cobrador, 'api');
        $this->postJson('/api/v1/autorizaciones/canjear', ['codigo' => $a['codigo'], 'gasto_id' => (string) Str::uuid(), 'amount_cents' => 100])
            ->assertStatus(410)->assertJsonPath('error.code', 'autorizacion_vencida');
    }

    public function test_a_code_redeemed_for_another_expense_does_not_count(): void
    {
        $a = $this->generar();
        $this->actingAs($this->cobrador, 'api');
        $this->postJson('/api/v1/autorizaciones/canjear', ['codigo' => $a['codigo'], 'gasto_id' => (string) Str::uuid(), 'amount_cents' => 70000])->assertOk();

        $this->pushGasto(70000, $a['id'])->assertJsonPath('data.results.0.code', 'autorizacion_requerida');
    }

    public function test_admin_never_needs_a_code_and_only_admin_generates_them(): void
    {
        $this->actingAs($this->admin, 'api');
        $this->pushGasto(900000)->assertJsonPath('data.results.0.status', 'accepted');

        $this->actingAs($this->cobrador, 'api');
        $this->postJson('/api/v1/autorizaciones', ['cobrador_id' => $this->cobrador->id, 'max_amount_cents' => 100])->assertForbidden();
        $this->getJson('/api/v1/autorizaciones')->assertForbidden();

        $this->actingAs($this->admin, 'api');
        $list = $this->getJson('/api/v1/autorizaciones')->assertOk();
        $this->assertSame(50000, $list->json('data.tope_egreso_cents'));
        $this->assertSame('Juan Cobrador', $list->json('data.cobradores.0.name'));
    }

    public function test_too_many_wrong_codes_lock_redeeming_for_a_while(): void
    {
        $a = $this->generar();
        $wrong = $a['codigo'] === '111111' ? '222222' : '111111';
        $this->actingAs($this->cobrador, 'api');
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/autorizaciones/canjear', ['codigo' => $wrong, 'gasto_id' => (string) Str::uuid(), 'amount_cents' => 100])->assertStatus(422);
        }
        $this->postJson('/api/v1/autorizaciones/canjear', ['codigo' => $a['codigo'], 'gasto_id' => (string) Str::uuid(), 'amount_cents' => 100])
            ->assertStatus(423)->assertJsonPath('error.code', 'pin_locked');
    }
}
