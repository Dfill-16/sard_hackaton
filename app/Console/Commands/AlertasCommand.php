<?php

namespace App\Console\Commands;

use App\Agents\A6\EnvioAlertas;
use Illuminate\Console\Command;

/**
 * En el líder (B o C), cada 1 s envía por el canal al Nodo D las alertas aprobadas sin acuse (T-015).
 */
final class AlertasCommand extends Command
{
    public const INTERVALO_US = 1_000_000;

    protected $signature = 'sard:alertas {--una-vez : Ejecuta un solo ciclo y termina}';

    protected $description = 'Envía al Nodo D las alertas de campo aprobadas, con reintento hasta el acuse';

    public function handle(EnvioAlertas $envio): int
    {
        if (! in_array(config('sard.nodo_role'), ['B', 'C'], true)) {
            $this->error('sard:alertas solo corre en B o C.');

            return self::FAILURE;
        }

        do {
            $enviadas = $envio->ciclo();
            if ($enviadas > 0) {
                $this->line(now()->format('H:i:s')." · {$enviadas} alertas enviadas a D");
            }

            if (! $this->option('una-vez')) {
                usleep(self::INTERVALO_US);
            }
        } while (! $this->option('una-vez'));

        return self::SUCCESS;
    }
}
