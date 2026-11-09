<?php

namespace Tests\Feature;

use App\Comunidad\Paquetes;
use App\Models\Cargo;
use App\Models\Community;
use App\Models\Familia;
use App\Models\Roles;
use App\Models\User;
use App\Models\Vivienda;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Paquetes por casa: la comunidad define cuántas familias caben y la cuota; se cobra una cuota
 * por casa, a nombre de su familia titular.
 */
class PaquetesTest extends TestCase
{
    use RefreshDatabase;

    private Community $community;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->travelTo('2026-10-15 10:00:00');
        $this->community = Community::factory()->create();
        $admin = User::factory()->forCommunity($this->community)->create()->assignRole(Roles::ADMIN);
        $this->useCommunity($this->community);
        $this->actingAs($admin, 'api');
    }

    private function register(string $address, string $given, ?string $paquete = null, bool $confirm = false)
    {
        return $this->postJson('/api/v1/padron/registro', [
            'vivienda' => array_filter(['address' => $address, 'paquete_id' => $paquete]),
            'titular' => ['given_names' => $given, 'paternal_surname' => 'López'],
            'confirm_shared_address' => $confirm,
        ]);
    }

    public function test_communities_start_with_three_default_packages_and_new_houses_get_the_smallest(): void
    {
        $list = Paquetes::of($this->community);
        $this->assertSame([1, 2, 3], array_column($list, 'familias'));

        $id = $this->register('Calle Uno 1', 'Ana')->assertCreated()->json('data.vivienda.id');
        $this->assertSame('p1', Vivienda::find($id)->paquete_id);
    }

    public function test_a_house_cannot_hold_more_families_than_its_package(): void
    {
        $this->register('Calle Uno 1', 'Ana')->assertCreated();

        $this->register('Calle Uno 1', 'Beto', confirm: true)
            ->assertStatus(422)->assertJsonPath('error.code', 'paquete_full');
        $this->assertSame(1, Familia::count());

        $vivienda = Vivienda::first();
        $this->patchJson("/api/v1/padron/viviendas/{$vivienda->id}", ['paquete_id' => 'p2'])->assertOk();
        $this->register('Calle Uno 1', 'Beto', confirm: true)->assertCreated();
        $this->assertSame(2, Familia::count());
    }

    public function test_registers_one_titular_per_family_of_the_package_and_no_more(): void
    {
        $otro = fn (string $given) => ['given_names' => $given, 'paternal_surname' => 'Ruiz'];

        $this->postJson('/api/v1/padron/registro', [
            'vivienda' => ['address' => 'Calle Dos 2', 'paquete_id' => 'p2'],
            'titular' => ['given_names' => 'Ana', 'paternal_surname' => 'López'],
            'otros_titulares' => [$otro('Beto'), $otro('Carla')],
        ])->assertStatus(422)->assertJsonPath('error.code', 'paquete_full');
        $this->assertSame(0, Familia::count());
        $this->assertSame(0, Vivienda::count());

        $res = $this->postJson('/api/v1/padron/registro', [
            'vivienda' => ['address' => 'Calle Dos 2', 'paquete_id' => 'p2'],
            'titular' => ['given_names' => 'Ana', 'paternal_surname' => 'López'],
            'otros_titulares' => [$otro('Beto')],
        ])->assertCreated();
        $this->assertSame('Ruiz', $res->json('data.otros.0.familia.name'));
        $this->assertSame(2, Familia::where('vivienda_id', $res->json('data.vivienda.id'))->count());
    }

    public function test_a_full_house_is_rejected_before_asking_to_confirm_the_shared_address(): void
    {
        $this->register('Calle Uno 1', 'Ana')->assertCreated();

        $this->register('Calle Uno 1', 'Beto')->assertStatus(422)->assertJsonPath('error.code', 'paquete_full');
    }

    public function test_a_family_cannot_come_back_to_a_house_that_is_already_full(): void
    {
        $ana = $this->register('Calle Uno 1', 'Ana')->assertCreated()->json('data.familia.id');
        $this->postJson("/api/v1/padron/familias/{$ana}/deactivate")->assertOk();
        $this->register('Calle Uno 1', 'Beto')->assertCreated();

        $this->postJson("/api/v1/padron/familias/{$ana}/activate")
            ->assertStatus(422)->assertJsonPath('error.code', 'paquete_full');
    }

    public function test_cannot_pick_an_unknown_package(): void
    {
        $this->register('Calle Uno 1', 'Ana', 'no-existe')->assertStatus(422);
    }

    public function test_one_charge_per_house_with_the_package_cuota_and_none_for_the_other_families(): void
    {
        $this->postJson('/api/v1/cuotas', ['amount_cents' => 15000, 'valid_from' => '2026-01'])->assertCreated();
        $this->register('Calle Uno 1', 'Ana', 'p2')->assertCreated();
        $this->register('Calle Uno 1', 'Beto', 'p2', true)->assertCreated();
        $this->register('Calle Dos 2', 'Cata')->assertCreated(); // p1

        $this->patchJson('/api/v1/ajustes', ['paquetes' => [
            ['id' => 'p1', 'name' => 'Individual', 'familias' => 1, 'cuota_cents' => 18000, 'activo' => true],
            ['id' => 'p2', 'name' => 'Doble', 'familias' => 2, 'cuota_cents' => 25000, 'activo' => true],
            ['id' => 'p3', 'name' => 'Triple', 'familias' => 3, 'cuota_cents' => 30000, 'activo' => true],
        ]])->assertOk();

        $this->postJson('/api/v1/periodos/generar', ['year' => 2026, 'month' => 10])->assertSuccessful();

        $this->assertSame(2, Cargo::where('concept', Cargo::CUOTA)->count());
        $amounts = Cargo::where('concept', Cargo::CUOTA)->pluck('amount_cents')->sort()->values()->all();
        $this->assertSame([18000, 25000], $amounts);

        // Repetir no duplica.
        $this->postJson('/api/v1/periodos/generar', ['year' => 2026, 'month' => 10])->assertSuccessful();
        $this->assertSame(2, Cargo::where('concept', Cargo::CUOTA)->count());
    }

    public function test_a_package_in_use_cannot_be_removed(): void
    {
        $this->register('Calle Uno 1', 'Ana')->assertCreated();

        $this->patchJson('/api/v1/ajustes', ['paquetes' => [
            ['id' => 'p2', 'name' => 'Doble', 'familias' => 2, 'cuota_cents' => null, 'activo' => true],
        ]])->assertStatus(422);
    }

    public function test_every_active_package_needs_a_price_and_the_base_cuota_follows_the_smallest_one(): void
    {
        $this->patchJson('/api/v1/ajustes', ['paquetes' => Paquetes::defaults()])->assertOk(); // sin cambios: no exige precio

        $this->patchJson('/api/v1/ajustes', ['paquetes' => [
            ['id' => 'p1', 'name' => 'Individual', 'familias' => 1, 'cuota_cents' => null, 'activo' => true],
        ]])->assertStatus(422);

        $this->patchJson('/api/v1/ajustes', ['paquetes' => [
            ['id' => 'p1', 'name' => 'Individual', 'familias' => 1, 'cuota_cents' => 17500, 'activo' => true],
            ['id' => 'p2', 'name' => 'Doble', 'familias' => 2, 'cuota_cents' => 25000, 'activo' => true],
            ['id' => 'p3', 'name' => 'Triple', 'familias' => 3, 'cuota_cents' => 30000, 'activo' => false],
        ]])->assertOk()->assertJsonPath('data.cuota_cents', 17500);
    }

    public function test_a_new_device_gets_how_to_collect_after_the_sync_history_was_cleared(): void
    {
        // Ajustes con precio guardados en la comunidad, pero el historial se borró.
        $this->community->forceFill([
            'multa_cents' => 5000,
            'meses_adelanto' => 3,
            'paquetes' => json_encode([['id' => 'p1', 'name' => 'Paquete 1', 'familias' => 1, 'cuota_cents' => 15000, 'activo' => true]]),
        ])->save();
        \App\Models\SyncChange::query()->delete();
        $device = (string) \Illuminate\Support\Str::uuid();
        \App\Models\Device::create(['id' => $device, 'name' => 'Caja', 'receipt_prefix' => 'E09']);
        $this->assertSame([], $this->getJson("/api/v1/sync/pull?device_id={$device}")->assertOk()->json('data.changes'));

        (require database_path('migrations/2026_11_08_020000_republish_missing_sync_changes.php'))->up();

        $changes = collect($this->getJson("/api/v1/sync/pull?device_id={$device}")->assertOk()->json('data.changes'));
        $ajustes = $changes->firstWhere('entity', 'ajustes');
        $this->assertSame(5000, $ajustes['payload']['multa_cents']);
        $this->assertSame(3, $ajustes['payload']['meses_adelanto']);
        $this->assertSame(15000, $ajustes['payload']['paquetes'][0]['cuota_cents']);
        $this->assertNotNull($changes->firstWhere('entity', 'usuarios'));

        // Correrla otra vez no repite nada.
        $before = \App\Models\SyncChange::count();
        (require database_path('migrations/2026_11_08_020000_republish_missing_sync_changes.php'))->up();
        $this->assertSame($before, \App\Models\SyncChange::count());
    }
}
