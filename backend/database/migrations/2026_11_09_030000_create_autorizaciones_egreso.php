<?php

use Database\Seeders\RoleSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fase 3 (cobrador):
     * - communities.tope_egreso_cents: hasta cuánto puede gastar un cobrador sin autorización
     *   (null = sin tope).
     * - autorizaciones_egreso: código de un solo uso y corta vigencia que genera el
     *   administrador para un gasto del cobrador que pasa el tope.
     * - gastos.autorizacion_id: con qué autorización se registró.
     * - Permisos: el cobrador registra entradas y salidas; el administrador autoriza.
     */
    public function up(): void
    {
        Schema::table('communities', fn (Blueprint $t) => $t->unsignedBigInteger('tope_egreso_cents')->nullable());

        Schema::create('autorizaciones_egreso', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('community_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('cobrador_id')->constrained('users');
            $table->unsignedBigInteger('max_amount_cents');
            $table->string('motivo', 160)->nullable();
            $table->string('code_hash');
            $table->timestamp('expires_at');
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamp('used_at')->nullable();
            $table->uuid('gasto_id')->nullable();
            $table->unsignedBigInteger('used_amount_cents')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['community_id', 'created_at']);
        });

        Schema::table('gastos', fn (Blueprint $t) => $t->uuid('autorizacion_id')->nullable());

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE autorizaciones_egreso ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE autorizaciones_egreso FORCE ROW LEVEL SECURITY');
            DB::statement('CREATE POLICY autorizaciones_egreso_community ON autorizaciones_egreso USING (app_can_access(community_id)) WITH CHECK (app_can_access(community_id))');
        }

        // Los roles quedan con la matriz nueva de permisos (sin tocar a las personas).
        (new RoleSeeder)->run();
    }

    public function down(): void
    {
        Schema::table('gastos', fn (Blueprint $t) => $t->dropColumn('autorizacion_id'));
        Schema::dropIfExists('autorizaciones_egreso');
        Schema::table('communities', fn (Blueprint $t) => $t->dropColumn('tope_egreso_cents'));
    }
};
