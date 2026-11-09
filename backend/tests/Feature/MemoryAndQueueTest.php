<?php

namespace Tests\Feature;

use App\Audit\AuditChain;
use App\Jobs\VerificarBitacora;
use App\Models\AuditLog;
use App\Models\Community;
use App\Models\Roles;
use App\Models\User;
use App\Tenancy\CurrentCommunity;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Memoria acotada al recorrer la bitácora y verificación en segundo plano con la cola.
 */
class MemoryAndQueueTest extends TestCase
{
    use RefreshDatabase;

    private Community $community;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->community = Community::factory()->create();
        $admin = User::factory()->forCommunity($this->community)->create()->assignRole(Roles::ADMIN);
        $this->useCommunity($this->community);
        $this->actingAs($admin, 'api');
    }

    private function seedLogs(int $count): void
    {
        DB::transaction(function () use ($count) {
            for ($i = 0; $i < $count; $i++) {
                AuditLog::record('demo.memoria', ['entity' => 'demo', 'data' => ['n' => $i, 'texto' => str_repeat('agua ', 40)]]);
            }
        });
    }

    /** Pico de memoria (bytes) que agrega ejecutar $fn. */
    private function peakOf(callable $fn): int
    {
        gc_collect_cycles();
        memory_reset_peak_usage();
        $before = memory_get_usage();
        $fn();

        return memory_get_peak_usage() - $before;
    }

    public function test_verifying_the_audit_chain_uses_bounded_memory(): void
    {
        $this->seedLogs(3000);

        $streamed = $this->peakOf(fn () => $this->assertTrue(AuditChain::verify($this->community->id)['ok']));
        // Forma anterior: toda la bitácora en un arreglo.
        $loaded = $this->peakOf(fn () => AuditLog::where('community_id', $this->community->id)->orderBy('id')->get()->count());

        $this->assertLessThan($loaded / 2, $streamed, 'Recorrer por bloques debe usar mucho menos memoria que cargar todo.');
    }

    public function test_verify_async_queues_a_single_job_per_community(): void
    {
        Queue::fake();

        $this->postJson('/api/v1/audit-logs/verify-async')->assertStatus(202)->assertJsonPath('data.status', 'queued');
        $this->getJson('/api/v1/audit-logs/verify-async')->assertOk()->assertJsonPath('data.status', 'queued');

        Queue::assertPushed(VerificarBitacora::class, fn (VerificarBitacora $job) => $job->communityId === $this->community->id
            && $job->uniqueId() === $this->community->id);
    }

    public function test_duplicate_requests_do_not_duplicate_the_job(): void
    {
        config(['queue.default' => 'database']);

        $this->postJson('/api/v1/audit-logs/verify-async')->assertStatus(202);
        $this->postJson('/api/v1/audit-logs/verify-async')->assertStatus(202);
        $this->postJson('/api/v1/audit-logs/verify-async')->assertStatus(202);

        $this->assertSame(1, DB::table('jobs')->count(), 'ShouldBeUnique deja un solo trabajo en cola.');
    }

    public function test_worker_stores_the_result_and_releases_the_community(): void
    {
        $this->seedLogs(10);

        // Cola "sync": el trabajo corre al despacharlo, como lo haría el worker.
        $this->postJson('/api/v1/audit-logs/verify-async')->assertStatus(202);

        $this->getJson('/api/v1/audit-logs/verify-async')
            ->assertOk()
            ->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.ok', true);
        $this->assertSame($this->community->id, app(CurrentCommunity::class)->id());
    }

    public function test_job_declares_overlap_protection_and_limits(): void
    {
        $job = new VerificarBitacora($this->community->id);

        $this->assertInstanceOf(WithoutOverlapping::class, $job->middleware()[0]);
        $this->assertSame(3, $job->tries);
        $this->assertSame(300, $job->timeout);
    }
}
