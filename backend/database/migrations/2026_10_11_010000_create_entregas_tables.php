<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Entregas (M10): el encargado recibe de un cobrador el dinero de sus cobros (los recibos de
     * su corte), lo cuenta y lo registra. Si lo contado no coincide, queda la diferencia con su
     * explicación. La entrega es un movimiento: si está mal, se anula y esos recibos vuelven a
     * quedar por entregar.
     */
    public const TABLES = ['entregas', 'entrega_pagos'];

    public function up(): void
    {
        Schema::create('entregas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('community_id')->constrained('communities');
            $table->foreignUuid('cobrador_id')->constrained('users');   // quien entrega
            $table->foreignUuid('received_by')->constrained('users');   // quien recibe
            $table->unsignedInteger('expected_cents');
            $table->unsignedInteger('received_cents');
            $table->string('note', 255)->nullable();
            $table->timestamp('received_at');
            $table->uuid('device_id')->nullable();
            $table->voidable();
            $table->timestamps();
            $table->index(['community_id', 'cobrador_id', 'received_at']);
        });

        Schema::create('entrega_pagos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('community_id')->constrained('communities');
            $table->foreignUuid('entrega_id')->constrained('entregas');
            $table->foreignUuid('pago_id')->constrained('pagos');
            $table->timestamps();
            $table->unique(['entrega_id', 'pago_id']);
            $table->index(['community_id', 'pago_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            foreach (self::TABLES as $table) {
                DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
                DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
                DB::statement("CREATE POLICY {$table}_community ON {$table} USING (app_can_access(community_id)) WITH CHECK (app_can_access(community_id))");
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (self::TABLES as $table) {
                DB::statement("DROP POLICY IF EXISTS {$table}_community ON {$table}");
            }
        }
        Schema::dropIfExists('entrega_pagos');
        Schema::dropIfExists('entregas');
    }
};
