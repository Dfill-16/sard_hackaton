<?php

namespace App\Jobs;

use App\Agents\A2\Triaje;
use App\Agents\A3\Correlacion;
use App\Agents\A6\Coordinacion;
use App\Sard\Log\EventLog;
use App\Sard\Nodo\EstadoNodo;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Procesa un reporte ya normalizado en el líder: A2 (triaje por reglas) y luego A3 (correlación).
 *
 * Idempotente: si el reporte ya tiene triaje o incidente, no vuelve a emitir esos eventos.
 * En un seguidor termina sin hacer nada (solo el líder ejecuta agentes).
 */
class ProcesarReporte implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $reporteUuid) {}

    public function handle(EstadoNodo $estado, EventLog $log, Triaje $triaje, Correlacion $correlacion, Coordinacion $coordinacion): void
    {
        if (! $estado->esLider()) {
            return;
        }

        $reporte = DB::table('reportes')->where('reporte_uuid', $this->reporteUuid)->first();
        if ($reporte === null) {
            return;
        }

        if (! DB::table('triajes')->where('reporte_uuid', $this->reporteUuid)->exists()) {
            $normalizado = (array) $reporte;
            $normalizado['sin_ubicacion'] = (bool) $reporte->sin_ubicacion;
            $normalizado['truncado'] = (bool) $reporte->truncado;

            $log->append('TriajeAsignado', $triaje->evaluar($normalizado), $this->reporteUuid);
        }

        $incidenteUuid = $correlacion->asignar($this->reporteUuid);
        if ($incidenteUuid !== null) {
            $coordinacion->evaluar($incidenteUuid);
        }
    }
}
