<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un usuario desactivado pierde el acceso en su siguiente petición (por ejemplo, al
 * sincronizar): se revocan sus sesiones JWT y el cliente cierra la sesión.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user->is_active) {
            $user->revokeAllSessions();
            throw new ApiException('account_inactive', 'La cuenta está desactivada. Consulte al administrador.', 403);
        }

        return $next($request);
    }
}
