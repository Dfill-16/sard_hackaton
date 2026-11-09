<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restringe el acceso a las rutas del Nodo A.
 *
 * Solo las peticiones cuyo nodo de origen sea A (NODE_ROLE=A) pueden
 * escribir en /api/reportes. Cualquier otro rol recibe 404.
 */
final class SoloNodoA
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('sard.nodo_role', 'A') !== 'A') {
            abort(404);
        }

        return $next($request);
    }
}
