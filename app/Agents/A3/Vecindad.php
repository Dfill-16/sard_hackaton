<?php

namespace App\Agents\A3;

use App\Sard\Reglas\Puntaje;
use App\Sard\Support\Tiempo;

/**
 * Criterio de vecindad de A3 (ADR-002), compartido por la asignación incremental y el DBSCAN por lotes.
 *
 * Dos reportes con ubicación son vecinos si:
 * |creado_en_a − creado_en_b| ≤ VENTANA_MIN, haversine ≤ DBSCAN_EPS_M,
 * y tienen el mismo tipo o al menos una bandera en común (marcada o inferida por palabras).
 */
final class Vecindad
{
    public const RADIO_TIERRA_M = 6_371_000;

    /**
     * Punto listo para comparar, a partir de una fila de `reportes`.
     *
     * @return array{uuid: string, lat: float, lon: float, tipo: int, banderas: int, ms: int, device_id: string}|null
     *                                                                                                                null si no tiene ubicación
     */
    public static function punto(object $reporte): ?array
    {
        if ($reporte->lat === null || $reporte->lon === null) {
            return null;
        }

        $personas = $reporte->personas === null ? null : (int) $reporte->personas;

        return [
            'uuid' => $reporte->reporte_uuid,
            'lat' => (float) $reporte->lat,
            'lon' => (float) $reporte->lon,
            'tipo' => (int) $reporte->tipo,
            'banderas' => Puntaje::calcular((int) $reporte->banderas, $personas, (string) $reporte->texto)['banderas_efectivas'],
            'ms' => Tiempo::milisegundos(Tiempo::desdeIso($reporte->creado_en)),
            'device_id' => (string) $reporte->device_id,
        ];
    }

    /**
     * @param  array{lat: float, lon: float, tipo: int, banderas: int, ms: int}  $a
     * @param  array{lat: float, lon: float, tipo: int, banderas: int, ms: int}  $b
     */
    public static function sonVecinos(array $a, array $b): bool
    {
        if (abs($a['ms'] - $b['ms']) > (int) config('sard.ventana_min') * 60_000) {
            return false;
        }

        if (self::haversine($a['lat'], $a['lon'], $b['lat'], $b['lon']) > (float) config('sard.dbscan_eps_m')) {
            return false;
        }

        return $a['tipo'] === $b['tipo'] || ($a['banderas'] & $b['banderas']) !== 0;
    }

    /** Distancia en metros sobre la esfera terrestre. */
    public static function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $h = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 2 * self::RADIO_TIERRA_M * asin(min(1.0, sqrt($h)));
    }
}
