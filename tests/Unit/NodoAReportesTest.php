<?php

namespace Tests\Unit;

use App\Protocol\PayloadReporte;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class NodoAReportesTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE_CASO_B = '010194e8c25a3e7d4f9b2c1e5f6a7b8c9e123456797fffffff7fffffff00000000003a21';

    /** @var string */
    private $ackEsperado;

    protected function setUp(): void
    {
        parent::setUp();

        $bytes = hex2bin(self::FIXTURE_CASO_B);
        $this->ackEsperado = chr(0x41).substr($bytes, 1, 16);
    }

    /** Envía un cuerpo crudo (raw) como application/octet-stream. */
    private function postBinario(string $ruta, string $cuerpo): TestResponse
    {
        return $this->call('POST', $ruta, [], [], [], [
            'CONTENT_TYPE' => 'application/octet-stream',
        ], $cuerpo);
    }

    public function test_almacenar_reporte_binario_devuelve_ack_de_17_bytes(): void
    {
        $bytes = hex2bin(self::FIXTURE_CASO_B);

        $respuesta = $this->postBinario('/api/reportes', $bytes);

        $respuesta->assertStatus(200);
        $respuesta->assertHeader('Content-Type', 'application/octet-stream');
        $this->assertSame(17, strlen($respuesta->getContent()));
        $this->assertSame($this->ackEsperado, $respuesta->getContent());
    }

    public function test_ac004a_payload_sin_texto_tiene_36_bytes(): void
    {
        $this->assertSame(36, strlen(hex2bin(self::FIXTURE_CASO_B)));
    }

    public function test_ac001b_mismo_payload_tres_veces_una_fila_y_ack_idénticos(): void
    {
        $bytes = hex2bin(self::FIXTURE_CASO_B);

        $ack1 = $this->postBinario('/api/reportes', $bytes)->getContent();
        $ack2 = $this->postBinario('/api/reportes', $bytes)->getContent();
        $ack3 = $this->postBinario('/api/reportes', $bytes)->getContent();

        $this->assertSame($ack1, $ack2);
        $this->assertSame($ack2, $ack3);
        $this->assertSame(1, DB::table('cola_salida')->count());
    }

    public function test_crc_alterado_devuelve_400_y_sin_fila(): void
    {
        $bytes = hex2bin(self::FIXTURE_CASO_B);
        $bytes[strlen($bytes) - 1] = chr(ord($bytes[strlen($bytes) - 1]) ^ 0x01);

        $respuesta = $this->postBinario('/api/reportes', $bytes);

        $respuesta->assertStatus(400);
        $this->assertSame(0, DB::table('cola_salida')->count());
    }

    public function test_payload_sin_ubicacion_es_aceptado(): void
    {
        $decodificado = PayloadReporte::decode(hex2bin(self::FIXTURE_CASO_B));
        $this->assertNull($decodificado['lat']);
        $this->assertNull($decodificado['lon']);

        $respuesta = $this->postBinario('/api/reportes', hex2bin(self::FIXTURE_CASO_B));

        $respuesta->assertStatus(200);
    }

    public function test_ac028a_cola_salida_sin_columnas_de_datos_personales(): void
    {
        $columnas = DB::select('PRAGMA table_info(cola_salida)');
        $nombres = array_map(fn ($col) => $col->name, $columnas);

        $this->assertNotContains('nombre', $nombres);
        $this->assertNotContains('email', $nombres);
        $this->assertNotContains('telefono', $nombres);
        $this->assertNotContains('direccion', $nombres);
        $this->assertContains('reporte_uuid', $nombres);
        $this->assertContains('payload', $nombres);
        $this->assertContains('bytes', $nombres);
        $this->assertContains('device_id', $nombres);
    }

    public function test_payload_mayor_a_176_bytes_devuelve_413(): void
    {
        $payloadGrande = str_repeat("\x00", 177);

        $respuesta = $this->postBinario('/api/reportes', $payloadGrande);

        $respuesta->assertStatus(413);
    }
}
