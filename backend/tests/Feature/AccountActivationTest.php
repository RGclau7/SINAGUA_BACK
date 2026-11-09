<?php

namespace Tests\Feature;

use App\Mail\ActivationPinMail;
use App\Mail\LoginPinMail;
use App\Models\AuditLog;
use App\Models\Community;
use App\Models\OneTimeCode;
use App\Models\Roles;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Fase 2: alta por correo con PIN, activación (contraseña + foto) y verificación del administrador. */
class AccountActivationTest extends TestCase
{
    use RefreshDatabase;

    private User $super;

    private Community $community;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Mail::fake();
        Storage::fake('s3');
        $this->super = User::factory()->superAdmin()->create();
        $this->community = Community::factory()->create(['name' => 'El Salto']);
    }

    /** Invita y devuelve el PIN que llegó por correo. */
    private function invite(string $email = 'nuevo@salto.mx', string $role = 'cobrador'): string
    {
        $this->actingAs($this->super, 'api');
        $this->postJson("/api/v1/admin/communities/{$this->community->id}/invitations", ['name' => 'Rosa  Méndez', 'email' => $email, 'role' => $role])
            ->assertCreated()->assertJsonPath('data.status', 'pending');
        $this->app['auth']->forgetGuards();

        $pin = null;
        Mail::assertSent(ActivationPinMail::class, function (ActivationPinMail $mail) use ($email, &$pin) {
            if ($mail->hasTo($email)) {
                $pin = $mail->pin;
            }

            return $mail->hasTo($email);
        });

        return $pin;
    }

    /** PNG real de 1x1 (las pruebas no tienen GD). */
    private static function png(string $name = 'yo.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
    }

    private function activate(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->post('/api/v1/activation', [
            'email' => 'nuevo@salto.mx',
            'pin' => '000000',
            'password' => 'mi-clave-segura',
            'password_confirmation' => 'mi-clave-segura',
            'photo' => self::png(),
            ...$overrides,
        ], ['Accept' => 'application/json']);
    }

    public function test_invitation_creates_a_pending_account_with_hashed_pin_and_email(): void
    {
        $pin = $this->invite();

        $this->assertMatchesRegularExpression('/^\d{6}$/', $pin);
        $user = User::where('email', 'nuevo@salto.mx')->firstOrFail();
        $this->assertSame('Rosa Méndez', $user->name);
        $this->assertTrue($user->isPending());
        $this->assertTrue($user->hasRole(Roles::COBRADOR));
        $code = OneTimeCode::where('user_id', $user->id)->firstOrFail();
        $this->assertNotSame($pin, $code->code_hash);
        $this->assertTrue(Hash::check($pin, $code->code_hash));
        $this->assertEqualsWithDelta(now()->addHours(48)->timestamp, $code->expires_at->timestamp, 5);

        Mail::assertSent(ActivationPinMail::class, fn ($m) => str_contains($m->link, '/activar?correo=nuevo%40salto.mx'));

        // Pendiente: no entra con ninguna contraseña.
        $this->postJson('/api/v1/auth/login', ['email' => 'nuevo@salto.mx', 'password' => 'lo-que-sea-123'])
            ->assertForbidden()->assertJsonPath('error.code', 'account_pending');

        $this->useCommunity($this->community);
        $this->assertTrue(AuditLog::where('action', 'user.invited')->exists());
    }

    public function test_activation_with_pin_password_and_photo(): void
    {
        $pin = $this->invite();

        $this->activate(['pin' => $pin])->assertOk()->assertJsonPath('data.activated', true);

        $user = User::where('email', 'nuevo@salto.mx')->firstOrFail();
        $this->assertFalse($user->isPending());
        $this->assertNotNull($user->photo_path);
        Storage::disk('s3')->assertExists($user->photo_path);

        $this->postJson('/api/v1/auth/login', ['email' => 'nuevo@salto.mx', 'password' => 'mi-clave-segura'])->assertOk();

        // El PIN sirve una sola vez.
        $this->activate(['pin' => $pin])->assertStatus(409)->assertJsonPath('error.code', 'already_active');
    }

    public function test_wrong_pin_counts_attempts_and_then_stops_working(): void
    {
        $pin = $this->invite();
        $wrong = $pin === '111111' ? '222222' : '111111';

        $this->activate(['pin' => $wrong])->assertStatus(422)->assertJsonPath('error.code', 'pin_invalid')
            ->assertJsonPath('error.details.attempts_left', 4);
        for ($i = 0; $i < 3; $i++) {
            $this->activate(['pin' => $wrong])->assertStatus(422);
        }
        $this->activate(['pin' => $wrong])->assertStatus(423)->assertJsonPath('error.code', 'pin_locked');
        // Ni el correcto sirve ya: hay que pedir otro.
        $this->activate(['pin' => $pin])->assertStatus(423);
        $this->assertTrue(User::where('email', 'nuevo@salto.mx')->first()->isPending());
    }

    public function test_expired_pin_is_rejected_and_a_new_invitation_replaces_it(): void
    {
        $old = $this->invite();
        $this->travel(49)->hours();

        $this->activate(['pin' => $old])->assertStatus(410)->assertJsonPath('error.code', 'pin_expired')
            ->assertJsonPath('error.message', 'Ese PIN ya venció. Pida uno nuevo al superadministrador.');

        Mail::fake();
        $new = $this->invite();
        if ($new !== $old) {
            $this->activate(['pin' => $old])->assertStatus(422);
        }
        $this->activate(['pin' => $new])->assertOk();
    }

    public function test_photo_is_required_and_must_be_an_image(): void
    {
        $pin = $this->invite();

        $this->activate(['pin' => $pin, 'photo' => null])->assertUnprocessable()
            ->assertJsonPath('error.details.photo.0', 'Suba su foto o una foto de su identificación.');
        $this->activate(['pin' => $pin, 'photo' => UploadedFile::fake()->create('virus.exe', 10)])->assertUnprocessable()
            ->assertJsonPath('error.details.photo.0', 'La foto debe ser JPG o PNG.');
        $this->activate(['pin' => $pin, 'photo' => UploadedFile::fake()->createWithContent('grande.jpg', "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00".str_repeat('a', 6 * 1024 * 1024))])->assertUnprocessable()
            ->assertJsonPath('error.details.photo.0', 'La foto pesa demasiado: máximo 5 MB.');
        // Ningún intento con datos incompletos gastó el PIN.
        $this->activate(['pin' => $pin])->assertOk();
    }

    public function test_cannot_invite_an_email_that_is_already_active(): void
    {
        User::factory()->forCommunity($this->community)->create(['email' => 'ya@salto.mx']);
        $this->actingAs($this->super, 'api');

        $this->postJson("/api/v1/admin/communities/{$this->community->id}/invitations", ['name' => 'Ya Existe', 'email' => 'ya@salto.mx', 'role' => 'cobrador'])
            ->assertUnprocessable();
        $this->postJson("/api/v1/admin/communities/{$this->community->id}/invitations", ['name' => 'X', 'email' => 'x', 'role' => 'jefe'])
            ->assertUnprocessable();
    }

    public function test_community_created_without_password_invites_its_admin(): void
    {
        $this->actingAs($this->super, 'api');
        $this->postJson('/api/v1/admin/communities', [
            'name' => 'Nueva', 'state' => 'Guanajuato', 'municipality' => 'Dolores', 'locality' => 'Centro', 'zone' => 'Barrio Bajo',
            'contact_phone' => '4151234567',
            'admin' => ['given_names' => 'Ana', 'paternal_surname' => 'Ruiz', 'phone' => '4151112233', 'address' => 'Calle 1', 'email' => 'ana@nueva.mx'],
        ])->assertCreated()->assertJsonPath('data.zone', 'Barrio Bajo')->assertJsonPath('data.admin.status', 'pending');

        Mail::assertSent(ActivationPinMail::class, fn ($m) => $m->hasTo('ana@nueva.mx') && $m->roleLabel === 'administrador');
    }

    public function test_web_page_activates_the_account(): void
    {
        $pin = $this->invite();

        $this->get('/activar?correo=nuevo%40salto.mx')->assertOk()->assertSee('Activar mi cuenta')->assertSee('nuevo@salto.mx');

        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)
            ->post('/activar', ['email' => 'nuevo@salto.mx', 'pin' => '99999x', 'password' => 'mi-clave-segura', 'password_confirmation' => 'mi-clave-segura',
                'photo' => self::png()])
            ->assertOk()->assertSee('El PIN no es correcto');

        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)
            ->post('/activar', ['email' => 'nuevo@salto.mx', 'pin' => $pin, 'password' => 'mi-clave-segura', 'password_confirmation' => 'mi-clave-segura',
                'photo' => self::png()])
            ->assertOk()->assertSee('Tu cuenta ya está activa');
    }

    public function test_admin_confirms_a_new_device_with_an_emailed_code_once(): void
    {
        config(['sinagua.two_factor.enabled' => true]);
        $admin = User::factory()->forCommunity($this->community)->create(['email' => 'admin@salto.mx', 'password' => 'clave-admin-1']);
        $admin->assignRole(Roles::ADMIN);
        $device = (string) Str::uuid();

        $this->postJson('/api/v1/auth/login', ['email' => 'admin@salto.mx', 'password' => 'clave-admin-1', 'device_id' => $device])
            ->assertForbidden()->assertJsonPath('error.code', 'two_factor_required')
            ->assertJsonPath('error.details.email_hint', 'ad•••@salto.mx');
        $code = null;
        Mail::assertSent(LoginPinMail::class, function (LoginPinMail $m) use (&$code) {
            $code = $m->pin;

            return $m->hasTo('admin@salto.mx');
        });

        $wrong = $code === '111111' ? '222222' : '111111';
        $this->postJson('/api/v1/auth/login', ['email' => 'admin@salto.mx', 'password' => 'clave-admin-1', 'device_id' => $device, 'two_factor_code' => $wrong])
            ->assertStatus(422)->assertJsonPath('error.code', 'pin_invalid');

        // El código es para ese equipo.
        $this->postJson('/api/v1/auth/login', ['email' => 'admin@salto.mx', 'password' => 'clave-admin-1', 'device_id' => (string) Str::uuid(), 'two_factor_code' => $code])
            ->assertStatus(422);

        $this->postJson('/api/v1/auth/login', ['email' => 'admin@salto.mx', 'password' => 'clave-admin-1', 'device_id' => $device, 'two_factor_code' => $code])
            ->assertOk()->assertJsonStructure(['data' => ['token']]);

        // Ya es de confianza: no vuelve a pedir código.
        Mail::fake();
        $this->postJson('/api/v1/auth/login', ['email' => 'admin@salto.mx', 'password' => 'clave-admin-1', 'device_id' => $device])->assertOk();
        Mail::assertNothingSent();

        // Los cobradores no lo necesitan.
        $cobrador = User::factory()->forCommunity($this->community)->create(['email' => 'cobra@salto.mx', 'password' => 'clave-cobra-1']);
        $cobrador->assignRole(Roles::COBRADOR);
        $this->postJson('/api/v1/auth/login', ['email' => 'cobra@salto.mx', 'password' => 'clave-cobra-1', 'device_id' => (string) Str::uuid()])->assertOk();
    }

    public function test_login_code_expires(): void
    {
        config(['sinagua.two_factor.enabled' => true]);
        $admin = User::factory()->forCommunity($this->community)->create(['email' => 'admin@salto.mx', 'password' => 'clave-admin-1']);
        $admin->assignRole(Roles::ADMIN);
        $device = (string) Str::uuid();

        $this->postJson('/api/v1/auth/login', ['email' => 'admin@salto.mx', 'password' => 'clave-admin-1', 'device_id' => $device])->assertForbidden();
        $code = null;
        Mail::assertSent(LoginPinMail::class, function (LoginPinMail $m) use (&$code) {
            $code = $m->pin;

            return true;
        });
        $this->travel(16)->minutes();

        $this->postJson('/api/v1/auth/login', ['email' => 'admin@salto.mx', 'password' => 'clave-admin-1', 'device_id' => $device, 'two_factor_code' => $code])
            ->assertStatus(410)->assertJsonPath('error.code', 'pin_expired');
    }
}
