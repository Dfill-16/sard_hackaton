<?php

namespace App\Agents\A1;

use App\Protocol\PayloadReporte;
use App\Sard\Reglas\Banderas;
use App\Sard\Support\Tiempo;
use App\Sard\Support\Uuid7;

/**
 * A1 · Recepción (líder, sin LLM): normaliza el reporte decodificado sin inventar nada.
 *
 * - personas 0 → null (desconocido).
 * - Sin GPS → lat/lon null y sin_ubicacion true; nunca se estima (REQ-011).
 * - Tipo fuera de 0-7 → 0 con "tipo" en campos_desconocidos; bit 15 → "banderas_bit15".
 * - Texto con UTF-8 inválido o de 140 B → truncado true (se conserva, nunca se descarta).
 * - creado_en sale del UUIDv7.
 */
final class Recepcion
{
    /**
     * @param  array<string, mixed>  $reporte  arreglo canónico de PayloadReporte::decode()
     * @return array{
     *     reporte_uuid: string, device_id: string, tipo: int, banderas: int, personas: int|null,
     *     texto: string, lat: float|null, lon: float|null, sin_ubicacion: bool, creado_en: string,
     *     truncado: bool, campos_desconocidos: array<int, string>,
     * } payload del evento ReporteNormalizado
     */
    public function normalizar(array $reporte): array
    {
        $camposDesconocidos = [];

        $tipo = (int) $reporte['tipo'];
        if ($tipo < 0 || $tipo > 7) {
            $tipo = 0;
            $camposDesconocidos[] = 'tipo';
        }

        $banderas = (int) $reporte['banderas'];
        if (($banderas & (1 << Banderas::BIT_RESERVADO)) !== 0) {
            $banderas &= ~(1 << Banderas::BIT_RESERVADO);
            $camposDesconocidos[] = 'banderas_bit15';
        }

        $texto = (string) $reporte['texto'];
        $truncado = false;
        if (! mb_check_encoding($texto, 'UTF-8')) {
            $texto = mb_scrub($texto, 'UTF-8');
            $truncado = true;
        }
        if (strlen($texto) >= PayloadReporte::MAXIMO_BYTES_TEXTO) {
            $truncado = true;
        }

        $sinUbicacion = $reporte['lat'] === null || $reporte['lon'] === null;

        return [
            'reporte_uuid' => $reporte['uuid'],
            'device_id' => sprintf('%08x', $reporte['device_id']),
            'tipo' => $tipo,
            'banderas' => $banderas,
            'personas' => (int) $reporte['personas'] === 0 ? null : (int) $reporte['personas'],
            'texto' => $texto,
            'lat' => $sinUbicacion ? null : (float) $reporte['lat'],
            'lon' => $sinUbicacion ? null : (float) $reporte['lon'],
            'sin_ubicacion' => $sinUbicacion,
            'creado_en' => Tiempo::iso(Tiempo::desdeMilisegundos(Uuid7::milisegundos($reporte['uuid']))),
            'truncado' => $truncado,
            'campos_desconocidos' => $camposDesconocidos,
        ];
    }
}
