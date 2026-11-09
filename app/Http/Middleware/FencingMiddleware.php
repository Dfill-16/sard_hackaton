<?php

namespace App\Http\Middleware;

use App\Sard\Nodo\EstadoNodo;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Control de época (fencing) para escrituras entre nodos.
 *
 * Si el encabezado `X-Sard-Epoca` trae una época menor que la época local,
 * la escritura se rechaza con 409 `epoca_vieja`.
 */
final class FencingMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $epocaHeader = $request->header('X-Sard-Epoca');
        if ($epocaHeader !== null && $epocaHeader !== '') {
            $epocaLocal = app(EstadoNodo::class)->epoca();
            if ((int) $epocaHeader < $epocaLocal) {
                return response()->json(['error' => 'epoca_vieja'], 409);
            }
        }

        return $next($request);
    }
}
