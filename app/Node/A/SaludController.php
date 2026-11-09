<?php

namespace App\Node\A;

use App\Sard\Nodo\EstadoNodo;
use Illuminate\Support\Facades\DB;

/**
 * Endpoint de salud del Nodo A.
 *
 * Devuelve el estado del nodo y la cantidad de reportes pendientes en la
 * cola de salida.
 */
final class SaludController
{
    public function __invoke(): array
    {
        $estado = app(EstadoNodo::class);

        $colaPendiente = DB::table('cola_salida')
            ->whereNull('enviado_a')
            ->count();

        return [
            'nodo' => config('sard.nodo_role', 'A'),
            'rol' => $estado->rol(),
            'lider' => $estado->lider(),
            'epoca' => $estado->epoca(),
            'cola_pendiente' => $colaPendiente,
        ];
    }
}
