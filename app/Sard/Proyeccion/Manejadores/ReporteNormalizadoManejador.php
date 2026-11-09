<?php

namespace App\Sard\Proyeccion\Manejadores;

/**
 * ReporteNormalizado → tabla `reportes` (originales del líder; nunca se borran).
 */
final class ReporteNormalizadoManejador
{
    /** @param  array<string, mixed>  $evento */
    public function __invoke(array $evento): void
    {
        $payload = Proyeccion::payload($evento);

        Proyeccion::guardarGanaMenor('reportes', 'reporte_uuid', [
            'reporte_uuid' => $payload['reporte_uuid'],
            'evento_uuid' => $evento['uuid'],
            'device_id' => $payload['device_id'],
            'tipo' => $payload['tipo'],
            'banderas' => $payload['banderas'],
            'personas' => $payload['personas'],
            'texto' => $payload['texto'],
            'lat' => $payload['lat'],
            'lon' => $payload['lon'],
            'sin_ubicacion' => (bool) $payload['sin_ubicacion'],
            'creado_en' => $payload['creado_en'],
            'truncado' => (bool) $payload['truncado'],
            'campos_desconocidos' => Proyeccion::json($payload['campos_desconocidos'] ?? []),
            'recibido_en' => $evento['creado_en'],
        ]);
    }
}
