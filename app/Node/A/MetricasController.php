<?php

namespace App\Node\A;

use App\Protocol\PayloadReporte;
use Illuminate\Support\Facades\DB;

/**
 * Métricas de ingesta del Nodo A.
 *
 * Calcula la línea base de bytes binarios frente a su equivalente JSON,
 * tal como define el brief v2 §8.
 */
final class MetricasController
{
    public function __invoke(): array
    {
        $reportes = DB::table('cola_salida')->get();

        $bytesBinario = 0;
        $bytesJsonEquivalente = 0;

        foreach ($reportes as $reporte) {
            $bytesBinario += (int) $reporte->bytes;

            try {
                $decodificado = PayloadReporte::decode($reporte->payload);
                $bytesJsonEquivalente += strlen(json_encode($decodificado, JSON_UNESCAPED_UNICODE));
            } catch (\Throwable $e) {
                // Si el payload no se puede decodificar, contar solo el binario.
                $bytesJsonEquivalente += (int) $reporte->bytes;
            }
        }

        return [
            'reportes' => $reportes->count(),
            'bytes_binario' => $bytesBinario,
            'bytes_json_equivalente' => $bytesJsonEquivalente,
            'confirmados_por_lider' => 0,
        ];
    }
}
