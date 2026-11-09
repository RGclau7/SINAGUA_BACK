<?php

use App\Http\Controllers\ActivarCuentaController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Documentación de la API (contrato en docs/api/openapi.yaml).
Route::view('/docs', 'docs.swagger')->name('docs.swagger');
Route::get('/docs/openapi.yaml', fn () => response()->file(base_path('docs/api/openapi.yaml'), [
    'Content-Type' => 'application/yaml; charset=UTF-8',
]))->name('docs.spec');

// Activación de cuenta con el PIN del correo (enlace del correo; misma lógica que la app).
Route::get('/activar', [ActivarCuentaController::class, 'show'])->name('activar.show');
Route::post('/activar', [ActivarCuentaController::class, 'store'])->middleware('throttle:pin')->name('activar.store');
