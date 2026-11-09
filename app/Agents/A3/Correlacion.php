<?php

namespace App\Agents\A3;

use App\Sard\Log\EventLog;
use App\Sard\Support\Tiempo;
use App\Sard\Support\Uuid7;
use Illuminate\Support\Facades\DB;

/**
 * A3 · Correlación espacio-temporal e incidente masivo (líder, sin LLM, ADR-002).
 *
 * Asignación incremental (DBSCAN de enlace simple con min 2): cada reporte triado entra al
 * incidente de uuid menor entre sus vecinos o abre uno nuevo. Nunca fusiona ni borra originales.
 * Con ≥ MASIVO_DISPOSITIVOS dispositivos distintos en la ventana se declara incidente masivo
 * (una sola vez). Muchos reportes de un solo dispositivo quedan como posible rumor y no se elevan.
 */
final class Correlacion
{
    public function __construct(private EventLog $log) {}

    /**
     * Asigna un reporte a un incidente y emite IncidenteAgrupado (y, si toca, IncidenteMasivoDeclarado).
     *
     * @return string|null incidente_uuid; null si el reporte no existe
     */
    public function asignar(string $reporteUuid): ?string
    {
        $existente = DB::table('incidente_reporte')->where('reporte_uuid', $reporteUuid)->value('incidente_uuid');
        if ($existente !== null) {
            return $existente;
        }

        $reporte = DB::table('reportes')->where('reporte_uuid', $reporteUuid)->first();
        if ($reporte === null) {
            return null;
        }

        $punto = Vecindad::punto($reporte);
        [$incidenteUuid, $distancia] = $punto === null ? [null, null] : $this->incidenteVecino($punto);

        $nuevo = $incidenteUuid === null;
        $incidenteUuid ??= Uuid7::generar();

        $this->log->append('IncidenteAgrupado', [
            'incidente_uuid' => $incidenteUuid,
            'reporte_uuid' => $reporteUuid,
            'nuevo' => $nuevo,
            'tipo' => (int) $reporte->tipo,
            'distancia_m' => $distancia === null ? null : round($distancia, 1),
        ], $incidenteUuid);

        if ($punto !== null) {
            $this->declararMasivoSiCorresponde($incidenteUuid, $punto['ms']);
        }

        return $incidenteUuid;
    }

    /**
     * Incidente de uuid menor entre los vecinos ya agrupados, y la distancia al vecino más cercano de ese incidente.
     *
     * @param  array{uuid: string, lat: float, lon: float, tipo: int, banderas: int, ms: int}  $punto
     * @return array{0: string|null, 1: float|null}
     */
    private function incidenteVecino(array $punto): array
    {
        $ventanaMs = (int) config('sard.ventana_min') * 60_000;

        $candidatos = DB::table('reportes')
            ->join('incidente_reporte', 'incidente_reporte.reporte_uuid', '=', 'reportes.reporte_uuid')
            ->whereNotNull('reportes.lat')
            ->whereNotNull('reportes.lon')
            ->where('reportes.reporte_uuid', '!=', $punto['uuid'])
            ->whereBetween('reportes.creado_en', [
                Tiempo::iso(Tiempo::desdeMilisegundos($punto['ms'] - $ventanaMs)),
                Tiempo::iso(Tiempo::desdeMilisegundos($punto['ms'] + $ventanaMs)),
            ])
            ->get(['reportes.*', 'incidente_reporte.incidente_uuid']);

        $distanciasPorIncidente = [];
        foreach ($candidatos as $candidato) {
            $otro = Vecindad::punto($candidato);
            if ($otro === null || ! Vecindad::sonVecinos($punto, $otro)) {
                continue;
            }

            $distancia = Vecindad::haversine($punto['lat'], $punto['lon'], $otro['lat'], $otro['lon']);
            $actual = $distanciasPorIncidente[$candidato->incidente_uuid] ?? INF;
            $distanciasPorIncidente[$candidato->incidente_uuid] = min($actual, $distancia);
        }

        if ($distanciasPorIncidente === []) {
            return [null, null];
        }

        ksort($distanciasPorIncidente, SORT_STRING);
        $incidente = array_key_first($distanciasPorIncidente);

        return [$incidente, $distanciasPorIncidente[$incidente]];
    }

    /** Emite IncidenteMasivoDeclarado una sola vez, al llegar a MASIVO_DISPOSITIVOS distintos en la ventana. */
    private function declararMasivoSiCorresponde(string $incidenteUuid, int $referenciaMs): void
    {
        $incidente = DB::table('incidentes')->where('uuid', $incidenteUuid)->first();
        if ($incidente === null || $incidente->masivo || $incidente->sin_ubicacion) {
            return;
        }

        $ventanaMs = (int) config('sard.ventana_min') * 60_000;
        $miembros = DB::table('incidente_reporte')
            ->join('reportes', 'reportes.reporte_uuid', '=', 'incidente_reporte.reporte_uuid')
            ->where('incidente_reporte.incidente_uuid', $incidenteUuid)
            ->orderBy('reportes.creado_en')
            ->get(['reportes.reporte_uuid', 'reportes.device_id', 'reportes.creado_en']);

        $enVentana = $miembros->filter(fn (object $m): bool => abs(Tiempo::milisegundos(Tiempo::desdeIso($m->creado_en)) - $referenciaMs) <= $ventanaMs);
        $dispositivos = $enVentana->pluck('device_id')->unique()->count();

        if ($dispositivos < (int) config('sard.masivo_dispositivos')) {
            return;
        }

        $this->log->append('IncidenteMasivoDeclarado', [
            'incidente_uuid' => $incidenteUuid,
            'dispositivos' => $dispositivos,
            'reportes' => $miembros->pluck('reporte_uuid')->all(),
        ], $incidenteUuid);
    }
}
