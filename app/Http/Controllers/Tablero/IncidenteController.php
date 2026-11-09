<?php

namespace App\Http\Controllers\Tablero;

use App\Sard\Reglas\Banderas;
use App\Sard\Support\Tiempo;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/incidentes/{uuid} · incidente con sus reportes originales y los eventos
 * relacionados (uuid, tipo, nodo, seq, hash corto) para la trazabilidad (AC-013a).
 */
final class IncidenteController
{
    public const LARGO_HASH_CORTO = 12;

    public function __construct(private Presentador $presentador) {}

    public function __invoke(string $uuid): JsonResponse
    {
        $incidente = DB::table('incidentes')->where('uuid', $uuid)->first();
        if ($incidente === null) {
            return response()->json(['error' => 'no_encontrado', 'detalle' => "No existe el incidente {$uuid}."], 404);
        }

        $reportes = DB::table('incidente_reporte')
            ->join('reportes', 'reportes.reporte_uuid', '=', 'incidente_reporte.reporte_uuid')
            ->leftJoin('triajes', 'triajes.reporte_uuid', '=', 'incidente_reporte.reporte_uuid')
            ->where('incidente_reporte.incidente_uuid', $uuid)
            ->orderBy('reportes.creado_en')
            ->get([
                'reportes.*', 'triajes.prioridad', 'triajes.puntaje', 'triajes.senales',
                'triajes.incertidumbre', 'triajes.motivos', 'triajes.modo',
            ]);

        $resumen = $this->presentador->resumenes($uuid)[$uuid] ?? '';
        $referencias = [$uuid, ...$reportes->pluck('reporte_uuid')->all()];

        $eventos = DB::table('eventos')
            ->whereIn('ref_uuid', $referencias)
            ->orderBy('uuid')
            ->get(['uuid', 'tipo', 'nodo_origen', 'seq', 'epoca', 'ref_uuid', 'creado_en', 'hash'])
            ->map(fn (object $evento): array => [
                'uuid' => $evento->uuid,
                'tipo' => $evento->tipo,
                'nodo' => $evento->nodo_origen,
                'seq' => (int) $evento->seq,
                'epoca' => (int) $evento->epoca,
                'ref_uuid' => $evento->ref_uuid,
                'creado_en' => $evento->creado_en,
                'hash' => substr($evento->hash, 0, self::LARGO_HASH_CORTO),
            ]);

        $recomendacion = DB::table('recomendaciones')
            ->leftJoin('unidades', 'unidades.id', '=', 'recomendaciones.unidad_id')
            ->where('recomendaciones.incidente_uuid', $uuid)
            ->orderByDesc('recomendaciones.version')
            ->first(['recomendaciones.*', 'unidades.nombre as unidad_nombre', 'unidades.tipo as unidad_tipo']);

        $decisiones = DB::table('decisiones')->where('incidente_uuid', $uuid)->orderBy('creado_en')->get()
            ->map(fn (object $d): array => [
                'uuid' => $d->uuid,
                'recomendacion_uuid' => $d->recomendacion_uuid,
                'accion' => $d->accion,
                'motivo' => $d->motivo,
                'operador' => $d->operador,
                'nodo' => $d->nodo,
                'creado_en' => $d->creado_en,
            ]);

        $alertas = DB::table('alertas')
            ->leftJoin('unidades', 'unidades.id', '=', 'alertas.unidad_id')
            ->where('alertas.incidente_uuid', $uuid)
            ->orderBy('alertas.enviada_en')
            ->get(['alertas.uuid', 'alertas.unidad_id', 'unidades.nombre as unidad_nombre', 'alertas.texto', 'alertas.enviada_en', 'alertas.ack_en']);

        return response()->json([
            ...$this->presentador->tarjeta($incidente, Tiempo::ahora(), $resumen, $decisiones->isNotEmpty()),
            'contradicciones' => json_decode($incidente->contradicciones, true) ?? [],
            'recomendacion' => $recomendacion === null ? null : [
                'uuid' => $recomendacion->uuid,
                'version' => (int) $recomendacion->version,
                'unidad_id' => $recomendacion->unidad_id,
                'unidad_nombre' => $recomendacion->unidad_nombre,
                'unidad_tipo' => $recomendacion->unidad_tipo,
                'accion' => $recomendacion->accion,
                'justificacion' => $recomendacion->justificacion,
                'pendientes' => json_decode($recomendacion->pendientes, true) ?? [],
                'distancia_m' => $recomendacion->distancia_m === null ? null : (int) $recomendacion->distancia_m,
                'modo' => $recomendacion->modo,
                'creado_en' => $recomendacion->creado_en,
                'decidida' => $decisiones->contains('recomendacion_uuid', $recomendacion->uuid),
            ],
            'decisiones' => $decisiones->all(),
            'alertas' => $alertas->all(),
            'reportes' => $reportes->map(fn (object $reporte): array => [
                'uuid' => $reporte->reporte_uuid,
                'texto' => $reporte->texto,
                'device_id' => $reporte->device_id,
                'tipo' => (int) $reporte->tipo,
                'banderas' => (int) $reporte->banderas,
                'banderas_claves' => array_map(
                    fn (string $clave): string => Banderas::CATALOGO[Banderas::bitDeClave($clave)]['etiqueta'],
                    Banderas::claves((int) $reporte->banderas),
                ),
                'personas' => $reporte->personas === null ? null : (int) $reporte->personas,
                'lat' => $reporte->lat === null ? null : (float) $reporte->lat,
                'lon' => $reporte->lon === null ? null : (float) $reporte->lon,
                'sin_ubicacion' => (bool) $reporte->sin_ubicacion,
                'hora' => $reporte->creado_en,
                'truncado' => (bool) $reporte->truncado,
                'prioridad' => $reporte->prioridad,
                'puntaje' => $reporte->puntaje === null ? null : (int) $reporte->puntaje,
                'senales' => json_decode($reporte->senales ?? '[]', true),
                'incertidumbre' => $reporte->incertidumbre === null ? null : (float) $reporte->incertidumbre,
                'motivos_incertidumbre' => json_decode($reporte->motivos ?? '[]', true),
                'origen' => $reporte->modo === 'llm' ? 'IA' : 'reglas',
            ])->all(),
            'eventos' => $eventos->all(),
        ]);
    }
}
