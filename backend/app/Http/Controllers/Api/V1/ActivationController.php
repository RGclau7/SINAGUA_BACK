<?php

namespace App\Http\Controllers\Api\V1;

use App\Accounts\AccountActivation;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/activation (sin sesión): primer acceso con el PIN del correo. Multipart con
 * email, pin, password, password_confirmation y photo (JPG o PNG, máximo 5 MB).
 */
class ActivationController extends Controller
{
    public function __invoke(Request $request, AccountActivation $activation): JsonResponse
    {
        $user = $activation->activate([...$request->only(['email', 'pin', 'password', 'password_confirmation']), 'photo' => $request->file('photo')]);

        return ApiResponse::ok(['activated' => true, 'email' => $user->email, 'name' => $user->name]);
    }
}
