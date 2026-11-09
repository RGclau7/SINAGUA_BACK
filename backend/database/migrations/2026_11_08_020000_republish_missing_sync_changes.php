<?php

use App\Comunidad\AjustesService;
use App\Cuotas\CuotasService;
use App\Models\Community;
use App\Models\Cuota;
use App\Models\User;
use App\Users\UserChangePublisher;
use App\Users\UserService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Si se borró el historial de sincronización (por ejemplo, al limpiar los datos de prueba),
     * un equipo nuevo no recibe lo que quedó: "Cómo se cobra", los usuarios ni las cuotas. Entonces
     * el equipo ve los paquetes de fábrica sin precio y, al guardar, el servidor rechaza el cambio.
     * Se vuelve a publicar lo que exista y no tenga ningún cambio en el historial.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SELECT set_config('app.system', 'on', true)");
        }

        $has = fn (string $entity) => DB::table('sync_changes')->where('entity', $entity)->distinct()->pluck('entity_id')->map(fn ($id) => (string) $id)->flip();
        $insert = fn (?string $community, string $entity, string $id, array $payload) => DB::table('sync_changes')->insert([
            'community_id' => $community, 'entity' => $entity, 'entity_id' => $id,
            'op' => 'update', 'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE), 'created_at' => now(),
        ]);

        $users = $has(UserChangePublisher::ENTITY);
        foreach (User::query()->whereNotNull('community_id')->get() as $user) {
            if (! isset($users[(string) $user->id])) {
                $insert($user->community_id, UserChangePublisher::ENTITY, $user->id, UserService::present($user));
            }
        }

        $cuotas = $has(CuotasService::CUOTAS);
        foreach (Cuota::withoutGlobalScopes()->get() as $cuota) {
            if (! isset($cuotas[(string) $cuota->id])) {
                $insert($cuota->community_id, CuotasService::CUOTAS, $cuota->id, CuotasService::present($cuota));
            }
        }

        // Al final, para que el equipo reciba los ajustes ya con los cobradores y las cuotas.
        $ajustes = $has(AjustesService::ENTITY);
        foreach (Community::all() as $community) {
            if (! isset($ajustes[(string) $community->id])) {
                $insert($community->id, AjustesService::ENTITY, $community->id, AjustesService::present($community));
            }
        }
    }

    public function down(): void {}
};
