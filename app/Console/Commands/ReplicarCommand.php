<?php

namespace App\Console\Commands;

use App\Agents\A5\Resiliencia;
use Illuminate\Console\Command;

/**
 * Réplica continua del seguidor (T-011): cada REPLICA_S trae del líder los eventos que faltan.
 */
final class ReplicarCommand extends Command
{
    protected $signature = 'sard:replicar {--una-vez : Ejecuta un solo ciclo y termina}';

    protected $description = 'Replica el log del líder en el seguidor (B o C) y aplica las proyecciones';

    public function handle(Resiliencia $a5): int
    {
        if (! in_array(config('sard.nodo_role'), ['B', 'C'], true)) {
            $this->error('sard:replicar solo corre en B o C.');

            return self::FAILURE;
        }

        $intervaloUs = (int) round((float) config('sard.replica_s') * 1_000_000);

        do {
            $insertados = $a5->replicar();
            if ($insertados > 0) {
                $this->line(now()->format('H:i:s')." · {$insertados} eventos replicados");
            }

            if (! $this->option('una-vez')) {
                usleep($intervaloUs);
            }
        } while (! $this->option('una-vez'));

        return self::SUCCESS;
    }
}
