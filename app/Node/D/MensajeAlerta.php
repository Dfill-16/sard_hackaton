<?php

namespace App\Node\D;

use App\Sard\Support\Tiempo;

/**
 * Cuerpo JSON compacto de la ALERTA que viaja por el canal (T-015). Debe pesar ≤ CANAL_MTU_B (200 B).
 *
 * Mapeo de claves cortas (contrato api.md → transporte):
 * | Contrato        | Clave | Formato en el canal                         |
 * |-----------------|-------|---------------------------------------------|
 * | alerta_uuid     | a     | UUID en hex sin guiones (32 caracteres)     |
 * | incidente_uuid  | i     | UUID en hex sin guiones (32 caracteres)     |
 * | unidad_id       | u     | texto, p. ej. "U-06"                        |
 * | texto           | t     | texto; se acorta (con «…») si no cabe       |
 * | enviada_en      | e     | milisegundos Unix                           |
 */
final class MensajeAlerta
{
    public const ELIPSIS = '…';

    /** @param  array{alerta_uuid: string, incidente_uuid: string, unidad_id: string, texto: string, enviada_en: string}  $alerta */
    public static function codificar(array $alerta, int $mtu = 200): string
    {
        $base = [
            'a' => str_replace('-', '', $alerta['alerta_uuid']),
            'i' => str_replace('-', '', $alerta['incidente_uuid']),
            'u' => $alerta['unidad_id'],
            't' => $alerta['texto'],
            'e' => Tiempo::milisegundos(Tiempo::desdeIso($alerta['enviada_en'])),
        ];

        $codificar = fn (string $texto): string => (string) json_encode([...$base, 't' => $texto], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        // Se recorta el texto, carácter a carácter, hasta que el mensaje quepa en la MTU.
        for ($largo = mb_strlen($alerta['texto']); $largo > 0; $largo--) {
            $texto = $largo === mb_strlen($alerta['texto'])
                ? $alerta['texto']
                : mb_substr($alerta['texto'], 0, $largo).self::ELIPSIS;
            $json = $codificar($texto);
            if (strlen($json) <= $mtu) {
                return $json;
            }
        }

        return $codificar('');
    }

    /**
     * @return array{alerta_uuid: string, incidente_uuid: string, unidad_id: string, texto: string, enviada_en: string}|null
     */
    public static function decodificar(string $cuerpo): ?array
    {
        $datos = json_decode($cuerpo, true);
        if (! is_array($datos) || ! isset($datos['a'], $datos['i'], $datos['u'], $datos['e'])
            || ! preg_match('/^[0-9a-f]{32}$/', (string) $datos['a']) || ! preg_match('/^[0-9a-f]{32}$/', (string) $datos['i'])) {
            return null;
        }

        return [
            'alerta_uuid' => self::conGuiones($datos['a']),
            'incidente_uuid' => self::conGuiones($datos['i']),
            'unidad_id' => (string) $datos['u'],
            'texto' => (string) ($datos['t'] ?? ''),
            'enviada_en' => Tiempo::iso(Tiempo::desdeMilisegundos((int) $datos['e'])),
        ];
    }

    private static function conGuiones(string $hex): string
    {
        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20, 12);
    }
}
