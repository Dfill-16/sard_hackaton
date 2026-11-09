<?php

namespace App\Http\Controllers\Tablero;

use App\Agents\A3\Vecindad;
use App\Sard\Reglas\Banderas;
use App\Sard\Reglas\PrioridadIncidente;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Arma las tarjetas de incidente que consume el tablero (api.md, T-009).
 */
final class Presentador
{
    public const LARGO_RESUMEN = 60;

    /** T-014: con confianza ≥ 0.6 el incidente se muestra «corroborado» (borde sólido); si no, «dudoso». */
    public const UMBRAL_CORROBORADO = 0.6;

    /** Radio mínimo del círculo de incertidumbre en el mapa (un solo reporte o reportes superpuestos). */
    public const RADIO_MINIMO_M = 60;

    /**
     * Todos los incidentes con su prioridad efectiva, ordenados por prioridad y antigüedad.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function incidentes(CarbonInterface $ahora): Collection
    {
        $incidentes = DB::table('incidentes')->get();
        $resumenes = $this->resumenes();
        $radios = $this->radios();
        // T-015: la alerta más reciente de cada incidente, para la columna de acuse.
        $alertas = DB::table('alertas')->orderBy('enviada_en')->get(['incidente_uuid', 'unidad_id', 'enviada_en', 'ack_en'])->keyBy('incidente_uuid');
        $conDecision = DB::table('decisiones')->distinct()->pluck('incidente_uuid')->flip();
        $conRecomendacionPendiente = DB::table('recomendaciones')
            ->whereNotIn('uuid', DB::table('decisiones')->select('recomendacion_uuid'))
            ->distinct()
            ->pluck('incidente_uuid')
            ->flip();

        return $incidentes
            ->map(fn (object $incidente): array => [
                ...$this->tarjeta($incidente, $ahora, $resumenes[$incidente->uuid] ?? '', $conDecision->has($incidente->uuid), $radios[$incidente->uuid] ?? null),
                'recomendacion_pendiente' => $conRecomendacionPendiente->has($incidente->uuid),
                'alerta' => isset($alertas[$incidente->uuid]) ? [
                    'unidad_id' => $alertas[$incidente->uuid]->unidad_id,
                    'enviada_en' => $alertas[$incidente->uuid]->enviada_en,
                    'ack_en' => $alertas[$incidente->uuid]->ack_en,
                ] : null,
            ])
            ->sort(fn (array $a, array $b): int => [PrioridadIncidente::nivel($a['prioridad']), $a['creado_en'], $a['uuid']]
                <=> [PrioridadIncidente::nivel($b['prioridad']), $b['creado_en'], $b['uuid']])
            ->values();
    }

    /** @return array<string, mixed> */
    public function tarjeta(object $incidente, CarbonInterface $ahora, string $resumen, bool $tieneDecision = false, ?int $radio = null): array
    {
        $prioridad = PrioridadIncidente::efectiva($incidente, $ahora, $tieneDecision);
        $confianza = (float) $incidente->confianza;

        return [
            'uuid' => $incidente->uuid,
            'prioridad' => $prioridad['prioridad'],
            'prioridad_base' => $incidente->prioridad_base,
            'envejecido' => $prioridad['envejecido'],
            'tipo' => (int) $incidente->tipo,
            'tipo_nombre' => $this->conMayuscula(Banderas::nombreTipo((int) $incidente->tipo)),
            'masivo' => (bool) $incidente->masivo,
            'posible_rumor' => PrioridadIncidente::posibleRumor($incidente),
            'reportes_n' => (int) $incidente->reportes_n,
            'dispositivos_n' => (int) $incidente->dispositivos_n,
            'confianza' => $confianza,
            'corroborado' => $confianza >= self::UMBRAL_CORROBORADO,
            'contradicciones_n' => count(json_decode($incidente->contradicciones, true) ?? []),
            'radio_m' => $incidente->lat === null ? null : max(self::RADIO_MINIMO_M, $radio ?? 0),
            'sin_ubicacion' => (bool) $incidente->sin_ubicacion,
            'lat' => $incidente->lat === null ? null : (float) $incidente->lat,
            'lon' => $incidente->lon === null ? null : (float) $incidente->lon,
            'resumen' => mb_substr($resumen, 0, self::LARGO_RESUMEN),
            'creado_en' => $incidente->creado_en,
            'estado' => $incidente->estado,
        ];
    }

