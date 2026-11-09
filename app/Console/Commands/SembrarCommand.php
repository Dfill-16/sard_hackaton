<?php

namespace App\Console\Commands;

use App\Node\A\GeneradorSintetico;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Genera reportes sintéticos y los envía al Nodo A como lo haría la PWA (T-006).
 */
final class SembrarCommand extends Command
{
    protected $signature = 'sard:sembrar
        {n=20 : Reportes dispersos alrededor de los barrios}
        {--dispositivos= : Dispositivos distintos para los reportes dispersos (por defecto, uno por reporte)}
        {--semilla=42 : Semilla de la generación}
        {--rumor : Agrega 30 reportes de un solo dispositivo}
        {--masivo : Agrega 6 dispositivos a ≤ 200 m con el mismo tipo}
        {--sin-ubicacion=0.1 : Fracción de reportes dispersos sin GPS}';

    protected $description = 'Genera reportes sintéticos (datos de demostración) y los envía a POST /api/reportes del Nodo A';

    public function handle(): int
    {
        $generador = new GeneradorSintetico((int) $this->option('semilla'));
        $dispositivos = $this->option('dispositivos') !== null ? (int) $this->option('dispositivos') : null;

        $payloads = $generador->dispersos((int) $this->argument('n'), $dispositivos, (float) $this->option('sin-ubicacion'));
        if ($this->option('masivo')) {
            $payloads = [...$payloads, ...$generador->masivo()];
        }
        if ($this->option('rumor')) {
            $payloads = [...$payloads, ...$generador->rumor()];
        }

        $url = rtrim((string) config('sard.urls.A'), '/').'/api/reportes';
        $confirmados = 0;

        foreach ($payloads as $payload) {
            try {
                $respuesta = Http::withBody($payload, 'application/octet-stream')->timeout(5)->post($url);
            } catch (ConnectionException $excepcion) {
                $this->error("El Nodo A no responde en {$url}.");

                return self::FAILURE;
            }

            if ($respuesta->status() === 200 && strlen($respuesta->body()) === 17) {
                $confirmados++;
            } else {
                $this->warn("Reporte rechazado por A: HTTP {$respuesta->status()}");
            }
        }

        $this->info('Enviados '.count($payloads)." reportes a {$url}; {$confirmados} con ACK del Nodo A.");

        return self::SUCCESS;
    }
}
