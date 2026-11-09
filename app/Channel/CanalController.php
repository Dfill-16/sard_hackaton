<?php

namespace App\Channel;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Rutas HTTP del proceso CANAL (api.md): enviar, estadísticas y configuración en vivo.
 */
final class CanalController
{
    public function __construct(private Canal $canal) {}

    public function enviar(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'origen' => ['required', 'string', 'in:A,B,C,D'],
            'destino' => ['required', 'string', 'in:A,B,C,D'],
            'ruta' => ['required', 'string', 'starts_with:/'],
            'content_type' => ['required', 'string'],
            'cuerpo_b64' => ['present', 'string'],
        ]);

        $cuerpo = base64_decode($datos['cuerpo_b64'], true);
        if ($cuerpo === false) {
            return response()->json(['error' => 'cuerpo_invalido', 'detalle' => 'cuerpo_b64 no es base64 válido.'], 422);
        }

        $mtu = (int) $this->canal->parametros()['mtu_b'];
        if (strlen($cuerpo) > $mtu) {
            return response()->json([
                'error' => 'mtu_excedida',
                'detalle' => 'El mensaje ocupa '.strlen($cuerpo)." B y la MTU del canal es {$mtu} B.",
            ], 413);
        }

        return response()->json($this->canal->encolar($datos, $cuerpo), 202);
    }

    public function estadisticas(): JsonResponse
    {
        return response()->json($this->canal->estadisticas());
    }

    public function configurar(Request $request): Response|JsonResponse
    {
        if (! config('sard.demo')) {
            return response()->json(['error' => 'solo_demo', 'detalle' => 'Requiere SARD_DEMO=true.'], 403);
        }

        $cambios = $request->validate([
            'perdida' => ['sometimes', 'numeric', 'between:0,1'],
            'ventana_abierta_s' => ['sometimes', 'numeric', 'min:0'],
            'ventana_cerrada_s' => ['sometimes', 'numeric', 'min:0'],
            'saltos' => ['sometimes', 'integer', 'between:1,3'],
        ]);

        $this->canal->actualizarParametros($cambios);

        return response()->noContent();
    }
}
