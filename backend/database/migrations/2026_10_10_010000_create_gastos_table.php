<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Gastos (M9): dinero que sale de un fondo. El fondo es una categoría de ingresos extras o,
     * si fondo_id es null, el de las cuotas del agua. El gasto es un movimiento: no se edita ni
     * se borra; si está mal, se anula con motivo. Lo único que se puede agregar después es el
     * comprobante (foto o PDF), que se guarda en S3/MinIO.
     */
    public function up(): void
    {
        Schema::create('gastos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('community_id')->constrained('communities');
            $table->foreignUuid('fondo_id')->nullable()->constrained('categorias_ingreso');
            $table->string('concepto', 160);
            $table->unsignedInteger('amount_cents');
            $table->date('spent_on');
            $table->string('note', 255)->nullable();
            $table->string('comprobante_path')->nullable();
            $table->string('comprobante_mime', 60)->nullable();
            $table->foreignUuid('recorded_by')->constrained('users');
            $table->uuid('device_id')->nullable();
            $table->voidable();
            $table->timestamps();
            $table->index(['community_id', 'fondo_id', 'spent_on']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE gastos ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE gastos FORCE ROW LEVEL SECURITY');
            DB::statement('CREATE POLICY gastos_community ON gastos USING (app_can_access(community_id)) WITH CHECK (app_can_access(community_id))');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP POLICY IF EXISTS gastos_community ON gastos');
        }
        Schema::dropIfExists('gastos');
    }
};
