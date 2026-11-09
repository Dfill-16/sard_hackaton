<?php

namespace App\Agents\A2;

use App\Sard\Reglas\Puntaje;

/**
 * A2 · Triaje por reglas (líder, sin LLM): asigna P1-P4 con señales e incertidumbre.
 *
 * Orden: Puntaje::calcular → regla crítica (P1) → bandas. Nada se descarta por prioridad.
 */
final class Triaje
{
    public const MODO_REGLAS = 'reglas';

    /** Peso de cada motivo en la incertidumbre (T-007, paso 3). */
    private const MOTIVOS = [
        'sin_ubicacion' => [0.3, 'sin ubicación (no se estima)'],
        'texto_vacio' => [0.2, 'sin texto descriptivo'],
        'tipo_otro' => [0.2, 'tipo «otro»'],
        'truncado' => [0.2, 'texto truncado'],
        'personas_desconocidas' => [0.1, 'número de personas desconocido'],
    ];

    /**
     * @param  array<string, mixed>  $normalizado  payload de ReporteNormalizado
     * @return array{
     *     reporte_uuid: string, prioridad: string, puntaje: int,
     *     senales: array<int, array{origen: string, clave: string, peso: int}>,
     *     incertidumbre: float, motivos_incertidumbre: array<int, string>, modo: string,
     * } payload de TriajeAsignado
     */
    public function evaluar(array $normalizado): array
    {
        $resultado = Puntaje::calcular(
            (int) $normalizado['banderas'],
            $normalizado['personas'] === null ? null : (int) $normalizado['personas'],
            (string) $normalizado['texto'],
        );

        $senales = $resultado['senales'];
        foreach ($resultado['criticas'] as $clave) {
            $senales[] = ['origen' => 'critica', 'clave' => $clave, 'peso' => 0];
        }

        [$incertidumbre, $motivos] = $this->incertidumbre($normalizado);

        return [
            'reporte_uuid' => $normalizado['reporte_uuid'],
            'prioridad' => Puntaje::prioridad($resultado),
            'puntaje' => $resultado['puntaje'],
            'senales' => $senales,
            'incertidumbre' => $incertidumbre,
            'motivos_incertidumbre' => $motivos,
            'modo' => self::MODO_REGLAS,
        ];
    }

    /**
     * incertidumbre = min(1, 0.3·sin_ubicacion + 0.2·texto vacío + 0.2·tipo «otro»
     *                       + 0.2·truncado + 0.1·personas null)
     *
     * @param  array<string, mixed>  $normalizado
     * @return array{0: float, 1: array<int, string>}
     */
    private function incertidumbre(array $normalizado): array
    {
        $presentes = [
            'sin_ubicacion' => (bool) $normalizado['sin_ubicacion'],
            'texto_vacio' => trim((string) $normalizado['texto']) === '',
            'tipo_otro' => (int) $normalizado['tipo'] === 0,
            'truncado' => (bool) $normalizado['truncado'],
            'personas_desconocidas' => $normalizado['personas'] === null,
        ];

        $suma = 0.0;
        $motivos = [];
        foreach ($presentes as $clave => $presente) {
            if ($presente) {
                $suma += self::MOTIVOS[$clave][0];
                $motivos[] = self::MOTIVOS[$clave][1];
            }
        }

        return [round(min(1.0, $suma), 2), $motivos];
    }
}
