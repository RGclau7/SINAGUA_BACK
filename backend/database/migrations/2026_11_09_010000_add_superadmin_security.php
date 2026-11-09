<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fase 1 (superadministrador):
     * - Zona o colonia de la comunidad (la ubicación se muestra como municipio y zona).
     * - PIN de seguridad del superadministrador (hash) con su propio conteo de intentos.
     * Solo agrega columnas: no toca datos existentes.
     */
    public function up(): void
    {
        Schema::table('communities', function (Blueprint $table) {
            $table->string('zone', 120)->nullable()->after('locality');
            $table->timestamp('deactivated_at')->nullable()->after('is_active');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('security_pin')->nullable();
            $table->unsignedSmallInteger('pin_failed_attempts')->default(0);
            $table->timestamp('pin_locked_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('communities', fn (Blueprint $t) => $t->dropColumn(['zone', 'deactivated_at']));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['security_pin', 'pin_failed_attempts', 'pin_locked_until']));
    }
};
