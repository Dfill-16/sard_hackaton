<?php

namespace App\Http\Controllers\Tablero;

use Illuminate\Contracts\View\View;

/**
 * GET /tablero · vista de coordinación (B o C). Los datos llegan por polling a /api/tablero/estado.
 */
final class TableroController
{
    /** Mapa base offline de Manizales y Villamaría (PMTiles vectorial, OpenStreetMap). */
    public const TESELAS = 'tiles/manizales.pmtiles';

    public function __invoke(): View
    {
        $nodo = (string) config('sard.nodo_role');
        $hayTeselas = file_exists(public_path(self::TESELAS));

        return view('tablero.index', [
            'nodo' => $nodo,
            'pollingMs' => (int) config('sard.polling_tablero_s') * 1000,
            'urlsRespaldo' => [
                'B' => (string) config('sard.urls.B'),
                'C' => (string) config('sard.urls.C'),
            ],
            'hayTeselas' => $hayTeselas,
            'teselas' => $hayTeselas ? '/'.self::TESELAS : null,
        ]);
    }
}
