<?php

namespace App\Console\Commands;

use App\Agents\A4\Comunicacion;
use Illuminate\Console\Command;

/**
 * A4 en el Nodo A: cada 250 ms envía al líder lo más prioritario de la cola (T-006).
 */
final class A4Command extends Command
{
    public const INTERVALO_US = 250_000;

    protected $signature = 'sard:a4 {--una-vez : Ejecuta un solo ciclo y termina}';

    protected $description = 'A4: envía la cola de salida del Nodo A al líder por el canal, con prioridad y backoff';

    public function handle(Comunicacion $a4): int
    {
        if (config('sard.nodo_role') !== 'A') {
            $this->error('sard:a4 solo corre con NODE_ROLE=A.');

            return self::FAILURE;
        }

        $this->info('A4 activo.');

        do {
            $enviados = $a4->ciclo();
            if ($enviados > 0) {
                $this->line(now()->format('H:i:s.v')." · {$enviados} reportes enviados al canal");
            }

            if (! $this->option('una-vez')) {
                usleep(self::INTERVALO_US);
            }
        } while (! $this->option('una-vez'));

        return self::SUCCESS;
    }
}
