<?php

namespace App\Sard\Proyeccion\Manejadores;

use App\Sard\Reglas\PrioridadIncidente;
use Illuminate\Support\Facades\DB;

/**
 * RecomendacionPropuesta → `recomendaciones`.
 *
 * Guarda con qué prioridad y estado masivo se propuso, para que A6 sepa si debe proponer otra.
 * Dos recomendaciones con la misma (incidente, versión) tras la unión: gana el uuid menor.
 */
final class RecomendacionPropuestaManejador
{
    /** @param  array<string, mixed>  $evento */
    public function __invoke(array $evento): void
    {
        $payload = Proyeccion::payload($evento);

        $existente = DB::table('recomendaciones')
            ->where('incidente_uuid', $payload['incidente_uuid'])
            ->where('version', $payload['version'])
            ->first();

        if ($existente !== null) {
            if (strcmp($existente->uuid, $payload['recomendacion_uuid']) <= 0) {
                return;
            }
            DB::table('recomendaciones')->where('uuid', $existente->uuid)->delete();
        }

        $incidente = DB::table('incidentes')->where('uuid', $payload['incidente_uuid'])->first();

        DB::table('recomendaciones')->insert([
            'uuid' => $payload['recomendacion_uuid'],
            'evento_uuid' => $evento['uuid'],
            'incidente_uuid' => $payload['incidente_uuid'],
            'version' => $payload['version'],
            'unidad_id' => $payload['unidad_id'],
            'accion' => $payload['accion'],
            'justificacion' => $payload['justificacion'],
            'pendientes' => Proyeccion::json($payload['pendientes'] ?? []),
            'distancia_m' => $payload['distancia_m'],
            'modo' => $payload['modo'],
            'prioridad_al_proponer' => $incidente === null
                ? 'P4'
                : PrioridadIncidente::efectiva($incidente, now(), tieneDecision: true)['prioridad'],
            'masivo_al_proponer' => (bool) ($incidente->masivo ?? false),
            'creado_en' => $evento['creado_en'],
        ]);
    }
}
