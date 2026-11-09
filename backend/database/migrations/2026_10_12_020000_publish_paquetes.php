<?php

use App\Comunidad\AjustesService;
use App\Models\Community;
use App\Models\Vivienda;
use App\Padron\PadronService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Pasa las casas que ya existen al paquete que corresponde por su número de familias y avisa a
     * los equipos (ajustes y viviendas), para que calculen la cuota igual que el servidor.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SELECT set_config('app.system', 'on', true)");
        }

        $counts = DB::table('familias')->where('is_active', true)->select('vivienda_id', DB::raw('count(*) as n'))->groupBy('vivienda_id')->pluck('n', 'vivienda_id');
        foreach (DB::table('viviendas')->whereNull('paquete_id')->pluck('id') as $id) {
            DB::table('viviendas')->where('id', $id)->update(['paquete_id' => 'p'.min(3, max(1, (int) ($counts[$id] ?? 1)))]);
        }

        foreach (Community::all() as $community) {
            DB::table('sync_changes')->insert([
                'community_id' => $community->id, 'entity' => AjustesService::ENTITY, 'entity_id' => $community->id,
                'op' => 'update', 'payload' => json_encode(AjustesService::present($community)), 'created_at' => now(),
            ]);
        }
        foreach (Vivienda::withoutGlobalScopes()->get() as $vivienda) {
            DB::table('sync_changes')->insert([
                'community_id' => $vivienda->community_id, 'entity' => PadronService::VIVIENDAS, 'entity_id' => $vivienda->id,
                'op' => 'update', 'payload' => json_encode(PadronService::present($vivienda)), 'created_at' => now(),
            ]);
        }
    }

    public function down(): void {}
};
