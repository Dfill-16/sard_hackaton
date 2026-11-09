<?php

namespace App\Channel;

/**
 * Aleatoriedad reproducible del canal (T-006, REQ-026).
 *
 * u(k) = hexdec(substr(sha1(SEMILLA|seq|k), 0, 8)) / 0xFFFFFFFF
 * Misma semilla y mismo seq → mismo valor, en cualquier corrida.
 */
final class Aleatorio
{
    public const PERDIDA = 1;

    public const LATENCIA = 2;

    public const DESORDEN = 3;

    public const RETRASO_DESORDEN = 4;

    public static function u(int $semilla, int $seq, int $k): float
    {
        return hexdec(substr(sha1($semilla.'|'.$seq.'|'.$k), 0, 8)) / 0xFFFFFFFF;
    }
}
