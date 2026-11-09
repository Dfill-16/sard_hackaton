<?php

namespace App\Node\A;

use App\Sard\Support\Tiempo;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/acks en el Nodo A: el líder confirma, a través del canal, que recibió un reporte.
 *
 * Idempotente: un ACK repetido no cambia la primera confirmación.
 */
final class AcksController
{
    public function __invoke(Request $request): Response
    {
        $datos = $request->validate([
            'reporte_uuid' => ['required', 'string'],
            'nodo' => ['required', 'string', 'in:B,C'],
            'epoca' => ['required', 'integer', 'min:0'],
        ]);

        DB::table('cola_salida')
            ->where('reporte_uuid', $datos['reporte_uuid'])
            ->whereNull('confirmado_en')
            ->update([
                'confirmado_en' => Tiempo::iso(),
                'confirmado_por' => $datos['nodo'],
            ]);

        return response()->noContent();
    }
}
