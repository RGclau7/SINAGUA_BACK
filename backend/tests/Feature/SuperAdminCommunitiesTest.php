<?php

namespace Tests\Feature;

use App\Auth\Jwt\JwtService;
use App\Models\AuditLog;
use App\Models\Community;
use App\Models\Roles;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Fase 1: tabla de comunidades, PIN de seguridad, cambio de administrador y desactivar. */
class SuperAdminCommunitiesTest extends TestCase
{
    use RefreshDatabase;

    private User $super;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->super = User::factory()->superAdmin()->create(['password' => 'clave-del-super']);
        $this->actingAs($this->super, 'api');
    }

    private function member(Community $c, string $role, array $attrs = []): User
    {
        $u = User::factory()->forCommunity($c)->create($attrs);
        $u->assignRole($role);

        return $u;
    }

    private function withPin(string $pin = '482913'): void
    {
        $this->putJson('/api/v1/admin/security-pin', ['password' => 'clave-del-super', 'pin' => $pin, 'pin_confirmation' => $pin])
            ->assertOk()->assertJsonPath('data.is_set', true);
    }

    public function test_list_shows_location_admin_users_and_status(): void
    {
        $c = Community::factory()->create(['name' => 'El Salto', 'municipality' => 'Dolores', 'zone' => 'Barrio Alto']);
        $this->member($c, Roles::ADMIN, ['name' => 'Ana Pérez', 'phone' => '4151112233']);
        $this->member($c, Roles::COBRADOR);
        Community::factory()->create(['name' => 'Vacía', 'is_active' => false]);

        $rows = collect($this->getJson('/api/v1/admin/communities')->assertOk()->json('data'))->keyBy('name');

        $this->assertSame('Dolores', $rows['El Salto']['municipality']);
        $this->assertSame('Barrio Alto', $rows['El Salto']['zone']);
        $this->assertSame('Ana Pérez', $rows['El Salto']['admin']['name']);
        $this->assertSame('4151112233', $rows['El Salto']['admin']['phone']);
        $this->assertSame(2, $rows['El Salto']['users_count']);
        $this->assertTrue($rows['El Salto']['is_active']);
        $this->assertNull($rows['Vacía']['admin']);
        $this->assertSame(0, $rows['Vacía']['users_count']);
        $this->assertFalse($rows['Vacía']['is_active']);
    }

    public function test_pin_is_hashed_and_needs_the_account_password(): void
    {
        $this->getJson('/api/v1/admin/security-pin')->assertJsonPath('data.is_set', false);

        $this->putJson('/api/v1/admin/security-pin', ['password' => 'otra', 'pin' => '482913', 'pin_confirmation' => '482913'])
            ->assertUnprocessable()->assertJsonPath('error.details.password.0', 'La contraseña no es correcta.');
        $this->putJson('/api/v1/admin/security-pin', ['password' => 'clave-del-super', 'pin' => '111111', 'pin_confirmation' => '111111'])
            ->assertUnprocessable();
        $this->putJson('/api/v1/admin/security-pin', ['password' => 'clave-del-super', 'pin' => '12a456', 'pin_confirmation' => '12a456'])
            ->assertUnprocessable()->assertJsonPath('error.details.pin.0', 'El PIN debe tener 6 números.');

        $this->withPin();
        $stored = $this->super->fresh()->security_pin;
        $this->assertNotSame('482913', $stored);
        $this->assertTrue(Hash::check('482913', $stored));
        $this->assertArrayNotHasKey('security_pin', $this->super->fresh()->toArray());
    }

    public function test_change_admin_requires_pin_and_records_who_did_it(): void
    {
        $c = Community::factory()->create();
        $old = $this->member($c, Roles::ADMIN, ['name' => 'Admin Viejo']);
        $new = $this->member($c, Roles::COBRADOR, ['name' => 'Admin Nuevo', 'phone' => '4150000000']);

        // Sin PIN creado.
        $this->postJson("/api/v1/admin/communities/{$c->id}/admin", ['user_id' => $new->id, 'pin' => '482913'])
            ->assertStatus(409)->assertJsonPath('error.code', 'pin_not_set');

        $this->withPin();

        // PIN incorrecto: no cambia nada.
        $this->postJson("/api/v1/admin/communities/{$c->id}/admin", ['user_id' => $new->id, 'pin' => '000000'])
            ->assertStatus(422)->assertJsonPath('error.code', 'pin_invalid')->assertJsonPath('error.details.attempts_left', 4);
        $this->assertTrue($old->fresh()->hasRole(Roles::ADMIN));

        $this->postJson("/api/v1/admin/communities/{$c->id}/admin", ['user_id' => $new->id, 'pin' => '482913'])
            ->assertOk()->assertJsonPath('data.admin.name', 'Admin Nuevo');

        $this->assertTrue($new->fresh()->hasRole(Roles::ADMIN));
        $this->assertFalse($old->fresh()->hasRole(Roles::ADMIN));
        $this->assertFalse($old->fresh()->is_active, 'por defecto el anterior se desactiva');

        $this->useCommunity($c);
        $log = AuditLog::where('action', 'community.admin_changed')->firstOrFail();
        $this->assertSame($this->super->id, $log->user_id);
        $this->assertSame('Admin Nuevo', $log->data['admin_name']);
        $this->assertArrayNotHasKey('pin', $log->data);
    }

    public function test_previous_admin_can_stay_as_cobrador(): void
    {
        $c = Community::factory()->create();
        $old = $this->member($c, Roles::ADMIN);
        $new = $this->member($c, Roles::COBRADOR);
        $this->withPin();

        $this->postJson("/api/v1/admin/communities/{$c->id}/admin", ['user_id' => $new->id, 'pin' => '482913', 'previous' => 'cobrador'])->assertOk();

        $this->assertTrue($old->fresh()->is_active);
        $this->assertTrue($old->fresh()->hasRole(Roles::COBRADOR));
    }

    public function test_pin_locks_after_too_many_wrong_attempts(): void
    {
        $c = Community::factory()->create();
        $new = $this->member($c, Roles::COBRADOR);
        $this->withPin();

        for ($i = 0; $i < 4; $i++) {
            $this->postJson("/api/v1/admin/communities/{$c->id}/admin", ['user_id' => $new->id, 'pin' => '000000'])->assertStatus(422);
        }
        $this->postJson("/api/v1/admin/communities/{$c->id}/admin", ['user_id' => $new->id, 'pin' => '000000'])
            ->assertStatus(423)->assertJsonPath('error.code', 'pin_locked');
        // Bloqueado: ni el correcto pasa.
        $this->postJson("/api/v1/admin/communities/{$c->id}/admin", ['user_id' => $new->id, 'pin' => '482913'])
            ->assertStatus(423);
        $this->assertFalse($new->fresh()->hasRole(Roles::ADMIN));

        $this->travel(16)->minutes();
        $this->postJson("/api/v1/admin/communities/{$c->id}/admin", ['user_id' => $new->id, 'pin' => '482913'])->assertOk();
    }

    public function test_cannot_pick_a_person_from_another_community(): void
    {
        $c = Community::factory()->create();
        $stranger = $this->member(Community::factory()->create(), Roles::COBRADOR);
        $this->withPin();

        $this->postJson("/api/v1/admin/communities/{$c->id}/admin", ['user_id' => $stranger->id, 'pin' => '482913'])->assertNotFound();
        $this->assertFalse($stranger->fresh()->hasRole(Roles::ADMIN));
    }

    public function test_deactivated_community_blocks_login_and_sync_and_can_be_reactivated(): void
    {
        $c = Community::factory()->create();
        $admin = $this->member($c, Roles::ADMIN, ['email' => 'a@pozo.mx', 'password' => 'clave-segura-1']);
        $token = app(JwtService::class)->issue($admin, null, '127.0.0.1')->token;

        $this->postJson("/api/v1/admin/communities/{$c->id}/deactivate")->assertOk()->assertJsonPath('data.is_active', false);

        // La sesión abierta deja de servir y la sincronización se rechaza con mensaje simple.
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/sync/pull?device_id='.Str::uuid())->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', ['email' => 'a@pozo.mx', 'password' => 'clave-segura-1'])
            ->assertForbidden()->assertJsonPath('error.code', 'community_inactive')
            ->assertJsonPath('error.message', 'Tu comunidad está desactivada por ahora. Habla con el superadministrador para volver a activarla.');

        $this->useCommunity($c);
        $this->assertTrue(AuditLog::where('action', 'community.deactivated')->exists());

        $this->actingAs($this->super, 'api');
        $this->postJson("/api/v1/admin/communities/{$c->id}/activate")->assertOk()->assertJsonPath('data.is_active', true);
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => 'a@pozo.mx', 'password' => 'clave-segura-1'])->assertOk();
        $this->assertTrue(AuditLog::where('action', 'community.activated')->exists());
    }

    public function test_only_superadmin_uses_these_actions(): void
    {
        $c = Community::factory()->create();
        $admin = $this->member($c, Roles::ADMIN);
        $this->actingAs($admin, 'api');

        $this->postJson("/api/v1/admin/communities/{$c->id}/deactivate")->assertForbidden();
        $this->getJson("/api/v1/admin/communities/{$c->id}/users")->assertForbidden();
        $this->getJson('/api/v1/admin/security-pin')->assertForbidden();
    }
}
