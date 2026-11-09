<?php

namespace App\Console\Commands;

use App\Sard\Proyeccion\Proyector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reconstruye todas las proyecciones desde el log de eventos.
 *
 * Vuelca los eventos ordenados por uuid y los reaplica a través del
 * proyector, dejando las tablas proyectadas consistentes con el log.
 */
final class ReconstruirCommand extends Command
{
    protected $signature = 'sard:reconstruir';

    protected $description = 'Reconstruye las proyecciones desde el log de eventos';

    public function handle(): int
    {
        $proyector = app(Proyector::class);
        $eventos = DB::table('eventos')
            ->orderBy('uuid')
            ->get();

        foreach ($eventos as $evento) {
            $proyector->aplicar(json_decode(json_encode($evento), true));
        }

        $this->info("Proyecciones reconstruidas: {$eventos->count()} eventos aplicados.");

        return self::SUCCESS;
    }
}
