<?php

namespace App\Sard\Reglas;

use App\Sard\Support\Tiempo;
use Carbon\CarbonInterface;

/**
 * Prioridad efectiva de un incidente en el líder (T-008, paso 7).
 *
 * - Base = la mejor prioridad de sus reportes (prioridad_base).
 * - Masivo → sube un nivel (P2 → P1).
 * - Envejecimiento B: +1 nivel cada B_ENVEJ_MIN sin DecisionHumana; por envejecimiento no pasa de B_ENVEJ_TOPE (P2).
 * - Posible rumor (≥ 5 reportes de 1 dispositivo) es solo una marca: no eleva.
 */
final class PrioridadIncidente
{
    public const UMBRAL_RUMOR_REPORTES = 5;

    /**
     * @param  object|array<string, mixed>  $incidente  fila de `incidentes`
     * @return array{prioridad: string, envejecido: bool}
     */
    public static function efectiva(object|array $incidente, CarbonInterface $ahora, bool $tieneDecision = false): array
    {
        $incidente = (object) $incidente;

        $nivel = self::nivel((string) $incidente->prioridad_base);
        if ((bool) $incidente->masivo) {
            $nivel = max(1, $nivel - 1);
        }

        $envejecido = false;
        if (! $tieneDecision) {
            $minutos = max(0, Tiempo::desdeIso($incidente->creado_en)->diffInSeconds($ahora)) / 60;
            $pasos = (int) floor($minutos / max(1, (int) config('sard.b_envej_min')));
            $tope = min($nivel, self::nivel((string) config('sard.b_envej_tope', 'P2')));
            $envejecidoNivel = max($tope, $nivel - $pasos);
            $envejecido = $envejecidoNivel < $nivel;
            $nivel = $envejecidoNivel;
        }

        return ['prioridad' => 'P'.$nivel, 'envejecido' => $envejecido];
    }

    /** @param  object|array<string, mixed>  $incidente */
    public static function posibleRumor(object|array $incidente): bool
    {
        $incidente = (object) $incidente;

        return (int) $incidente->reportes_n >= self::UMBRAL_RUMOR_REPORTES && (int) $incidente->dispositivos_n === 1;
    }

    public static function nivel(string $prioridad): int
    {
        $nivel = (int) ltrim(strtoupper($prioridad), 'P');

        return min(4, max(1, $nivel === 0 ? 4 : $nivel));
    }
}
