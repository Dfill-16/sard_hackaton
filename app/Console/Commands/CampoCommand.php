<?php

namespace App\Console\Commands;

use App\Node\D\Acuses;
use Illuminate\Console\Command;

/**
 * En el Nodo D, cada 1 s reenvía al líder los acuses «Recibido» que aún no confirma (T-015).
 */
final class CampoCommand extends Command
{
    public const INTERVALO_US = 1_000_000;

    protected $signature = 'sard:campo {--una-vez : Ejecuta un solo ciclo y termina}';

    protected $description = 'Reenvía al líder los acuses de campo hasta que los confirme';

    public function handle(Acuses $acuses): int
    {
        if (config('sard.nodo_role') !== 'D') {
            $this->error('sard:campo solo corre en el Nodo D.');

            return self::FAILURE;
        }

        do {
            $enviados = $acuses->ciclo();
            if ($enviados > 0) {
                $this->line(now()->format('H:i:s')." · {$enviados} acuses enviados al líder");
            }

            if (! $this->option('una-vez')) {
                usleep(self::INTERVALO_US);
            }
        } while (! $this->option('una-vez'));

        return self::SUCCESS;
    }
}
