<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/**
 * Reportes de demostración para el mapa del tablero (datos sintéticos y ficticios).
 *
 * No escribe en la base de datos: envía los reportes al Nodo A por POST /api/reportes, igual que
 * la PWA. Así pasan por el canal, el triaje (A2), la correlación (A3) y la recomendación (A6), y
 * quedan en el log con su cadena de hashes. Requiere los nodos encendidos (./scripts/start.ps1).
 *
 * Uso: php artisan db:seed --class=ReportesDemoSeeder
 *
 * Contenido: el escenario curado (P1, brecha, contradicción, sin ubicación), 20 reportes dispersos
 * por los barrios, un incidente masivo (6 dispositivos) y un posible rumor (30 reportes de un dispositivo).
 */
class ReportesDemoSeeder extends Seeder
{
    public function run(): void
    {
        $codigo = Artisan::call('sard:sembrar', [
            'n' => 20,
            '--demo' => true,
            '--masivo' => true,
            '--rumor' => true,
            '--semilla' => random_int(1, 1_000_000),
        ]);

        $this->command?->getOutput()->write(Artisan::output());
        if ($codigo !== 0) {
            $this->command?->error('No se enviaron los reportes: enciende los nodos con ./scripts/start.ps1 y vuelve a intentarlo.');
        }
    }
}
