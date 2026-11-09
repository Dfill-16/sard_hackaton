<?php

namespace App\Sard\Proyeccion\Manejadores;

use Illuminate\Support\Facades\DB;

/**
 * Recalcula los campos derivados de un incidente desde sus reportes originales (T-008):
 * tipo (moda), centroide, conteos, prioridad base, confianza y contradicciones.
 *
 * Las contradicciones se conservan; nunca se resuelven solas.
 */
final class RecalculoIncidente
{
    public const DIFERENCIA_PERSONAS_CONTRADICCION = 5;

    public function recalcular(string $incidenteUuid, string $momento): void
    {
        $miembros = DB::table('incidente_reporte')
            ->join('reportes', 'reportes.reporte_uuid', '=', 'incidente_reporte.reporte_uuid')
            ->leftJoin('triajes', 'triajes.reporte_uuid', '=', 'incidente_reporte.reporte_uuid')
            ->where('incidente_reporte.incidente_uuid', $incidenteUuid)
            ->orderBy('reportes.creado_en')
            ->orderBy('reportes.reporte_uuid')
            ->get([
                'reportes.reporte_uuid', 'reportes.device_id', 'reportes.tipo', 'reportes.personas',
                'reportes.lat', 'reportes.lon', 'triajes.prioridad', 'triajes.incertidumbre',
            ]);

        if ($miembros->isEmpty()) {
            // Incidente sin reportes (perdió todos sus miembros tras la unión): no se muestra.
            DB::table('incidentes')->where('uuid', $incidenteUuid)->delete();

            return;
        }

        $conUbicacion = $miembros->filter(fn (object $m): bool => $m->lat !== null && $m->lon !== null);
        $dispositivos = $miembros->pluck('device_id')->unique()->count();

        DB::table('incidentes')->where('uuid', $incidenteUuid)->update([
            'tipo' => $this->moda($miembros->pluck('tipo')->map(fn ($tipo): int => (int) $tipo)->all()),
            'lat' => $conUbicacion->isEmpty() ? null : round($conUbicacion->avg('lat'), 7),
            'lon' => $conUbicacion->isEmpty() ? null : round($conUbicacion->avg('lon'), 7),
            'sin_ubicacion' => $conUbicacion->isEmpty(),
            'reportes_n' => $miembros->count(),
            'dispositivos_n' => $dispositivos,
            'prioridad_base' => $miembros->pluck('prioridad')->filter()->min() ?? 'P4',
            'confianza' => $this->confianza($miembros->pluck('incertidumbre')->all(), $dispositivos),
            'contradicciones' => Proyeccion::json($this->contradicciones($miembros->all())),
            'actualizado_en' => $momento,
        ]);
    }

    /**
     * confianza = round((1 − promedio(incertidumbre)) · min(1, 0.4 + 0.15·dispositivos_n), 2)
     *
     * @param  array<int, float|null>  $incertidumbres
     */
    public function confianza(array $incertidumbres, int $dispositivos): float
    {
        $conocidas = array_values(array_filter($incertidumbres, fn ($valor): bool => $valor !== null));
        $promedio = $conocidas === [] ? 1.0 : array_sum($conocidas) / count($conocidas);
        $fuentes = min(1.0, 0.4 + 0.15 * $dispositivos);

        return round((1 - $promedio) * $fuentes, 2);
    }

    /**
     * Tipos distintos entre miembros o diferencia de personas > 5.
     *
     * @param  array<int, object>  $miembros  ordenados por creado_en
     * @return array<int, array{tipo: string, reportes: array<int, string>, detalle: string}>
     */
    public function contradicciones(array $miembros): array
    {
        $contradicciones = [];
        $primero = $miembros[0];
        $tiposVistos = [(int) $primero->tipo => true];

        foreach ($miembros as $miembro) {
            if (! isset($tiposVistos[(int) $miembro->tipo])) {
                $tiposVistos[(int) $miembro->tipo] = true;
                $contradicciones[] = [
                    'tipo' => 'tipo',
                    'reportes' => [$primero->reporte_uuid, $miembro->reporte_uuid],
                    'detalle' => "tipo {$primero->tipo} frente a tipo {$miembro->tipo}",
                ];
            }
        }

        $conPersonas = array_values(array_filter($miembros, fn (object $m): bool => $m->personas !== null));
        if (count($conPersonas) >= 2) {
            usort($conPersonas, fn (object $a, object $b): int => $a->personas <=> $b->personas);
            $menor = $conPersonas[0];
            $mayor = $conPersonas[count($conPersonas) - 1];
            if ($mayor->personas - $menor->personas > self::DIFERENCIA_PERSONAS_CONTRADICCION) {
                $contradicciones[] = [
                    'tipo' => 'personas',
                    'reportes' => [$menor->reporte_uuid, $mayor->reporte_uuid],
                    'detalle' => "{$menor->personas} frente a {$mayor->personas} personas",
                ];
            }
        }

        return $contradicciones;
    }

    /**
     * Valor más frecuente; ante empate, el que apareció primero.
     *
     * @param  array<int, int>  $valores
     */
    private function moda(array $valores): int
    {
        $conteos = array_count_values($valores);
        $maximo = max($conteos);

        foreach ($valores as $valor) {
            if ($conteos[$valor] === $maximo) {
                return $valor;
            }
        }

        return 0;
    }
}
