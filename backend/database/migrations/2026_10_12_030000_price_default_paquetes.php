<?php

use App\Comunidad\AjustesService;
use App\Comunidad\Paquetes;
use App\Models\Community;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Toda casa paga la cuota de su paquete: a los paquetes sin precio se les pone la cuota que la
     * comunidad ya cobraba, para que nada cambie hasta que ella los ajuste. Avisa a los equipos.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SELECT set_config('app.system', 'on', true)");
        }

        foreach (Community::all() as $community) {
            $cuota = AjustesService::cuotaActual($community);
            if ($cuota === null) {
                continue;
            }
            $list = array_map(fn ($p) => ['cuota_cents' => $p['cuota_cents'] ?? $cuota] + $p, Paquetes::of($community));
            DB::table('communities')->where('id', $community->id)->update(['paquetes' => json_encode($list, JSON_UNESCAPED_UNICODE)]);
            $community->refresh();
            DB::table('sync_changes')->insert([
                'community_id' => $community->id, 'entity' => AjustesService::ENTITY, 'entity_id' => $community->id,
                'op' => 'update', 'payload' => json_encode(AjustesService::present($community)), 'created_at' => now(),
            ]);
        }
    }

    public function down(): void {}
};
