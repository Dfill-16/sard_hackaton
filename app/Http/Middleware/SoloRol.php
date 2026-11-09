<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restringe un grupo de rutas a uno o varios roles de nodo (NODE_ROLE).
 *
 * Uso: `->middleware('rol:CANAL')` o `->middleware('rol:B,C')`.
 * Cualquier otro rol recibe 404, igual que SoloNodoA.
 */
final class SoloRol
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if (! in_array(config('sard.nodo_role', 'A'), $roles, true)) {
            abort(404);
        }

        return $next($request);
    }
}
