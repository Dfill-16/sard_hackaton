<?php

namespace App\Sard\Proyeccion\Manejadores;

use Illuminate\Support\Facades\DB;

/**
 * TriajeAsignado → tabla `triajes`. Si el reporte ya pertenece a un incidente,
 * recalcula ese incidente (su prioridad base depende del triaje).
 */
final class TriajeAsignadoManejador
{
    public function __construct(private RecalculoIncidente $recalculo) {}

    /** @param  array<string, mixed>  $evento */
    public function __invoke(array $evento): void
    {
        $payload = Proyeccion::payload($evento);

        Proyeccion::guardarGanaMenor('triajes', 'reporte_uuid', [
            'reporte_uuid' => $payload['reporte_uuid'],
            'evento_uuid' => $evento['uuid'],
            'prioridad' => $payload['prioridad'],
            'puntaje' => $payload['puntaje'],
            'senales' => Proyeccion::json($payload['senales'] ?? []),
            'incertidumbre' => $payload['incertidumbre'],
            'motivos' => Proyeccion::json($payload['motivos_incertidumbre'] ?? []),
            'modo' => $payload['modo'],
        ]);

        $incidente = DB::table('incidente_reporte')->where('reporte_uuid', $payload['reporte_uuid'])->value('incidente_uuid');
        if ($incidente !== null) {
            $this->recalculo->recalcular($incidente, $evento['creado_en']);
        }
    }
}
