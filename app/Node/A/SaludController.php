<?php

namespace App\Node\A;

use App\Sard\Log\EventLog;
use App\Sard\Nodo\EstadoNodo;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/salud (api.md). Misma URL en todos los nodos con respuesta según el rol:
 * - A: {nodo, rol, lider, epoca, cola_pendiente}
 * - B/C: {nodo, rol, epoca, ultimo_latido_hace_s, seq_max}
 */
final class SaludController
{
    public function __invoke(EstadoNodo $estado, EventLog $log): array
    {
        $nodo = (string) config('sard.nodo_role', 'A');

        if (in_array($nodo, ['B', 'C'], true)) {
            return [
                'nodo' => $nodo,
                'rol' => $estado->rol(),
                'lider' => $estado->lider(),
                'epoca' => $estado->epoca(),
                'ultimo_latido_hace_s' => $estado->ultimoLatidoHaceS(),
                'seq_max' => $log->seqMaxPorOrigen()[$nodo] ?? 0,
                'aislado' => $estado->aislado(),
            ];
        }

        return [
            'nodo' => $nodo,
            'rol' => $estado->rol(),
            'lider' => $estado->lider(),
            'epoca' => $estado->epoca(),
            'cola_pendiente' => DB::table('cola_salida')->whereNull('confirmado_en')->count(),
        ];
    }
}
