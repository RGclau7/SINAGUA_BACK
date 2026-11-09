<?php

namespace App\Accounts;

use App\Exceptions\ApiException;
use App\Models\AuditLog;
use App\Models\OneTimeCode;
use App\Models\User;
use App\Tenancy\CurrentCommunity;
use App\Users\UserChangePublisher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Primer acceso: con el PIN del correo la persona define su contraseña y sube su foto (o una
 * foto de su identificación). Hasta entonces la cuenta queda "pendiente" y no puede entrar.
 * Lo usan la app de escritorio (API) y la página web /activar.
 */
class AccountActivation
{
    public const PHOTO_TYPES = ['jpg', 'jpeg', 'png'];

    public const PHOTO_MAX_KB = 5120;

    public function __construct(
        private readonly OneTimeCodes $codes,
        private readonly CurrentCommunity $context,
        private readonly UserChangePublisher $publisher,
    ) {}

    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:190'],
            'pin' => ['required', 'string', 'max:20'],
            'password' => ['required', 'confirmed', Password::min(10)],
            'photo' => ['required', 'file', 'mimes:'.implode(',', self::PHOTO_TYPES), 'max:'.self::PHOTO_MAX_KB],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'email.required' => 'Escriba su correo.',
            'email.email' => 'El correo no es válido.',
            'pin.required' => 'Escriba el PIN de 6 números que le llegó al correo.',
            'password.required' => 'Escriba su contraseña nueva.',
            'password.confirmed' => 'Las dos contraseñas no son iguales.',
            'password.min' => 'La contraseña debe tener al menos 10 caracteres.',
            'photo.required' => 'Suba su foto o una foto de su identificación.',
            'photo.file' => 'Suba su foto o una foto de su identificación.',
            'photo.mimes' => 'La foto debe ser JPG o PNG.',
            'photo.max' => 'La foto pesa demasiado: máximo 5 MB.',
        ];
    }

    /** @throws ApiException */
    public function activate(array $input): User
    {
        $data = Validator::make($input, self::rules(), self::messages())->validate();
        $email = mb_strtolower(trim($data['email']));

        $user = $this->context->runAsSystem(fn () => User::where('email', $email)->first());
        if ($user === null) {
            throw new ApiException('pin_invalid', 'El PIN no es correcto.', 422, ['attempts_left' => 0]);
        }
        if ($user->activated_at !== null) {
            throw new ApiException('already_active', 'Esta cuenta ya está activa. Entre con su correo y contraseña.', 409);
        }

        $code = $this->codes->check($user, OneTimeCode::ACTIVATION, $data['pin']);

        /** @var UploadedFile $photo */
        $photo = $data['photo'];
        $path = 'perfiles/'.$user->id.'/'.Str::uuid().'.'.strtolower($photo->getClientOriginalExtension() ?: $photo->extension());
        if (! Storage::disk(config('sinagua.fotos_disk'))->put($path, file_get_contents($photo->getRealPath()))) {
            throw new ApiException('storage_error', 'No se pudo guardar la foto. Intente de nuevo en unos minutos.', 503);
        }

        $this->codes->consume($code);

        return $this->context->runAsSystem(function () use ($user, $data, $path) {
            $user->password = $data['password'];
            $user->photo_path = $path;
            $user->activated_at = now();
            $user->failed_login_attempts = 0;
            $user->locked_until = null;
            $user->save();

            AuditLog::record('user.account_activated', [
                'community_id' => $user->community_id,
                'user_id' => $user->id,
                'entity' => 'users',
                'entity_id' => $user->id,
                'data' => ['name' => $user->name],
            ]);
            $this->publisher->publish($user, 'update');

            return $user;
        });
    }
}
