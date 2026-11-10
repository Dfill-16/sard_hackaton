<?php

namespace App\Sard\Firma;

use RuntimeException;

/**
 * JSON canónico sobre el que se calcula la firma de lotes (T-019 · REQ-027).
 *
 * Es el mismo canónico de T-003 (EventLog): ksort recursivo con
 * JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES. Se replica aquí porque el de
 * EventLog es privado y la ficha de T-019 no permite tocarlo; una prueba afirma
 * la equivalencia byte a byte.
 */
final class Canonico
{
    /** Huella que se firma: sha256 del JSON canónico del arreglo de eventos. */
    public static function huella(array $eventos): string
    {
        return hash('sha256', self::json($eventos));
    }

    /** JSON canónico del arreglo dado (misma ordenación que T-003). */
    public static function json(array $arreglo): string
    {
        $json = json_encode(self::ordenar($arreglo), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('No fue posible canonicalizar el arreglo de eventos.');
        }

        return $json;
    }

    private static function ordenar(array $arreglo): array
    {
        $resultado = [];
        ksort($arreglo);
        foreach ($arreglo as $clave => $valor) {
            $resultado[$clave] = is_array($valor) ? self::ordenar($valor) : $valor;
        }

        return $resultado;
    }
}
