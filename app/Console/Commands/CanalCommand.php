<?php

namespace App\Console\Commands;

use App\Channel\Canal;
use Illuminate\Console\Command;

/**
 * Worker del proceso CANAL: cada 100 ms entrega los mensajes vencidos (T-006).
 */
final class CanalCommand extends Command
{
    public const INTERVALO_US = 100_000;

    protected $signature = 'sard:canal {--una-vez : Ejecuta un solo ciclo y termina}';

    protected $description = 'Worker del canal simulado: entrega mensajes con pérdida, latencia, desorden y ventanas';

    public function handle(Canal $canal): int
    {
        if (config('sard.nodo_role') !== 'CANAL') {
            $this->error('sard:canal solo corre con NODE_ROLE=CANAL.');

            return self::FAILURE;
        }

        $arranqueMs = $canal->registrarArranque();
        $this->info('Canal activo. Parámetros: '.json_encode($canal->parametros()));

        do {
            $procesados = $canal->despacharPendientes($arranqueMs);
            if ($procesados > 0) {
                $this->line(now()->format('H:i:s.v')." · {$procesados} mensajes procesados");
            }

            if (! $this->option('una-vez')) {
                usleep(self::INTERVALO_US);
            }
        } while (! $this->option('una-vez'));

        return self::SUCCESS;
    }
}
