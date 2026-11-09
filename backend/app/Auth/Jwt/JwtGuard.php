<?php

namespace App\Auth\Jwt;

use App\Models\AuthSession;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Guard "jwt" (ver config/auth.php, guard "api"): lee "Authorization: Bearer <jwt>",
 * valida el token y la sesión a la que apunta, y devuelve al usuario.
 *
 * Se usa como middleware con `auth:api`. Sin token, o con uno inválido, Laravel responde
 * 401 { "error": { "code": "unauthenticated" } }.
 */
final class JwtGuard
{
    public function __construct(private readonly JwtService $jwt) {}

    public function __invoke(Request $request): ?User
    {
        $token = $request->bearerToken();
        if ($token === null || $token === '') {
            return null;
        }

        $claims = $this->jwt->decode($token);
        if ($claims === null) {
            return null;
        }

        $session = AuthSession::with('user')->find($claims->jti);
        if ($session === null || $session->user_id !== $claims->sub || ! $session->isUsable() || $session->user === null) {
            return null;
        }

        $session->touchIfStale();
        // Para cerrar exactamente esta sesión en /auth/logout.
        $request->attributes->set(AuthSession::class, $session);

        return $session->user;
    }
}
