<?php

namespace App\Auth\Jwt;

use App\Models\AuthSession;
use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Emite y valida los JWT de la API (RFC 7519) firmados con HMAC-SHA256 (HS256).
 *
 * - El algoritmo está fijo del lado del servidor: un token con "alg": "none" u otro
 *   algoritmo se rechaza aunque diga ser válido (ataque de confusión de algoritmo).
 * - La llave tiene 256 bits. Sale de JWT_SECRET o, si no se configuró, se deriva de
 *   APP_KEY con HMAC, así nunca se usa la misma llave para dos cosas.
 * - Se validan firma, exp, nbf, iat, iss y aud; el jti debe existir en auth_sessions.
 */
final class JwtService
{
    public const ALGORITHM = 'HS256';

    private readonly string $key;

    public function __construct()
    {
        $this->key = self::resolveKey();
        JWT::$leeway = (int) config('sinagua.jwt.leeway_seconds');
    }

    public function issue(User $user, ?string $deviceId = null, ?string $ip = null, ?string $userAgent = null): IssuedToken
    {
        $now = now();
        $session = AuthSession::create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'device_id' => $deviceId,
            'name' => $deviceId !== null ? "device:{$deviceId}" : 'api',
            'ip' => $ip,
            'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
            'last_used_at' => $now,
            'expires_at' => $now->copy()->addHours(config('sinagua.auth.token_lifetime_hours')),
        ]);

        $claims = [
            'iss' => config('sinagua.jwt.issuer'),
            'aud' => config('sinagua.jwt.audience'),
            'sub' => $user->id,
            'jti' => $session->id,
            'iat' => $now->getTimestamp(),
            'nbf' => $now->getTimestamp(),
            'exp' => $session->expires_at->getTimestamp(),
            // Informativos para el cliente: la autorización siempre se revisa contra la base.
            'cid' => $user->community_id,
            'did' => $deviceId,
            'roles' => $user->getRoleNames()->values()->all(),
        ];

        return new IssuedToken(JWT::encode($claims, $this->key, self::ALGORITHM, null, ['typ' => 'JWT']), $session);
    }

    /**
     * Devuelve los claims si el token es auténtico y vigente; null en cualquier otro caso
     * (no se distingue el motivo hacia el cliente para no dar pistas a un atacante).
     */
    public function decode(string $jwt): ?object
    {
        try {
            $claims = JWT::decode($jwt, new Key($this->key, self::ALGORITHM));
        } catch (Throwable) {
            return null;
        }

        $valid = ($claims->iss ?? null) === config('sinagua.jwt.issuer')
            && ($claims->aud ?? null) === config('sinagua.jwt.audience')
            && is_string($claims->sub ?? null)
            && is_string($claims->jti ?? null)
            && Str::isUuid($claims->jti);

        return $valid ? $claims : null;
    }

    private static function resolveKey(): string
    {
        $secret = (string) config('sinagua.jwt.secret');
        if ($secret !== '') {
            if (str_starts_with($secret, 'base64:')) {
                $secret = base64_decode(substr($secret, 7), true) ?: '';
            }
            if (strlen($secret) < 32) {
                throw new RuntimeException('JWT_SECRET debe tener al menos 32 bytes (256 bits).');
            }

            return $secret;
        }

        $appKey = (string) config('app.key');
        if ($appKey === '') {
            throw new RuntimeException('Falta APP_KEY (o JWT_SECRET) para firmar los JWT.');
        }
        if (str_starts_with($appKey, 'base64:')) {
            $appKey = base64_decode(substr($appKey, 7), true) ?: $appKey;
        }

        // Llave propia para JWT derivada de APP_KEY (32 bytes).
        return hash_hmac('sha256', 'sinagua-jwt-hs256', $appKey, true);
    }
}
