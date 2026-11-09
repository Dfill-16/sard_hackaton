<?php

namespace App\Console\Commands;

use App\Agents\A3\Dbscan;
use App\Agents\A3\Vecindad;
use App\Sard\Reglas\PrioridadIncidente;
use App\Sard\Support\Tiempo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Corre DBSCAN por lotes sobre todos los reportes con ubicación y lo compara con la
 * asignación incremental de A3 (que depende del orden de llegada).
 */
final class A3VerificarCommand extends Command
{
    protected $signature = 'sard:a3-verificar';

    protected $description = 'Compara los incidentes incrementales de A3 con un DBSCAN por lotes y lista los incidentes';

    public function handle(Dbscan $dbscan): int
    {
        $reportes = DB::table('reportes')->whereNotNull('lat')->whereNotNull('lon')->orderBy('creado_en')->get();
        $puntos = $reportes->map(fn (object $reporte): ?array => Vecindad::punto($reporte))->filter()->values()->all();

        $lote = collect($dbscan->agrupar($puntos))->map(fn (array $grupo): string => implode(',', $grupo));

        $incremental = DB::table('incidente_reporte')
            ->whereIn('reporte_uuid', $reportes->pluck('reporte_uuid'))
            ->orderBy('reporte_uuid')
            ->get()
            ->groupBy('incidente_uuid')
            ->map(fn ($filas): string => $filas->pluck('reporte_uuid')->sort()->implode(','));

        $coinciden = $incremental->values()->intersect($lote)->count();
        $sinIncidente = DB::table('reportes')
            ->whereNotIn('reporte_uuid', DB::table('incidente_reporte')->select('reporte_uuid'))
            ->count();

        $this->table(['Medida', 'Valor'], [
            ['Reportes con ubicación', $reportes->count()],
            ['Grupos DBSCAN por lotes', $lote->count()],
            ['Incidentes incrementales (con ubicación)', $incremental->count()],
            ['Grupos idénticos', $coinciden],
            ['Diferencias', max($lote->count(), $incremental->count()) - $coinciden],
            ['Reportes sin incidente', $sinIncidente],
        ]);

        $ahora = Tiempo::ahora();
        $filas = DB::table('incidentes')->orderBy('creado_en')->get()->map(function (object $incidente) use ($ahora): array {
            $prioridad = PrioridadIncidente::efectiva($incidente, $ahora);

            return [
                substr($incidente->uuid, 0, 13),
                $incidente->tipo,
                $incidente->reportes_n,
                $incidente->dispositivos_n,
                $incidente->masivo ? 'sí' : 'no',
                PrioridadIncidente::posibleRumor($incidente) ? 'sí' : 'no',
                $incidente->sin_ubicacion ? 'sí' : 'no',
                $incidente->prioridad_base,
                $prioridad['prioridad'].($prioridad['envejecido'] ? ' (env.)' : ''),
                $incidente->confianza,
            ];
        });

        $this->table(['Incidente', 'Tipo', 'Reportes', 'Disp.', 'Masivo', 'Rumor', 'Sin ubic.', 'Base', 'Efectiva', 'Confianza'], $filas->all());

        $this->line('Reportes originales en `reportes`: '.DB::table('reportes')->count());

        return self::SUCCESS;
    }
}
