<?php

namespace Tests\Unit;

use App\Protocol\PayloadReporte;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class NodoASaludYMetricasTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE_CASO_B = '010194e8c25a3e7d4f9b2c1e5f6a7b8c9e123456797fffffff7fffffff00000000003a21';

    /** Envía un cuerpo crudo (raw) como application/octet-stream. */
    private function postBinario(string $ruta, string $cuerpo): TestResponse
    {
        return $this->call('POST', $ruta, [], [], [], [
            'CONTENT_TYPE' => 'application/octet-stream',
        ], $cuerpo);
    }

    public function test_salud_devuelve_estado_del_nodo(): void
    {
        $respuesta = $this->get('/api/salud');

        $respuesta->assertStatus(200);
        $datos = $respuesta->json();

        $this->assertArrayHasKey('nodo', $datos);
        $this->assertArrayHasKey('rol', $datos);
        $this->assertArrayHasKey('lider', $datos);
        $this->assertArrayHasKey('epoca', $datos);
        $this->assertArrayHasKey('cola_pendiente', $datos);
        $this->assertSame('A', $datos['nodo']);
        $this->assertSame(1, $datos['epoca']);
        $this->assertSame(0, $datos['cola_pendiente']);
    }

    public function test_salud_muestra_cola_pendiente_despues_de_insertar(): void
    {
        $this->postBinario('/api/reportes', hex2bin(self::FIXTURE_CASO_B));

        $respuesta = $this->get('/api/salud');
        $datos = $respuesta->json();

        $this->assertSame(1, $datos['cola_pendiente']);
    }

    public function test_metricas_devuelve_bytes_binario_y_json_equivalente(): void
    {
        $this->postBinario('/api/reportes', hex2bin(self::FIXTURE_CASO_B));

        $respuesta = $this->get('/api/metricas');

        $respuesta->assertStatus(200);
        $datos = $respuesta->json();

        $this->assertArrayHasKey('reportes', $datos);
        $this->assertArrayHasKey('bytes_binario', $datos);
        $this->assertArrayHasKey('bytes_json_equivalente', $datos);
        $this->assertArrayHasKey('confirmados_por_lider', $datos);
        $this->assertSame(1, $datos['reportes']);
        $this->assertSame(36, $datos['bytes_binario']);
        $this->assertSame(0, $datos['confirmados_por_lider']);
    }

    public function test_metricas_bytes_json_equivalente_calculado_desde_decodificado(): void
    {
        $this->postBinario('/api/reportes', hex2bin(self::FIXTURE_CASO_B));

        $decodificado = PayloadReporte::decode(hex2bin(self::FIXTURE_CASO_B));
        $bytesJsonEsperado = strlen(json_encode($decodificado, JSON_UNESCAPED_UNICODE));

        $respuesta = $this->get('/api/metricas');
        $datos = $respuesta->json();

        $this->assertSame($bytesJsonEsperado, $datos['bytes_json_equivalente']);
    }
}
