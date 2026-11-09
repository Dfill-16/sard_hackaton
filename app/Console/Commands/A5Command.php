<?php

namespace App\Console\Commands;

use App\Agents\A5\Resiliencia;
use App\Sard\Nodo\EstadoNodo;
use App\Sard\Support\Tiempo;
use Illuminate\Console\Command;

/**
 * A5 en B y C (T-011, T-013): latidos del líder, vigilante del seguidor, failover y unión al volver.
 */
final class A5Command extends Command
{
    protected $signature = 'sard:a5 {--una-vez : Ejecuta un solo ciclo y termina}';

    protected $description = 'A5: latidos, vigilante de failover, aviso del nuevo líder y unión al volver (sin LLM)';

    public function handle(Resiliencia $a5, EstadoNodo $estado): int
    {
        if (! in_array(config('sard.nodo_role'), ['B', 'C'], true)) {
            $this->error('sard:a5 solo corre en B o C.');

            return self::FAILURE;
        }

        $arranqueMs = Tiempo::milisegundos();
        // El vigilante cuenta desde este arranque: un latido viejo guardado no dispara un failover.
        $estado->guardar('ultimo_latido', null);

        if ($a5->descubrir()) {
            $this->warn("Hay un líder con época mayor: {$estado->lider()} (época {$estado->epoca()}). Este nodo queda como seguidor.");
        }
        $this->info("A5 activo en {$a5->nodo()} · rol {$estado->rol()} · líder {$estado->lider()} · época {$estado->epoca()}");

        $porNotificar = [];
        $intervaloUs = (int) config('sard.latido_s') * 1_000_000;

        do {
            if ($estado->esLider()) {
                $a5->latir();
            } else {
                if ($estado->valor('pendiente_union') === '1' && ($resultado = $a5->unir()) !== null) {
                    $this->info("Unión con {$estado->lider()}: {$resultado['insertados']} insertados, {$resultado['ignorados']} ignorados, {$resultado['traidos']} traídos.");
                }

                if ($a5->vigilar($arranqueMs)) {
                    $this->warn(now()->format('H:i:s')." · Sin latidos: {$a5->nodo()} asume como líder en la época {$estado->epoca()}.");
                    $porNotificar = ['A', 'D'];
                }
            }

            if ($porNotificar !== []) {
                $porNotificar = $a5->notificarLider($porNotificar);
            }

            if (! $this->option('una-vez')) {
                usleep($intervaloUs);
            }
        } while (! $this->option('una-vez'));

        return self::SUCCESS;
    }
}
