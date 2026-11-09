<?php

namespace Tests\Feature;

use App\Models\Community;
use App\Models\Roles;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Todas las búsquedas del servidor ignoran acentos y mayúsculas ("gonzalez" = "González"),
 * pero la ñ sigue distinta ("pena" no encuentra "Peña").
 */
class SearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $community = Community::factory()->create();
        $admin = User::factory()->forCommunity($community)->create()->assignRole(Roles::ADMIN);
        User::factory()->forCommunity($community)->create(['name' => 'Laura González', 'email' => 'laura@x.mx'])->assignRole(Roles::COBRADOR);
        $this->useCommunity($community);
        $this->actingAs($admin, 'api');

        $this->postJson('/api/v1/padron/registro', [
            'vivienda' => ['address' => 'Calle Juárez 5'],
            'titular' => ['given_names' => 'Marta', 'paternal_surname' => 'González', 'maternal_surname' => 'Peña'],
        ])->assertCreated();
        $this->postJson('/api/v1/padron/registro', [
            'vivienda' => ['address' => 'Calle Hidalgo 9'],
            'titular' => ['given_names' => 'Beto', 'paternal_surname' => 'Ruiz'],
        ])->assertCreated();
    }

    public static function gonzalez(): array
    {
        return [['gonzalez'], ['GONZALEZ'], ['González'], ['juarez'], ['peña'], ['PEÑA']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('gonzalez')]
    public function test_padron_and_cobranza_find_without_accents_or_capitals(string $search): void
    {
        foreach (['familias', 'habitantes', 'viviendas'] as $entity) {
            if ($entity === 'habitantes' && $search === 'juarez') {
                continue; // el domicilio no es del habitante
            }
            $this->assertCount(1, $this->getJson('/api/v1/padron/'.$entity.'?search='.urlencode($search))->assertOk()->json('data'), "$entity: $search");
        }
        $this->assertCount(1, $this->getJson('/api/v1/cobranza/buscar?search='.urlencode($search))->assertOk()->json('data'));
    }

    public function test_users_find_without_accents_or_capitals_and_enie_stays_distinct(): void
    {
        foreach (['gonzalez', 'GONZALEZ', 'González'] as $search) {
            $this->assertCount(1, $this->getJson('/api/v1/users?search='.urlencode($search))->assertOk()->json('data'));
        }
        $this->assertCount(0, $this->getJson('/api/v1/padron/familias?search=pena')->assertOk()->json('data'));
    }
}
