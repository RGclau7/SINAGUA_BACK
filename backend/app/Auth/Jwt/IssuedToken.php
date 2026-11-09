<?php

namespace App\Auth\Jwt;

use App\Models\AuthSession;

/** Un JWT recién firmado y la sesión del servidor a la que apunta su jti. */
final readonly class IssuedToken
{
    public function __construct(
        public string $token,
        public AuthSession $session,
    ) {}
}
