<?php

namespace Tests\Feature;

use App\Auth\Jwt\JwtService;
use App\Models\AuthSession;
use App\Models\Community;
use App\Models\Roles;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Middleware JWT (guard "api") y hashing de contraseñas.
 */
class JwtAuthTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'clave-correcta-123';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $community = Community::factory()->create();
        $this->user = User::factory()->forCommunity($community)->create([
            'email' => 'admin@pozo.mx',
            'password' => self::PASSWORD,
        ]);
        $this->user->assignRole(Roles::ADMIN);
    }

    private function token(): string
    {
        return $this->postJson('/api/v1/auth/login', ['email' => 'admin@pozo.mx', 'password' => self::PASSWORD])
            ->assertOk()->json('data.token');
    }

    private function me(string $token)
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/auth/me');
    }

    /** @return array{0: array, 1: array, 2: string} */
    private function parts(string $jwt): array
    {
        [$h, $p, $s] = explode('.', $jwt);

        return [json_decode(JWT::urlsafeB64Decode($h), true), json_decode(JWT::urlsafeB64Decode($p), true), $s];
    }

    private function forge(array $header, array $payload, string $signature = ''): string
    {
        return JWT::urlsafeB64Encode(json_encode($header)).'.'.JWT::urlsafeB64Encode(json_encode($payload)).'.'.$signature;
    }

    public function test_login_returns_a_signed_hs256_jwt_with_standard_claims(): void
    {
        $token = $this->token();
        [$header, $claims] = $this->parts($token);

        $this->assertSame(['typ' => 'JWT', 'alg' => 'HS256'], $header);
        $this->assertSame($this->user->id, $claims['sub']);
        $this->assertSame('sinagua-api', $claims['aud']);
        $this->assertSame(config('sinagua.jwt.issuer'), $claims['iss']);
        $this->assertSame($this->user->community_id, $claims['cid']);
        $this->assertSame([Roles::ADMIN], $claims['roles']);
        $this->assertSame(12 * 3600, $claims['exp'] - $claims['iat']);
        $this->assertTrue(AuthSession::whereKey($claims['jti'])->exists(), 'El jti apunta a una sesión del servidor.');

        $this->me($token)->assertOk()->assertJsonPath('data.email', 'admin@pozo.mx');
    }

    public function test_requests_without_token_are_rejected_with_401_json(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
        $this->me('esto-no-es-un-jwt')->assertUnauthorized();
    }

    public function test_tampered_payload_is_rejected(): void
    {
        $token = $this->token();
        [$header, $claims, $signature] = $this->parts($token);

        // Otro usuario intenta hacerse pasar por superadministrador cambiando el payload.
        $claims['roles'] = [Roles::SUPERADMIN];
        $this->me($this->forge($header, $claims, $signature))->assertUnauthorized();
    }

    public function test_alg_none_and_other_algorithms_are_rejected(): void
    {
        [$header, $claims] = $this->parts($this->token());

        $this->me($this->forge(['typ' => 'JWT', 'alg' => 'none'], $claims))->assertUnauthorized();
        $this->me(JWT::encode($claims, str_repeat('k', 64), 'HS512'))->assertUnauthorized();
    }

    public function test_token_signed_with_another_key_is_rejected(): void
    {
        [, $claims] = $this->parts($this->token());

        $this->me(JWT::encode($claims, str_repeat('x', 32), 'HS256'))->assertUnauthorized();
    }

    public function test_wrong_audience_or_issuer_is_rejected(): void
    {
        [, $claims] = $this->parts($this->token());
        $key = (new \ReflectionProperty(JwtService::class, 'key'))->getValue(app(JwtService::class));

        $this->me(JWT::encode([...$claims, 'aud' => 'otra-api'], $key, 'HS256'))->assertUnauthorized();
        $this->me(JWT::encode([...$claims, 'iss' => 'https://malicioso.example'], $key, 'HS256'))->assertUnauthorized();
        // Firma válida pero sin sesión en el servidor (jti inventado).
        $this->me(JWT::encode([...$claims, 'jti' => '00000000-0000-4000-8000-000000000000'], $key, 'HS256'))->assertUnauthorized();
    }

    public function test_expired_token_is_rejected(): void
    {
        $token = $this->token();

        $this->travel(12)->hours();
        $this->travel(1)->minutes();
        $this->me($token)->assertUnauthorized();
    }

    public function test_refresh_rotates_the_token_and_revokes_the_old_one(): void
    {
        $old = $this->token();

        $this->app['auth']->forgetGuards();
        $new = $this->withHeader('Authorization', "Bearer {$old}")->postJson('/api/v1/auth/refresh')
            ->assertOk()->assertJsonPath('data.token_type', 'Bearer')->json('data.token');

        $this->assertNotSame($old, $new);
        $this->me($old)->assertUnauthorized();
        $this->me($new)->assertOk();
    }

    public function test_session_use_is_recorded_at_most_once_per_minute(): void
    {
        $token = $this->token();
        $jti = $this->parts($token)[1]['jti'];
        $loginAt = AuthSession::find($jti)->last_used_at;

        $this->travel(30)->seconds();
        $this->me($token)->assertOk();
        $this->assertEquals($loginAt, AuthSession::find($jti)->last_used_at, 'Menos de 60 s: no se escribe.');

        $this->travel(45)->seconds();
        $this->me($token)->assertOk();
        $this->assertTrue(AuthSession::find($jti)->last_used_at->gt($loginAt));
    }

    public function test_passwords_are_stored_with_bcrypt_never_in_plain_text(): void
    {
        $stored = $this->user->getRawOriginal('password');

        $this->assertNotSame(self::PASSWORD, $stored);
        $this->assertSame('bcrypt', Hash::info($stored)['algoName']);
        $this->assertMatchesRegularExpression('/^\$2y\$\d{2}\$/', $stored);
        $this->assertTrue(Hash::check(self::PASSWORD, $stored));
    }

    public function test_weaker_hashes_are_upgraded_on_next_login(): void
    {
        // Contraseña guardada con un costo menor (por ejemplo, creada en el escritorio).
        $this->user->forceFill(['password' => password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4])])->saveQuietly();
        Hash::driver('bcrypt')->setRounds(10);

        $this->token();

        $this->assertStringStartsWith('$2y$10$', $this->user->fresh()->getRawOriginal('password'));
    }

    public function test_api_responses_carry_security_headers(): void
    {
        $this->getJson('/api/v1/health')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Cache-Control', 'no-store, private');
    }
}
