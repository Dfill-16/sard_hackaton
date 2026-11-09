<?php

namespace App\Node\A;

use App\Sard\Reglas\Banderas;
use Illuminate\Contracts\View\View;

/**
 * GET /pwa en el Nodo A: PWA ciudadana offline (T-005). Mismo origen que POST /api/reportes.
 */
final class PwaController
{
    /** Nombres de tipo en lenguaje ciudadano (payload-reporte.md, tipos 0-7) y su ícono. */
    public const TIPOS = [
        1 => ['nombre' => 'Derrumbe o colapso', 'icono' => 'colapso'],
        2 => ['nombre' => 'Incendio o explosión', 'icono' => 'fuego'],
        3 => ['nombre' => 'Herido o enfermo', 'icono' => 'medico'],
        4 => ['nombre' => 'Deslizamiento', 'icono' => 'ladera'],
        5 => ['nombre' => 'Inundación', 'icono' => 'agua'],
        6 => ['nombre' => 'Vía bloqueada', 'icono' => 'via'],
        7 => ['nombre' => 'Gas o cables', 'icono' => 'servicios'],
        0 => ['nombre' => 'Otra emergencia', 'icono' => 'otro'],
    ];

    public function __invoke(): View
    {
        $tipos = [];
        foreach (self::TIPOS as $valor => $tipo) {
            $tipos[] = ['valor' => $valor, ...$tipo];
        }

        $banderas = collect(Banderas::CATALOGO)
            ->map(fn (array $bandera, int $bit): array => [
                'bit' => $bit,
                'nombre' => mb_strtoupper(mb_substr($bandera['etiqueta'], 0, 1)).mb_substr($bandera['etiqueta'], 1),
                'critica' => $bandera['critica'],
            ])
            ->sortByDesc('critica')
            ->values()
            ->all();

        return view('pwa.index', [
            'configuracion' => [
                'tipos' => $tipos,
                'banderas' => $banderas,
                'ventanaRepetidoMin' => (int) config('sard.pwa_ventana_repetido_min'),
                'urlReportes' => '/api/reportes',
            ],
        ]);
    }
}