    /**
     * Radio aproximado de cada incidente: la mayor distancia de un reporte a su centroide (metros).
     *
     * @return array<string, int>
     */
    public function radios(?string $incidenteUuid = null): array
    {
        $filas = DB::table('incidente_reporte')
            ->join('reportes', 'reportes.reporte_uuid', '=', 'incidente_reporte.reporte_uuid')
            ->join('incidentes', 'incidentes.uuid', '=', 'incidente_reporte.incidente_uuid')
            ->whereNotNull('reportes.lat')
            ->whereNotNull('incidentes.lat')
            ->when($incidenteUuid !== null, fn ($consulta) => $consulta->where('incidente_reporte.incidente_uuid', $incidenteUuid))
            ->get(['incidente_reporte.incidente_uuid', 'reportes.lat', 'reportes.lon', 'incidentes.lat as c_lat', 'incidentes.lon as c_lon']);

        $radios = [];
        foreach ($filas as $fila) {
            $distancia = (int) ceil(Vecindad::haversine((float) $fila->c_lat, (float) $fila->c_lon, (float) $fila->lat, (float) $fila->lon));
            $radios[$fila->incidente_uuid] = max($radios[$fila->incidente_uuid] ?? 0, $distancia);
        }

        return $radios;
    }

    /**
     * Fuentes de un incidente: cada dispositivo con cuántos reportes envió y cuándo (T-014).
     *
     * @return array<int, array{device_id: string, reportes_n: int, primero: string, ultimo: string}>
     */
    public function fuentes(string $incidenteUuid): array
    {
        return DB::table('incidente_reporte')
            ->join('reportes', 'reportes.reporte_uuid', '=', 'incidente_reporte.reporte_uuid')
            ->where('incidente_reporte.incidente_uuid', $incidenteUuid)
            ->groupBy('reportes.device_id')
            ->selectRaw('reportes.device_id, COUNT(*) AS reportes_n, MIN(reportes.creado_en) AS primero, MAX(reportes.creado_en) AS ultimo')
            ->orderByDesc('reportes_n')
            ->orderBy('primero')
            ->get()
            ->map(fn (object $fila): array => [
                'device_id' => (string) $fila->device_id,
                'reportes_n' => (int) $fila->reportes_n,
                'primero' => (string) $fila->primero,
                'ultimo' => (string) $fila->ultimo,
            ])
            ->all();
    }

    private function conMayuscula(string $texto): string
    {
        return mb_strtoupper(mb_substr($texto, 0, 1)).mb_substr($texto, 1);
    }

    /**
     * Texto del primer reporte (más antiguo) de cada incidente.
     *
     * @return array<string, string>
     */
    public function resumenes(?string $incidenteUuid = null): array
    {
        $filas = DB::table('incidente_reporte')
            ->join('reportes', 'reportes.reporte_uuid', '=', 'incidente_reporte.reporte_uuid')
            ->when($incidenteUuid !== null, fn ($consulta) => $consulta->where('incidente_reporte.incidente_uuid', $incidenteUuid))
            ->orderByDesc('reportes.creado_en')
            ->orderByDesc('reportes.reporte_uuid')
            ->get(['incidente_reporte.incidente_uuid', 'reportes.texto']);

        $resumenes = [];
        foreach ($filas as $fila) {
            $resumenes[$fila->incidente_uuid] = (string) $fila->texto;
        }

        return $resumenes;
    }
}
