<?php

namespace App\Console\Commands;

use App\Sard\Log\EventLog;
use Illuminate\Console\Command;

/**
 * Imprime el resultado de verificarCadena para un nodo.
 */
final class VerificarLogCommand extends Command
{
    protected $signature = 'sard:verificar-log {nodo?}';

    protected $description = 'Verifica la cadena de hash del log de eventos de un nodo';

    public function handle(): int
    {
        $nodo = $this->argument('nodo') ?? config('sard.nodo_role', 'A');

        $resultado = app(EventLog::class)->verificarCadena($nodo);

        if ($resultado['ok']) {
            $this->info("OK: cadena de eventos del nodo [{$nodo}] íntegra.");
        } else {
            $this->error("ROTO en seq [{$resultado['roto_en']}] del nodo [{$nodo}].");
        }

        return self::SUCCESS;
    }
}
