<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sesiones de la API autenticadas con JWT.
     *
     * El JWT viaja firmado (HS256) y se valida sin ir a la base; su claim "jti" apunta a una
     * fila de esta tabla para poder cerrarlo antes de que expire: cerrar sesión, usuario
     * desactivado, cambio de contraseña o 30 minutos sin usarse (requisito del SRS).
     * Reemplaza a personal_access_tokens de Sanctum.
     */
    public function up(): void
    {
        Schema::create('auth_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();                 // = claim jti del JWT
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('device_id')->nullable();
            $table->string('name', 120);                   // "device:{uuid}" o "api"
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);
        });

        // Los tokens de Sanctum dejan de servir: el escritorio vuelve a iniciar sesión solo.
        Schema::dropIfExists('personal_access_tokens');
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_sessions');

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->uuidMorphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }
};
