<?php

namespace Tests\Feature;

use App\Gastos\Fondos;
use App\Models\Community;
use App\Models\Device;
use App\Models\Gasto;
use App\Models\Roles;
use App\Models\SyncChange;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Gastos (M9): cada gasto sale de un fondo (cuotas del agua o una categoría de ingresos
 * extras) y baja su saldo; el comprobante se adjunta aparte.
 */
class GastosTest extends TestCase
{
    use RefreshDatabase;

    private Community $community;

    private User $admin;

    private string $deviceId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Storage::fake('s3');

        $this->community = Community::factory()->create();
        $this->admin = User::factory()->forCommunity($this->community)->create()->assignRole(Roles::ADMIN);
        $this->useCommunity($this->community);
        $this->deviceId = (string) Str::uuid();
        Device::create(['id' => $this->deviceId, 'name' => 'Caja', 'receipt_prefix' => 'E01']);
        $this->actingAs($this->admin, 'api');
    }

    private function banos(int $cents): string
    {
        $id = $this->postJson('/api/v1/ingresos/categorias', ['name' => 'Baños comunitarios'])->json('data.id');
        $this->postJson('/api/v1/ingresos', ['categoria_id' => $id, 'amount_cents' => $cents, 'received_on' => now()->toDateString()])->assertCreated();

        return $id;
    }

    public function test_expense_lowers_its_fund_and_voiding_returns_it(): void
    {
        $banos = $this->banos(50000);

        $id = $this->postJson('/api/v1/gastos', ['fondo_id' => $banos, 'concepto' => 'Papel y jabón', 'amount_cents' => 12000, 'spent_on' => now()->toDateString()])
            ->assertCreated()->assertJsonPath('notices', [])->json('data.id');

        $fondos = collect($this->getJson('/api/v1/fondos')->assertOk()->json('data'))->pluck('saldo_cents', 'name');
        $this->assertSame(38000, $fondos['Baños comunitarios']);
        $this->assertSame(0, $fondos[Fondos::CUOTAS]);

        // Del fondo de cuotas sin dinero: se registra pero avisa que quedó en negativo.
        $this->postJson('/api/v1/gastos', ['concepto' => 'Recibo de luz', 'amount_cents' => 80000, 'spent_on' => now()->toDateString()])
            ->assertCreated()->assertJsonPath('notices.0.code', 'fondo_negativo');
        $this->assertSame(-80000, Fondos::cuotasCents());

        $this->postJson("/api/v1/movements/gastos/{$id}/void", ['reason' => 'Era de otra compra'])->assertOk();
        $this->assertSame(50000, Fondos::saldoCents($banos));
        $this->assertSame('void', SyncChange::where('entity', 'gastos')->where('entity_id', $id)->latest('id')->value('op'));
        $this->assertCount(1, $this->getJson('/api/v1/gastos?fondo=cuotas')->json('data'));
    }

    public function test_comprobante_is_attached_later_and_downloaded(): void
    {
        $id = $this->postJson('/api/v1/gastos', ['concepto' => 'Cloro', 'amount_cents' => 5000, 'spent_on' => now()->toDateString()])->json('data.id');

        $this->post("/api/v1/gastos/{$id}/comprobante", ['archivo' => UploadedFile::fake()->createWithContent('nota.txt', 'solo texto')], ['Accept' => 'application/json'])
            ->assertStatus(422);
        $this->post("/api/v1/gastos/{$id}/comprobante", ['archivo' => UploadedFile::fake()->createWithContent('ticket.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n")], ['Accept' => 'application/json', 'X-Device-Id' => $this->deviceId])
            ->assertOk()->assertJsonPath('data.has_comprobante', true);

        $gasto = Gasto::findOrFail($id);
        Storage::disk('s3')->assertExists($gasto->comprobante_path);
        $this->assertStringStartsWith($this->community->id.'/gastos/', $gasto->comprobante_path);
        $this->get("/api/v1/gastos/{$id}/comprobante")->assertOk();
        $this->assertTrue(SyncChange::where('entity', 'gastos')->where('entity_id', $id)->latest('id')->first()->payload['has_comprobante']);
    }

    public function test_desktop_registers_and_voids_offline(): void
    {
        $id = (string) Str::uuid();
        $push = fn (array $m) => $this->postJson('/api/v1/sync/push', ['device_id' => $this->deviceId, 'mutations' => [['mutation_id' => (string) Str::uuid(), 'entity' => 'gastos', 'entity_id' => $id, ...$m]]]);

        $push(['op' => 'create', 'payload' => ['fondo_id' => null, 'concepto' => 'Reparación de bomba', 'amount_cents' => 150000, 'spent_on' => now()->toDateString()]])
            ->assertJsonPath('data.results.0.status', 'accepted')
            ->assertJsonPath('data.results.0.notices.0.code', 'fondo_negativo');
        $push(['op' => 'update', 'payload' => ['amount_cents' => 1]])->assertJsonPath('data.results.0.code', 'append_only');
        $push(['op' => 'void', 'payload' => ['void_reason' => 'Se canceló la reparación']])->assertJsonPath('data.results.0.status', 'accepted');

        $this->assertSame($this->deviceId, Gasto::findOrFail($id)->device_id);
        $this->assertSame(0, Fondos::cuotasCents());
    }

    public function test_cobrador_registers_expenses_without_a_limit(): void
    {
        // Fase 3: el cobrador registra salidas; sin tope no necesita autorización.
        $this->actingAs(User::factory()->forCommunity($this->community)->create()->assignRole(Roles::COBRADOR), 'api');

        $this->getJson('/api/v1/fondos')->assertOk();
        $this->postJson('/api/v1/gastos', ['concepto' => 'Cloro', 'amount_cents' => 100, 'spent_on' => now()->toDateString()])->assertCreated();
        $this->assertStringNotContainsString('gastos', (string) $this->getJson("/api/v1/sync/pull?device_id={$this->deviceId}")->json('data.scope'));
    }
}
