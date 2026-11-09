<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Paquetes: cada comunidad define cuántas familias caben en una casa y cuánto se paga al mes.
     * Se guardan en la comunidad (JSON) y viajan con los ajustes; la vivienda guarda el id del suyo.
     * Las casas que ya existen pasan solas al paquete que corresponde por su número de familias.
     */
    public function up(): void
    {
        Schema::table('communities', fn (Blueprint $t) => $t->text('paquetes')->nullable());
        Schema::table('viviendas', fn (Blueprint $t) => $t->string('paquete_id', 40)->nullable());

        if (DB::getDriverName() === 'pgsql') {
            // Las tablas tienen seguridad por fila: esta migración toca todas las comunidades.
            DB::statement("SELECT set_config('app.system', 'on', true)");
        }

        $defaults = json_encode([
            ['id' => 'p1', 'name' => 'Paquete 1', 'familias' => 1, 'cuota_cents' => null, 'activo' => true],
            ['id' => 'p2', 'name' => 'Paquete 2', 'familias' => 2, 'cuota_cents' => null, 'activo' => true],
            ['id' => 'p3', 'name' => 'Paquete 3', 'familias' => 3, 'cuota_cents' => null, 'activo' => true],
        ]);
        DB::table('communities')->update(['paquetes' => $defaults]);

        $counts = DB::table('familias')->where('is_active', true)->select('vivienda_id', DB::raw('count(*) as n'))->groupBy('vivienda_id')->pluck('n', 'vivienda_id');
        foreach ($counts as $viviendaId => $n) {
            DB::table('viviendas')->where('id', $viviendaId)->update(['paquete_id' => 'p'.min(3, max(1, (int) $n))]);
        }
        DB::table('viviendas')->whereNull('paquete_id')->update(['paquete_id' => 'p1']);
    }

    public function down(): void
    {
        Schema::table('viviendas', fn (Blueprint $t) => $t->dropColumn('paquete_id'));
        Schema::table('communities', fn (Blueprint $t) => $t->dropColumn('paquetes'));
    }
};
