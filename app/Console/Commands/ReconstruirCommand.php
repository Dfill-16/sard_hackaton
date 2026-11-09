<?php

namespace App\Console\Commands;

use App\Sard\Proyeccion\Proyector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Reconstruye todas las proyecciones desde el log de eventos.
 *
 * Vacía las tablas proyectadas, vuelca los eventos ordenados por uuid y los
 * reaplica a través del proyector, dejando las proyecciones consistentes con el log.
 * Un evento mal formado se informa y se salta; nunca se modifica el log.
 */
final class ReconstruirCommand extends Command
{
    /** Tablas derivadas del log (se pueden borrar y reconstruir). */
    public const PROYECCIONES = [
        'alertas', 'unidades_estado', 'decisiones', 'recomendaciones',
        'incidente_reporte', 'incidentes', 'triajes', 'reportes',
    ];

    protected $signature = 'sard:reconstruir';

    protected $description = 'Reconstruye las proyecciones desde el log de eventos';

    public function handle(): int
    {
        $proyector = app(Proyector::class);

        foreach (self::PROYECCIONES as $tabla) {
            DB::table($tabla)->delete();
        }

        $eventos = DB::table('eventos')
            ->orderBy('uuid')
            ->get();

        $fallidos = 0;
        foreach ($eventos as $evento) {
            try {
                DB::transaction(fn () => $proyector->aplicar((array) $evento));
            } catch (Throwable $excepcion) {
                $fallidos++;
                $this->warn("Evento {$evento->uuid} ({$evento->tipo}) no se pudo proyectar: {$excepcion->getMessage()}");
            }
        }

        $this->info("Proyecciones reconstruidas: {$eventos->count()} eventos aplicados, {$fallidos} con error.");

        return self::SUCCESS;
    }
}
