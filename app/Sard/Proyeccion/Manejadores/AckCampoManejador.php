<?php

namespace App\Sard\Proyeccion\Manejadores;

use Illuminate\Support\Facades\DB;

/**
 * AckCampo → la alerta queda con acuse (T-015). Tras la unión, si hubiera dos, gana el más antiguo.
 */
final class AckCampoManejador
{
    /** @param  array<string, mixed>  $evento */
    public function __invoke(array $evento): void
    {
        $payload = Proyeccion::payload($evento);

        DB::table('alertas')
            ->where('uuid', $payload['alerta_uuid'])
            ->where(fn ($q) => $q->whereNull('ack_en')->orWhere('ack_en', '>', $evento['creado_en']))
            ->update([
                'ack_en' => $evento['creado_en'],
                'recibido_en_campo' => $payload['recibido_en_campo'] ?? null,
            ]);
    }
}
