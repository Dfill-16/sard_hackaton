<?php

namespace App\Sard\Reglas;

/**
 * Normaliza texto libre para el diccionario de palabras (catalogo-banderas.md):
 * minúsculas, sin tildes y signos convertidos en espacio.
 */
final class Normalizador
{
    private const SIN_TILDES = [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a',
        'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
        'ñ' => 'n', 'ç' => 'c',
    ];

    public static function texto(string $texto): string
    {
        $limpio = mb_scrub($texto, 'UTF-8');
        $limpio = mb_strtolower($limpio, 'UTF-8');
        $limpio = strtr($limpio, self::SIN_TILDES);
        $limpio = preg_replace('/[^a-z0-9]+/', ' ', $limpio) ?? '';

        return trim($limpio);
    }

    /**
     * Palabras del texto ya normalizado.
     *
     * @return array<int, string>
     */
    public static function palabras(string $textoNormalizado): array
    {
        if ($textoNormalizado === '') {
            return [];
        }

        return explode(' ', $textoNormalizado);
    }
}
