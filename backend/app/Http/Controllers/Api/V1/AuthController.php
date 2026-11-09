<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\Jwt\JwtService;
use App\Auth\LoginService;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\AuthSession;
use App\Models\User;
use App\Support\ApiResponse;
use App\Tenancy\CurrentCommunity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(
        private readonly LoginService $login,
        private readonly CurrentCommunity $context,
        private readonly JwtService $jwt,
    ) {}

    /**
     * POST /api/v1/auth/login {email, password, device_id?}
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'max:190'],
            'password' => ['required', 'string', 'max:255'],
            'device_id' => ['nullable', 'uuid'],
            'two_factor_code' => ['nullable', 'string', 'max:20'],
        ]);

        [$user, $issued] = $this->login->attempt($data['email'], $data['password'], $data['device_id'] ?? null, $request->ip(), $request->userAgent(), $data['two_factor_code'] ?? null);

        return ApiResponse::ok($this->tokenResponse($user, $issued->token, $issued->session));
    }

    /**
     * POST /api/v1/auth/refresh — cambia el JWT vigente por uno nuevo (rotación): la sesión
     * anterior queda revocada, así un token robado deja de servir en cuanto el dueño renueva.
     */
    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();
        $current = $request->attributes->get(AuthSession::class);

        $issued = $this->jwt->issue($user, $current?->device_id, $request->ip(), $request->userAgent());
        $current?->revoke();

        return ApiResponse::ok($this->tokenResponse($user, $issued->token, $issued->session));
    }

    /**
     * GET /api/v1/auth/me — datos y rol vigentes del usuario.
     */
    public function me(Request $request): JsonResponse
    {
        return ApiResponse::ok($this->present($request->user()));
    }

    /**
     * POST /api/v1/auth/logout — revoca la sesión de este JWT (deja de servir al instante).
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $request->attributes->get(AuthSession::class)?->revoke();

        $this->context->runAsSystem(fn () => AuditLog::record('auth.logout', [
            'community_id' => $user->community_id,
            'user_id' => $user->id,
            'entity' => 'users',
            'entity_id' => $user->id,
        ]));

        return ApiResponse::ok(['logged_out' => true]);
    }

    private function tokenResponse(User $user, string $token, AuthSession $session): array
    {
        return [
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_at' => $session->expires_at->toIso8601String(),
            'idle_timeout_minutes' => config('sinagua.auth.idle_minutes'),
            'user' => $this->present($user),
        ];
    }

    private function present(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->getRoleNames()->values()->all(),
            'panel' => $user->panel(),
            'community' => $user->community === null ? null : [
                'id' => $user->community->id,
                'name' => $user->community->name,
            ],
        ];
    }
}
