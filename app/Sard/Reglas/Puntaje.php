<?php

namespace App\Sard\Reglas;

/**
 * Puntaje base de un reporte (catalogo-banderas.md), idéntico en A4 y A2.
 *
 * puntaje = Σ peso(banderas marcadas)
 *         + Σ peso(banderas inferidas por palabras, sin repetir)
 *         + bono_personas
 *
 * Una palabra solo suma si su bandera no está marcada ni fue sumada ya por otra palabra.
 */
final class Puntaje
{
    public const BONO_PERSONAS_TOPE = 3;

    /**
     * @return array{
     *     puntaje: int,
     *     senales: array<int, array{origen: string, clave: string, peso: int}>,
     *     criticas: array<int, string>,
     *     banderas_efectivas: int,
     * }
     */
    public static function calcular(int $banderas, ?int $personas, string $texto): array
    {
        $puntaje = 0;
        $senales = [];
        $criticas = [];
        $efectivas = 0;

        foreach (Banderas::CATALOGO as $bit => $bandera) {
            if (($banderas & (1 << $bit)) === 0) {
                continue;
            }

            $efectivas |= 1 << $bit;
            $puntaje += $bandera['peso'];
            $senales[] = ['origen' => 'bandera', 'clave' => $bandera['clave'], 'peso' => $bandera['peso']];
            if ($bandera['critica']) {
                $criticas[] = $bandera['clave'];
            }
        }

        foreach (self::banderasPorPalabras($texto) as $clave) {
            $bit = Banderas::bitDeClave($clave);
            if ($bit === null || ($efectivas & (1 << $bit)) !== 0) {
                continue;
            }

            $bandera = Banderas::CATALOGO[$bit];
            $efectivas |= 1 << $bit;
            $puntaje += $bandera['peso'];
            $senales[] = ['origen' => 'palabra', 'clave' => $clave, 'peso' => $bandera['peso']];
            if ($bandera['critica']) {
                $criticas[] = $clave;
            }
        }

        $bono = self::bonoPersonas($personas);
        if ($bono > 0) {
            $puntaje += $bono;
            $senales[] = ['origen' => 'personas', 'clave' => 'personas', 'peso' => $bono];
        }

        return [
            'puntaje' => $puntaje,
            'senales' => $senales,
            'criticas' => $criticas,
            'banderas_efectivas' => $efectivas,
        ];
    }

    /**
     * Bandas de A2: regla crítica → P1; si no, P1 ≥ 8 · P2 5–7 · P3 2–4 · P4 0–1.
     *
     * @param  array{puntaje: int, criticas: array<int, string>}  $resultado
     */
    public static function prioridad(array $resultado): string
    {
        if ($resultado['criticas'] !== []) {
            return 'P1';
        }

        return match (true) {
            $resultado['puntaje'] >= 8 => 'P1',
            $resultado['puntaje'] >= 5 => 'P2',
            $resultado['puntaje'] >= 2 => 'P3',
            default => 'P4',
        };
    }

    /** bono_personas = min(3, personas − 1) si personas ≥ 2; 0 si ≤ 1 o desconocido. */
    public static function bonoPersonas(?int $personas): int
    {
        if ($personas === null || $personas < 2) {
            return 0;
        }

        return min(self::BONO_PERSONAS_TOPE, $personas - 1);
    }

    /**
     * Banderas inferidas por el diccionario, sin repetir y en el orden del contrato.
     *
     * @return array<int, string>
     */
    public static function banderasPorPalabras(string $texto): array
    {
        $normalizado = Normalizador::texto($texto);
        $palabras = Normalizador::palabras($normalizado);
        if ($palabras === []) {
            return [];
        }

        $mencionaCauce = array_intersect($palabras, Banderas::PALABRAS_CAUCE) !== [];
        $inferidas = [];

        foreach (Banderas::DICCIONARIO as $entrada) {
            if (in_array($entrada['bandera'], $inferidas, true)) {
                continue;
            }

            if (in_array($entrada['patron'], Banderas::REQUIERE_CAUCE, true) && ! $mencionaCauce) {
                continue;
            }

            if (self::coincide($entrada['patron'], $normalizado, $palabras)) {
                $inferidas[] = $entrada['bandera'];
            }
        }

        return $inferidas;
    }

    /** @param  array<int, string>  $palabras */
    private static function coincide(string $patron, string $normalizado, array $palabras): bool
    {
        if (str_contains($patron, ' ')) {
            return str_contains(' '.$normalizado.' ', ' '.$patron.' ');
        }

        if (str_ends_with($patron, '*')) {
            $prefijo = substr($patron, 0, -1);
            foreach ($palabras as $palabra) {
                if (str_starts_with($palabra, $prefijo)) {
                    return true;
                }
            }

            return false;
        }

        return in_array($patron, $palabras, true);
    }
}
