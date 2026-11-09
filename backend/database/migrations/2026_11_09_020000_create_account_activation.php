<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fase 2: alta por correo y verificación.
     * - users: cuándo se invitó, cuándo activó su cuenta y su foto de perfil. Las cuentas que ya
     *   existían quedan activas (activated_at = su fecha de alta): no cambia nada para ellas.
     * - one_time_codes: PIN de un solo uso (activación de cuenta, entrada en un equipo nuevo) con
     *   hash, vencimiento y límite de intentos.
     * - trusted_devices: equipos donde el administrador ya confirmó su entrada con el PIN del correo.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->string('photo_path')->nullable();
        });
        DB::table('users')->whereNull('activated_at')->update(['activated_at' => DB::raw('coalesce(created_at, CURRENT_TIMESTAMP)')]);

        Schema::create('one_time_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 20);
            $table->string('code_hash');
            $table->uuid('device_id')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->index(['user_id', 'purpose']);
        });

        Schema::create('trusted_devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('device_id');
            $table->timestamp('trusted_at');
            $table->timestamp('last_used_at')->nullable();
            $table->unique(['user_id', 'device_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trusted_devices');
        Schema::dropIfExists('one_time_codes');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['invited_at', 'activated_at', 'photo_path']));
    }
};
