<?php

namespace App\Sard\Proyeccion\Manejadores;

use Illuminate\Support\Facades\DB;

/**
 * AlertaCampoEnviada → `alertas` y la unidad pasa a «asignada» en `unidades_estado`.
 */
final class AlertaCampoEnviadaManejador
{
    /** @param  array<string, mixed>  $evento */
    public function __invoke(array $evento): void
    {
        $payload = Proyeccion::payload($evento);

        DB::table('alertas')->insertOrIgnore([
            'uuid' => $payload['alerta_uuid'],
            'evento_uuid' => $evento['uuid'],
            'decision_uuid' => $payload['decision_uuid'],
            'incidente_uuid' => $payload['incidente_uuid'],
            'unidad_id' => $payload['unidad_id'],
            'texto' => $payload['texto'],
            'enviada_en' => $evento['creado_en'],
        ]);

        Proyeccion::guardarGanaMenor('unidades_estado', 'unidad_id', [
            'unidad_id' => $payload['unidad_id'],
            'estado' => 'asignada',
            'alerta_uuid' => $payload['alerta_uuid'],
            'incidente_uuid' => $payload['incidente_uuid'],
            'evento_uuid' => $evento['uuid'],
            'actualizado_en' => $evento['creado_en'],
        ]);
    }
}
