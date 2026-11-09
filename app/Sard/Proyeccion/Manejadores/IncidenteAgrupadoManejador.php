<?php

namespace App\Sard\Proyeccion\Manejadores;

use Illuminate\Support\Facades\DB;

/**
 * IncidenteAgrupado → `incidente_reporte` y recálculo de `incidentes`.
 *
 * Solo agrega la relación; el reporte original sigue intacto en `reportes`.
 */
final class IncidenteAgrupadoManejador
{
    public function __construct(private RecalculoIncidente $recalculo) {}

    /** @param  array<string, mixed>  $evento */
    public function __invoke(array $evento): void
    {
        $payload = Proyeccion::payload($evento);

        DB::table('incidentes')->insertOrIgnore([
            'uuid' => $payload['incidente_uuid'],
            'tipo' => $payload['tipo'],
            'lat' => null,
            'lon' => null,
            'sin_ubicacion' => true,
            'reportes_n' => 0,
            'dispositivos_n' => 0,
            'masivo' => false,
            'prioridad_base' => 'P4',
            'confianza' => 0,
            'contradicciones' => '[]',
            'estado' => 'abierto',
            'creado_en' => $evento['creado_en'],
            'actualizado_en' => $evento['creado_en'],
        ]);

        $anterior = DB::table('incidente_reporte')->where('reporte_uuid', $payload['reporte_uuid'])->value('incidente_uuid');

        Proyeccion::guardarGanaMenor('incidente_reporte', 'reporte_uuid', [
            'reporte_uuid' => $payload['reporte_uuid'],
            'incidente_uuid' => $payload['incidente_uuid'],
            'evento_uuid' => $evento['uuid'],
        ]);

        // Tras la unión, el mismo reporte pudo agruparse en dos incidentes (uno por nodo).
        // Gana el evento menor; el incidente que pierde se recalcula y, si queda vacío, sale de la proyección.
        $this->recalculo->recalcular($payload['incidente_uuid'], $evento['creado_en']);
        if ($anterior !== null && $anterior !== $payload['incidente_uuid']) {
            $this->recalculo->recalcular($anterior, $evento['creado_en']);
        }
    }
}
