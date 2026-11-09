<?php

namespace Tests\Concerns;

use App\Protocol\PayloadReporte;
use App\Sard\Nodo\EstadoNodo;
use App\Sard\Reglas\Banderas;
use App\Sard\Support\Tiempo;
use App\Sard\Support\Uuid7;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/**
 * Ayudas para pruebas del líder: construir payloads v1 y entregarlos como lo hace el canal.
 */
trait EntregaReportes
{
    /** Configura este proceso como nodo líder (B por defecto) y simula el canal. */
    protected function comoLider(string $nodo = 'B'): void
    {
        config(['sard.nodo_role' => $nodo]);
        app(EstadoNodo::class)->asumir(1);
        Http::fake(['*' => Http::response(['mensaje_id' => 'x', 'estado' => 'en_cola'], 202)]);
    }

    /**
     * @param  array<int, string>  $banderas  claves del catálogo
     */
    protected function payload(
        array $banderas = [],
        string $texto = '',
        ?float $lat = 5.0558,
        ?float $lon = -75.4930,
        int $tipo = 1,
        int $personas = 0,
        int $deviceId = 0x0A0B0C0D,
        ?string $uuid = null,
    ): string {
        $mascara = 0;
        foreach ($banderas as $clave) {
            $mascara |= 1 << Banderas::bitDeClave($clave);
        }

        return PayloadReporte::encode([
            'version' => 1,
            'uuid' => $uuid ?? Uuid7::generar(),
            'device_id' => $deviceId,
            'lat' => $lat,
            'lon' => $lon,
            'tipo' => $tipo,
            'banderas' => $mascara,
            'personas' => $personas,
            'texto' => $texto,
        ]);
    }

    /** UUIDv7 válido cuyo timestamp es el momento dado (creado_en sale del UUID). */
    protected function uuidEn(string $iso): string
    {
        $hex = sprintf('%012x', Tiempo::milisegundos(Tiempo::desdeIso($iso)))
            .'7'.bin2hex(random_bytes(2))[0].bin2hex(random_bytes(1))
            .'8'.substr(bin2hex(random_bytes(8)), 0, 15);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20, 12);
    }

    protected function entregar(string $payload): TestResponse
    {
        return $this->call('POST', '/api/entrega/reporte', [], [], [], [
            'CONTENT_TYPE' => 'application/octet-stream',
        ], $payload);
    }

    protected function uuidDe(string $payload): string
    {
        return PayloadReporte::decode($payload)['uuid'];
    }
}
