<?php

namespace App\Sard\Proyeccion\Manejadores;

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

        $enConflicto = DB::table('decisiones')
            ->where('incidente_uuid', $payload['incidente_uuid'])
            ->groupBy('recomendacion_uuid')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        $aprobada = DB::table('decisiones')
            ->where('incidente_uuid', $payload['incidente_uuid'])
            ->where('accion', 'aprobar')
            ->exists();

        DB::table('incidentes')->where('uuid', $payload['incidente_uuid'])->update([
            'estado' => $enConflicto ? 'en_conflicto' : ($aprobada ? 'en_atencion' : 'abierto'),
            'actualizado_en' => $evento['creado_en'],
        ]);
    }
}
