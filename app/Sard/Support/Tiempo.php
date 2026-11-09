<?php

namespace App\Sard\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Fechas del sistema: ISO 8601 UTC con milisegundos (eventos.md), comparables como texto.
 *
 * Usa el reloj de Carbon para que las pruebas puedan fijar la hora con Carbon::setTestNow().
 */
final class Tiempo
{
    public const FORMATO = 'Y-m-d\TH:i:s.v\Z';

    public static function ahora(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC');
    }

    public static function iso(?CarbonInterface $momento = null): string
    {
        return ($momento ?? self::ahora())->setTimezone('UTC')->format(self::FORMATO);
    }

    public static function desdeIso(string $iso): CarbonImmutable
    {
        return CarbonImmutable::parse($iso)->setTimezone('UTC');
    }

    public static function desdeMilisegundos(int $milisegundos): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestampMs($milisegundos, 'UTC');
    }

    public static function milisegundos(?CarbonInterface $momento = null): int
    {
        return (int) ($momento ?? self::ahora())->getTimestampMs();
    }
}
