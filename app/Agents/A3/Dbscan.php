<?php

namespace App\Agents\A3;

/**
 * DBSCAN por lotes con haversine, sin librería de ML (ADR-001).
 *
 * Usa el mismo criterio de vecindad que la asignación incremental; sirve para pruebas
 * y para `sard:a3-verificar`. Un punto sin vecinos suficientes queda como grupo propio.
 */
final class Dbscan
{
    /**
     * @param  array<int, array{uuid: string, lat: float, lon: float, tipo: int, banderas: int, ms: int}>  $puntos
     * @return array<int, array<int, string>> grupos de uuids, cada uno ordenado
     */
    public function agrupar(array $puntos, ?int $minimo = null): array
    {
        $minimo ??= (int) config('sard.dbscan_min');
        $total = count($puntos);
        $vecinos = [];

        for ($i = 0; $i < $total; $i++) {
            $vecinos[$i] = [];
            for ($j = 0; $j < $total; $j++) {
                if ($i !== $j && Vecindad::sonVecinos($puntos[$i], $puntos[$j])) {
                    $vecinos[$i][] = $j;
                }
            }
        }

        $grupoDe = array_fill(0, $total, null);
        $grupos = [];

        for ($i = 0; $i < $total; $i++) {
            if ($grupoDe[$i] !== null) {
                continue;
            }

            $grupo = count($grupos);
            $grupos[$grupo] = [];
            $grupoDe[$i] = $grupo;
            $grupos[$grupo][] = $puntos[$i]['uuid'];

            if (count($vecinos[$i]) + 1 < $minimo) {
                continue;
            }

            $pendientes = $vecinos[$i];
            while ($pendientes !== []) {
                $j = array_shift($pendientes);
                if ($grupoDe[$j] !== null) {
                    continue;
                }

                $grupoDe[$j] = $grupo;
                $grupos[$grupo][] = $puntos[$j]['uuid'];

                if (count($vecinos[$j]) + 1 >= $minimo) {
                    array_push($pendientes, ...$vecinos[$j]);
                }
            }
        }

        foreach ($grupos as &$miembros) {
            sort($miembros, SORT_STRING);
        }

        return array_values($grupos);
    }
}
