<?php

namespace App\Http\Controllers;

use App\Accounts\AccountActivation;
use App\Exceptions\ApiException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Página web mínima /activar (el enlace del correo): la misma activación que la app, para
 * quien la abre desde el teléfono. No guarda nada en el navegador.
 */
class ActivarCuentaController extends Controller
{
    public function show(Request $request): View
    {
        return view('activar.form', ['email' => (string) $request->query('correo', ''), 'errors_list' => [], 'done' => false]);
    }

    public function store(Request $request, AccountActivation $activation): View
    {
        try {
            $user = $activation->activate([...$request->only(['email', 'pin', 'password', 'password_confirmation']), 'photo' => $request->file('photo')]);

            return view('activar.form', ['email' => $user->email, 'errors_list' => [], 'done' => true, 'name' => $user->name]);
        } catch (ValidationException $e) {
            $errors = collect($e->errors())->flatten()->unique()->values()->all();
        } catch (ApiException $e) {
            $errors = [$e->getMessage()];
        }

        return view('activar.form', ['email' => (string) $request->input('email', ''), 'errors_list' => $errors, 'done' => false]);
    }
}
