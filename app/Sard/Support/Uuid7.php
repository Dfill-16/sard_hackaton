<?php

namespace App\Sard\Support;

use InvalidArgumentException;

/**
 * Generador de identificadores UUIDv7.
 *
 * Los primeros 48 bits codifican el timestamp en milisegundos (epoch Unix),
 * seguidos de 4 bits de versión (0111), 2 bits de variante RFC 4122 (10xx)
 * y 74 bits de aleaduría criptográfica.
 */
final class Uuid7
{
    public const VERSION = 0x70;

    public const VARIANT_MASK = 0x80;

    /**
     * Genera un UUIDv7 canónico (minúsculas con guiones).
     */
    public static function generar(): string
    {
        $tsHex = str_pad(dechex(self::milisegundosAhora()), 12, '0', STR_PAD_LEFT);

        $bytes = random_bytes(10);
        // Byte 0 (offset 12-13 del hex sin guiones): versión 7 → 0111
        $bytes[0] = chr((ord($bytes[0]) & 0x0F) | self::VERSION);
        // Byte 2 (offset 16-17 del hex sin guiones): variante 10xx
        $bytes[2] = chr((ord($bytes[2]) & 0x3F) | self::VARIANT_MASK);

        $hex = $tsHex.bin2hex($bytes);

        return self::formatear($hex);
    }

    /**
     * Extrae el timestamp en milisegundos codificado en un UUIDv7.
     */
    public static function milisegundos(string $uuid): int
    {
        $hex = str_replace('-', '', strtolower($uuid));
        if (strlen($hex) !== 32) {
            throw new InvalidArgumentException('UUID inválido: '.$uuid);
        }

        return hexdec(substr($hex, 0, 12));
    }

    private static function milisegundosAhora(): int
    {
        return (int) (microtime(true) * 1000);
    }

    private static function formatear(string $hex): string
    {
        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4)
            .'-'.substr($hex, 16, 4).'-'.substr($hex, 20, 12);
    }
}
