<?php

namespace App\Sard\Proyeccion\Manejadores;

use App\Sard\Reglas\PrioridadIncidente;
use Illuminate\Support\Facades\DB;

/**
 * PrioridadElevadaLLM → el triaje gana la elevación sugerida (T-016 · REQ-012).
 *
 * Nunca baja: si la sugerencia no es más urgente que las reglas, la prioridad efectiva no cambia.
 * Si hubiera dos elevaciones (tras la unión), queda la más urgente.
 */
final class PrioridadElevadaLLMManejador
{
    public function __construct(private RecalculoIncidente $recalculo) {}

    /** @param  array<string, mixed>  $evento */
    public function __invoke(array $evento): void
    {
        $payload = Proyeccion::payload($evento);
        $triaje = DB::table('triajes')->where('reporte_uuid', $payload['reporte_uuid'])->first();
        if ($triaje === null) {
            return;
        }

        $actual = $triaje->elevacion_a;
        if ($actual === null || PrioridadIncidente::nivel($payload['a']) < PrioridadIncidente::nivel($actual)) {
            DB::table('triajes')->where('reporte_uuid', $payload['reporte_uuid'])->update([
                'elevacion_a' => $payload['a'],
                'elevacion_explicacion' => $payload['explicacion'],
                'elevacion_proveedor' => $payload['proveedor'],
            ]);
        }

        TriajeAsignadoManejador::aplicarPrioridadEfectiva($payload['reporte_uuid']);

        $incidente = DB::table('incidente_reporte')->where('reporte_uuid', $payload['reporte_uuid'])->value('incidente_uuid');
        if ($incidente !== null) {
            $this->recalculo->recalcular($incidente, $evento['creado_en']);
        }
    }
}
