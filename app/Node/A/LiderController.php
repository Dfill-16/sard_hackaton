<?php

namespace App\Node\A;

use App\Agents\A4\Comunicacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * POST /api/lider {nodo, epoca} en el Nodo A (T-012): el nuevo líder se anuncia tras el failover.
 *
 * Fencing: una época igual o menor a la local se rechaza con 409.
 */
final class LiderController
{
    public function __construct(private Comunicacion $a4) {}

    public function __invoke(Request $request): Response|JsonResponse
    {
        $datos = $request->validate([
            'nodo' => ['required', 'in:B,C'],
            'epoca' => ['required', 'integer', 'min:1'],
        ]);

        if (! $this->a4->adoptarLider($datos['nodo'], $datos['epoca'])) {
            return response()->json(['error' => 'epoca_vieja', 'detalle' => 'La época anunciada no es mayor que la local.'], 409);
        }

        return response()->noContent();
    }
}
