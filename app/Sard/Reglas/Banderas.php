<?php

namespace App\Sard\Reglas;

/**
 * Catálogo de banderas, pesos y diccionario de palabras (contrato catalogo-banderas.md, aprobado 4/4).
 *
 * Bits 0..14 son banderas; el bit 15 está reservado. Este catálogo lo usan por igual
 * A4 (Nodo A) y A2 (líder), para que ambos calculen exactamente el mismo puntaje.
 */
final class Banderas
{
    public const BIT_RESERVADO = 15;

    /**
     * Bit → [clave, etiqueta, peso, crítica].
     *
     * @var array<int, array{clave: string, etiqueta: string, peso: int, critica: bool}>
     */
    public const CATALOGO = [
        0 => ['clave' => 'atrapada', 'etiqueta' => 'persona atrapada', 'peso' => 5, 'critica' => true],
        1 => ['clave' => 'respiratoria', 'etiqueta' => 'dificultad respiratoria', 'peso' => 5, 'critica' => true],
        2 => ['clave' => 'herido', 'etiqueta' => 'herido/urgencia médica', 'peso' => 4, 'critica' => false],
        3 => ['clave' => 'incendio', 'etiqueta' => 'incendio', 'peso' => 4, 'critica' => false],
        4 => ['clave' => 'explosion', 'etiqueta' => 'explosión', 'peso' => 5, 'critica' => true],
        5 => ['clave' => 'derrumbe', 'etiqueta' => 'derrumbe/colapso', 'peso' => 4, 'critica' => false],
        6 => ['clave' => 'deslizamiento', 'etiqueta' => 'deslizamiento', 'peso' => 4, 'critica' => false],
        7 => ['clave' => 'avalancha', 'etiqueta' => 'avalancha/flujo de lodo', 'peso' => 4, 'critica' => false],
        8 => ['clave' => 'inundacion', 'etiqueta' => 'inundación/creciente de río', 'peso' => 3, 'critica' => false],
        9 => ['clave' => 'grieta', 'etiqueta' => 'grieta', 'peso' => 2, 'critica' => false],
        10 => ['clave' => 'via', 'etiqueta' => 'vía bloqueada', 'peso' => 1, 'critica' => false],
        11 => ['clave' => 'gas', 'etiqueta' => 'fuga de gas', 'peso' => 4, 'critica' => false],
        12 => ['clave' => 'cables', 'etiqueta' => 'cables caídos', 'peso' => 2, 'critica' => false],
        13 => ['clave' => 'vulnerable', 'etiqueta' => 'persona vulnerable', 'peso' => 2, 'critica' => false],
        14 => ['clave' => 'otro', 'etiqueta' => 'otro', 'peso' => 1, 'critica' => false],
    ];

    /**
     * Diccionario sobre texto normalizado. `*` al final = prefijo de palabra;
     * con espacio = frase exacta; sin nada = palabra exacta.
     * Orden = orden del contrato, que define el orden de las señales.
     *
     * @var array<int, array{patron: string, bandera: string}>
     */
    public const DICCIONARIO = [
        ['patron' => 'atrapad*', 'bandera' => 'atrapada'],
        ['patron' => 'no respira', 'bandera' => 'respiratoria'],
        ['patron' => 'inconsciente', 'bandera' => 'respiratoria'],
        ['patron' => 'herid*', 'bandera' => 'herido'],
        ['patron' => 'sangr*', 'bandera' => 'herido'],
        ['patron' => 'incendi*', 'bandera' => 'incendio'],
        ['patron' => 'fuego', 'bandera' => 'incendio'],
        ['patron' => 'humo', 'bandera' => 'incendio'],
        ['patron' => 'explos*', 'bandera' => 'explosion'],
        ['patron' => 'bomba', 'bandera' => 'explosion'],
        ['patron' => 'derrumb*', 'bandera' => 'derrumbe'],
        ['patron' => 'colaps*', 'bandera' => 'derrumbe'],
        ['patron' => 'desliz*', 'bandera' => 'deslizamiento'],
        ['patron' => 'avalanch*', 'bandera' => 'avalancha'],
        ['patron' => 'lodo', 'bandera' => 'avalancha'],
        ['patron' => 'inund*', 'bandera' => 'inundacion'],
        ['patron' => 'creci*', 'bandera' => 'inundacion'],
        ['patron' => 'gas', 'bandera' => 'gas'],
    ];

    /** Patrones que solo cuentan si el texto también menciona un río o quebrada. */
    public const REQUIERE_CAUCE = ['creci*'];

    public const PALABRAS_CAUCE = ['rio', 'quebrada'];

    /**
     * Nombres de los tipos del payload (payload-reporte.md).
     *
     * @var array<int, string>
     */
    public const TIPOS = [
        0 => 'otro',
        1 => 'estructura/colapso',
        2 => 'incendio/explosión',
        3 => 'médico',
        4 => 'deslizamiento/avalancha',
        5 => 'inundación',
        6 => 'vía bloqueada',
        7 => 'servicios (gas/cables)',
    ];

    public static function bitDeClave(string $clave): ?int
    {
        foreach (self::CATALOGO as $bit => $bandera) {
            if ($bandera['clave'] === $clave) {
                return $bit;
            }
        }

        return null;
    }

    /**
     * Claves de las banderas marcadas en una máscara de bits (bit 15 se ignora).
     *
     * @return array<int, string>
     */
    public static function claves(int $mascara): array
    {
        $claves = [];
        foreach (self::CATALOGO as $bit => $bandera) {
            if (($mascara & (1 << $bit)) !== 0) {
                $claves[] = $bandera['clave'];
            }
        }

        return $claves;
    }

    public static function nombreTipo(int $tipo): string
    {
        return self::TIPOS[$tipo] ?? self::TIPOS[0];
    }
}
