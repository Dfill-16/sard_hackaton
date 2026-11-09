<?php

namespace Tests\Concerns;

use App\Sard\Nodo\EstadoNodo;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Simula un segundo nodo en el mismo proceso de pruebas: una base SQLite en memoria
 * propia, migrada desde cero, que pasa a ser la conexión por defecto.
 */
trait DosNodos
{
    /**
     * RefreshDatabase debe revertir siempre la conexión original de pruebas, aunque la prueba
     * cambie la conexión por defecto a la del segundo nodo.
     *
     * @var array<int, string>
     */
    protected array $connectionsToTransact = ['sqlite'];

    protected function cambiarABaseDe(string $nodo, string $lider = 'B', int $epoca = 1): void
    {
        $conexion = 'nodo_'.strtolower($nodo);
        DB::purge($conexion);
        config([
            "database.connections.{$conexion}" => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'sard.nodo_role' => $nodo,
        ]);

        Artisan::call('migrate', ['--database' => $conexion, '--force' => true]);
        DB::setDefaultConnection($conexion);

        app(EstadoNodo::class)->seguir($lider, $epoca);
        if ($nodo === $lider) {
            app(EstadoNodo::class)->asumir($epoca);
        }
    }
}
