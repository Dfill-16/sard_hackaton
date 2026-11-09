<?php

namespace App\Sard\Reglas;

use App\Agents\A3\Vecindad;
use App\Agents\A6\Coordinacion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Brechas de cobertura (T-017, [DECISIÓN-TÉCNICA]): un incidente P1-P2 con ubicación que no tiene
 * una unidad «disponible» del tipo requerido (regla de A6) a ≤ 3 km.
 *
 * Solo informa: no cambia las recomendaciones de A6. Un incidente sin ubicación no es brecha;
 * va en su propia lista del tablero.
 */
final class Brechas
{
    public const RADIO_COBERTURA_M = 3000;

    public function __construct(private Coordinacion $coordinacion) {}

    /**
     * @param  Collection<int, array<string, mixed>>  $tarjetas  incidentes ya presentados (con prioridad efectiva)
     * @return array<int, array{incidente_uuid: string, prioridad: string, tipo_nombre: string, unidad_requerida: string, unidad_mas_cercana: array{id: string, nombre: string, distancia_m: int}|null}>
     */
    public function calcular(Collection $tarjetas): array
    {
        $brechas = [];

        foreach ($tarjetas as $tarjeta) {
            if (! in_array($tarjeta['prioridad'], ['P1', 'P2'], true) || $tarjeta['lat'] === null) {
                continue;
            }

            [$tipoUnidad] = $this->coordinacion->unidadRequerida((object) ['uuid' => $tarjeta['uuid'], 'tipo' => $tarjeta['tipo']]);
            if ($tipoUnidad === null) {
                continue;
            }

            $cercana = $this->coordinacion->unidadMasCercana($tipoUnidad, $tarjeta['lat'], $tarjeta['lon']);
            if ($cercana !== null && $cercana['distancia_m'] <= self::RADIO_COBERTURA_M) {
                continue;
            }

            $brechas[] = [
                'incidente_uuid' => $tarjeta['uuid'],
                'prioridad' => $tarjeta['prioridad'],
                'tipo_nombre' => $tarjeta['tipo_nombre'],
                'unidad_requerida' => $tipoUnidad,
                'unidad_mas_cercana' => $cercana,
            ];
        }

        return $brechas;
    }

    /**
     * Unidades con su estado actual: ocupada (semilla), asignada (alerta aprobada) o disponible.
     *
     * @return array<int, array{id: string, tipo: string, nombre: string, base: string, lat: float, lon: float, estado: string}>
     */
    public function unidades(): array
    {
        $asignadas = DB::table('unidades_estado')->where('estado', 'asignada')->pluck('unidad_id')->flip();

        return DB::table('unidades')->orderBy('id')->get()->map(fn (object $u): array => [
            'id' => $u->id,
            'tipo' => $u->tipo,
            'nombre' => $u->nombre,
            'base' => $u->base,
            'lat' => (float) $u->lat,
            'lon' => (float) $u->lon,
            'estado' => $u->estado_inicial !== 'disponible' ? 'ocupada' : ($asignadas->has($u->id) ? 'asignada' : 'disponible'),
        ])->all();
    }

    /**
     * Recursos de semilla (albergues, acopios, puntos de sangre).
     *
     * @return array<int, array{id: string, tipo: string, nombre: string, zona: string, lat: float, lon: float}>
     */
    public function recursos(): array
    {
        return DB::table('recursos')->orderBy('id')->get()->map(fn (object $r): array => [
            'id' => $r->id,
            'tipo' => $r->tipo,
            'nombre' => $r->nombre,
            'zona' => $r->zona,
            'lat' => (float) $r->lat,
            'lon' => (float) $r->lon,
        ])->all();
    }

    /**
     * Albergue más cercano a un punto (solo información para el detalle).
     *
     * @return array{id: string, nombre: string, zona: string, distancia_m: int}|null
     */
    public function albergueMasCercano(?float $lat, ?float $lon): ?array
    {
        if ($lat === null || $lon === null) {
            return null;
        }

        return collect($this->recursos())
            ->where('tipo', 'Albergue')
            ->map(fn (array $r): array => [
                'id' => $r['id'],
                'nombre' => $r['nombre'],
                'zona' => $r['zona'],
                'distancia_m' => (int) round(Vecindad::haversine($lat, $lon, $r['lat'], $r['lon'])),
            ])
            ->sortBy('distancia_m')
            ->first();
    }
}
