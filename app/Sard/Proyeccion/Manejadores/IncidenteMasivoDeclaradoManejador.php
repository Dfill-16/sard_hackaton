<?php

namespace App\Sard\Proyeccion\Manejadores;

use Illuminate\Support\Facades\DB;

/**
 * IncidenteMasivoDeclarado → marca el incidente como masivo (sube de prioridad en el tablero).
 */
final class IncidenteMasivoDeclaradoManejador
{
    /** @param  array<string, mixed>  $evento */
    public function __invoke(array $evento): void
    {
        $payload = Proyeccion::payload($evento);

        DB::table('incidentes')
            ->where('uuid', $payload['incidente_uuid'])
            ->update(['masivo' => true, 'actualizado_en' => $evento['creado_en']]);
    }
}
