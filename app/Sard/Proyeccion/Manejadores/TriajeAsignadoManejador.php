<?php

namespace App\Sard\Proyeccion\Manejadores;

use App\Sard\Reglas\PrioridadIncidente;
use Illuminate\Support\Facades\DB;

/**
 * TriajeAsignado → tabla `triajes`. Si el reporte ya pertenece a un incidente,
 * recalcula ese incidente (su prioridad base depende del triaje).
 *
 * T-016: la prioridad de reglas queda en `prioridad_reglas`; `prioridad` es la efectiva,
 * el máximo entre reglas y la elevación sugerida por el LLM (si la hay).
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
            'prioridad_reglas' => $payload['prioridad'],
            'puntaje' => $payload['puntaje'],
            'senales' => Proyeccion::json($payload['senales'] ?? []),
            'incertidumbre' => $payload['incertidumbre'],
            'motivos' => Proyeccion::json($payload['motivos_incertidumbre'] ?? []),
            'modo' => $payload['modo'],
        ]);

        self::aplicarPrioridadEfectiva($payload['reporte_uuid']);

        $incidente = DB::table('incidente_reporte')->where('reporte_uuid', $payload['reporte_uuid'])->value('incidente_uuid');
        if ($incidente !== null) {
            $this->recalculo->recalcular($incidente, $evento['creado_en']);
        }
    }

    /** prioridad efectiva = la más urgente entre reglas y la elevación del LLM; origen «reglas» o «IA». */
    public static function aplicarPrioridadEfectiva(string $reporteUuid): void
    {
        $triaje = DB::table('triajes')->where('reporte_uuid', $reporteUuid)->first();
        if ($triaje === null) {
            return;
        }

        $reglas = $triaje->prioridad_reglas ?? $triaje->prioridad;
        $elevada = $triaje->elevacion_a !== null
            && PrioridadIncidente::nivel($triaje->elevacion_a) < PrioridadIncidente::nivel($reglas);

        DB::table('triajes')->where('reporte_uuid', $reporteUuid)->update([
            'prioridad' => $elevada ? $triaje->elevacion_a : $reglas,
            'origen' => $elevada ? 'IA' : 'reglas',
        ]);
    }
}
