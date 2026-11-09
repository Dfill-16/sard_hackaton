<?php

namespace App\Http\Controllers\Tablero;

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

    /**
     * Todos los incidentes con su prioridad efectiva, ordenados por prioridad y antigüedad.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function incidentes(CarbonInterface $ahora): Collection
    {
        $incidentes = DB::table('incidentes')->get();
        $resumenes = $this->resumenes();
        $conDecision = DB::table('decisiones')->distinct()->pluck('incidente_uuid')->flip();
        $conRecomendacionPendiente = DB::table('recomendaciones')
            ->whereNotIn('uuid', DB::table('decisiones')->select('recomendacion_uuid'))
            ->distinct()
            ->pluck('incidente_uuid')
            ->flip();

        return $incidentes
            ->map(fn (object $incidente): array => [
                ...$this->tarjeta($incidente, $ahora, $resumenes[$incidente->uuid] ?? '', $conDecision->has($incidente->uuid)),
                'recomendacion_pendiente' => $conRecomendacionPendiente->has($incidente->uuid),
            ])
            ->sort(fn (array $a, array $b): int => [PrioridadIncidente::nivel($a['prioridad']), $a['creado_en'], $a['uuid']]
                <=> [PrioridadIncidente::nivel($b['prioridad']), $b['creado_en'], $b['uuid']])
            ->values();
    }

    /** @return array<string, mixed> */
    public function tarjeta(object $incidente, CarbonInterface $ahora, string $resumen, bool $tieneDecision = false): array
    {
        $prioridad = PrioridadIncidente::efectiva($incidente, $ahora, $tieneDecision);

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
            'confianza' => (float) $incidente->confianza,
            'contradicciones_n' => count(json_decode($incidente->contradicciones, true) ?? []),
            'sin_ubicacion' => (bool) $incidente->sin_ubicacion,
            'lat' => $incidente->lat === null ? null : (float) $incidente->lat,
            'lon' => $incidente->lon === null ? null : (float) $incidente->lon,
            'resumen' => mb_substr($resumen, 0, self::LARGO_RESUMEN),
            'creado_en' => $incidente->creado_en,
            'estado' => $incidente->estado,
        ];
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
