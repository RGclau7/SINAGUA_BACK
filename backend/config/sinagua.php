<?php

return [

    'auth' => [
        // Intentos fallidos antes de bloquear la cuenta (RNF de seguridad).
        'max_attempts' => (int) env('AUTH_MAX_ATTEMPTS', 5),

        // Minutos de bloqueo tras agotar los intentos (decisión del 1 de octubre).
        'lockout_minutes' => (int) env('AUTH_LOCKOUT_MINUTES', 15),

        // Minutos sin usar el token antes de que expire por inactividad (decisión del 1 de octubre).
        'idle_minutes' => (int) env('AUTH_IDLE_MINUTES', 30),

        // Vida máxima de un token aunque se use continuamente.
        'token_lifetime_hours' => (int) env('AUTH_TOKEN_LIFETIME_HOURS', 12),
    ],

    // PIN de seguridad del superadministrador (se pide antes de cambiar administradores).
    'pin' => [
        'max_attempts' => (int) env('SECURITY_PIN_MAX_ATTEMPTS', 5),
        'lockout_minutes' => (int) env('SECURITY_PIN_LOCKOUT_MINUTES', 15),
    ],

    // PIN de un solo uso por correo (activación de cuenta y entrada en un equipo nuevo).
    'codes' => [
        'max_attempts' => (int) env('PIN_MAX_ATTEMPTS', 5),
        // Vigencia del PIN de activación: entre 24 y 48 horas.
        'activation_hours' => max(24, min(48, (int) env('ACTIVATION_PIN_HOURS', 48))),
        'login_minutes' => (int) env('LOGIN_PIN_MINUTES', 15),
    ],

    // Verificación en dos pasos del administrador: PIN al correo la primera vez que entra en un
    // equipo (después ese equipo queda de confianza durante trusted_days días).
    'two_factor' => [
        'enabled' => (bool) env('ADMIN_TWO_FACTOR', true),
        'trusted_days' => (int) env('TRUSTED_DEVICE_DAYS', 180),
    ],

    // Códigos de autorización de gastos del cobrador que pasan el tope: minutos de vigencia.
    'autorizaciones' => [
        'minutes' => (int) env('AUTORIZACION_MINUTES', 30),
    ],

    // Dirección pública del servidor para los enlaces de los correos (por ejemplo
    // https://sinagua.onrender.com). Sin ella se usa APP_URL.
    'public_url' => rtrim((string) env('SINAGUA_PUBLIC_URL', env('APP_URL', 'http://localhost:8081')), '/'),

    // Disco de las fotos de perfil (MinIO en desarrollo, cualquier S3 compatible en la nube).
    'fotos_disk' => env('SINAGUA_FOTOS_DISK', env('SINAGUA_COMPROBANTES_DISK', 's3')),

    // JWT de la API (HS256). Sin JWT_SECRET, la llave se deriva de APP_KEY.
    'jwt' => [
        'secret' => env('JWT_SECRET', ''),
        'issuer' => env('JWT_ISSUER', env('APP_URL', 'http://localhost')),
        'audience' => env('JWT_AUDIENCE', 'sinagua-api'),
        // Tolerancia de reloj entre servidor y equipos al revisar exp/nbf/iat.
        'leeway_seconds' => (int) env('JWT_LEEWAY_SECONDS', 30),
    ],

    // Disco donde se guardan las fotos y PDF de los comprobantes de gasto (MinIO en desarrollo).
    'comprobantes_disk' => env('SINAGUA_COMPROBANTES_DISK', 's3'),

];
