<?php

namespace App\Sard\Proyeccion\Manejadores;

use App\Agents\A6\Coordinacion;
use Illuminate\Support\Facades\DB;

/**
 * DecisionHumana → `decisiones` y estado del incidente.
 *
 * Dos decisiones sobre la misma recomendación se conservan ambas y el incidente queda
 * «en_conflicto» (eventos.md): nadie las resuelve automáticamente.
 */
final class DecisionHumanaManejador
{
    /** @param  array<string, mixed>  $evento */
    public function __invoke(array $evento): void
    {
        $payload = Proyeccion::payload($evento);

        // Clics repetidos que ya quedaron en el log (antes de la regla de idempotencia): el evento se
        // conserva en el log, pero no cuenta como otra decisión ni deja el incidente en conflicto.
        $repetida = DB::table('decisiones')
            ->where('recomendacion_uuid', $payload['recomendacion_uuid'])
            ->where('uuid', '!=', $payload['decision_uuid'])
            ->get()
            ->contains(fn (object $previa): bool => Coordinacion::esLaMismaDecision($previa, $payload['accion'], $payload['operador'], $evento['nodo_origen']));

        if (! $repetida) {
            $this->registrar($evento, $payload);
        }

        $this->actualizarEstado($payload['incidente_uuid'], $evento['creado_en']);
    }

    /**
     * @param  array<string, mixed>  $evento
     * @param  array<string, mixed>  $payload
     */
    private function registrar(array $evento, array $payload): void
    {
        DB::table('decisiones')->insertOrIgnore([
            'uuid' => $payload['decision_uuid'],
            'evento_uuid' => $evento['uuid'],
            'recomendacion_uuid' => $payload['recomendacion_uuid'],
            'incidente_uuid' => $payload['incidente_uuid'],
            'accion' => $payload['accion'],
            'motivo' => $payload['motivo'] ?? null,
            'operador' => $payload['operador'],
            'nodo' => $evento['nodo_origen'],
            'creado_en' => $evento['creado_en'],
        ]);
    }

    private function actualizarEstado(string $incidenteUuid, string $creadoEn): void
    {
        $enConflicto = DB::table('decisiones')
            ->where('incidente_uuid', $incidenteUuid)
            ->groupBy('recomendacion_uuid')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        $aprobada = DB::table('decisiones')
            ->where('incidente_uuid', $incidenteUuid)
            ->where('accion', 'aprobar')
            ->exists();

        DB::table('incidentes')->where('uuid', $incidenteUuid)->update([
            'estado' => $enConflicto ? 'en_conflicto' : ($aprobada ? 'en_atencion' : 'abierto'),
            'actualizado_en' => $creadoEn,
        ]);
    }
}
