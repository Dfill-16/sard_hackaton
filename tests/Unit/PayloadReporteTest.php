<?php

namespace Tests\Unit;

use App\Protocol\PayloadInvalidoException;
use App\Protocol\PayloadReporte;
use App\Protocol\Reporte;
use PHPUnit\Framework\TestCase;

/**
 * Pruebas del codec binario de reporte v1 (T-002).
 *
 * El fixture tests/fixtures/payload-v1.hex está CONGELADO: es el oráculo.
 * Estas pruebas lo leen y lo comparan; nunca lo recalculan ni regeneran.
 */
final class PayloadReporteTest extends TestCase
{
    /** Caso A del fixture congelado: con ubicación (Bogotá), tipo 3, texto de 20 B → 56 B. */
    private const CASO_A = '010194e8c25a3e7d4f9b2c1e5f6a7b8c9d1234567802ced770d3d97e98030000021468657269646f7320656e20656c207069736f20322d97';

    /** Caso B del fixture congelado: sin ubicación, texto vacío → 36 B exactos (AC-004a). */
    private const CASO_B = '010194e8c25a3e7d4f9b2c1e5f6a7b8c9e123456797fffffff7fffffff00000000003a21';

    /** CRC-16/CCITT-FALSE del vector de prueba estándar "123456789" (oráculo independiente del codec). */
    private const CRC_123456789 = 0x29B1;

    public function test_decodifica_fixture_caso_a_con_ubicacion(): void
    {
        $reporte = PayloadReporte::decode(hex2bin(self::CASO_A));

        $this->assertSame([
            'version' => 1,
            'uuid' => '0194e8c2-5a3e-7d4f-9b2c-1e5f6a7b8c9d',
            'device_id' => 305419896,
            'lat' => 4.711,
            'lon' => -74.0721,
            'tipo' => 3,
            'banderas' => 0,
            'personas' => 2,
            'texto' => 'heridos en el piso 2',
        ], $reporte);
    }

    public function test_decodifica_fixture_caso_b_sin_ubicacion(): void
    {
        $reporte = PayloadReporte::decode(hex2bin(self::CASO_B));

        $this->assertSame(1, $reporte['version']);
        $this->assertSame('0194e8c2-5a3e-7d4f-9b2c-1e5f6a7b8c9e', $reporte['uuid']);
        $this->assertSame(305419897, $reporte['device_id']);
        $this->assertNull($reporte['lat']);
        $this->assertNull($reporte['lon']);
        $this->assertSame(0, $reporte['tipo']);
        $this->assertSame(0, $reporte['banderas']);
        $this->assertSame(0, $reporte['personas']);
        $this->assertSame('', $reporte['texto']);
    }

    public function test_ac004a_payload_sin_texto_ocupa_36_bytes(): void
    {
        $this->assertSame(36, strlen(hex2bin(self::CASO_B)));
        $this->assertSame(36, strlen(PayloadReporte::encode([
            'uuid' => '0194e8c2-5a3e-7d4f-9b2c-1e5f6a7b8c9e',
            'device_id' => 1,
            'lat' => null,
            'lon' => null,
            'tipo' => 0,
            'banderas' => 0,
            'personas' => 0,
            'texto' => '',
        ])));
    }

    public function test_encode_es_determinista_frente_al_fixture_congelado(): void
    {
        // Re-codificar lo decodificado del oráculo debe reproducir el oráculo byte a byte.
        foreach ([self::CASO_A, self::CASO_B] as $caso) {
            $this->assertSame($caso, bin2hex(PayloadReporte::encode(PayloadReporte::decode(hex2bin($caso)))));
        }
    }

    public function test_decode_encode_ida_y_vuelta(): void
    {
        $casos = [
            ['uuid' => '0194e8c2-5a3e-7d4f-9b2c-1e5f6a7b8c9d', 'device_id' => 42, 'lat' => 4.711, 'lon' => -74.0721, 'tipo' => 3, 'banderas' => 0x0400, 'personas' => 2, 'texto' => 'heridos en el piso 2'],
            ['uuid' => '0194e8d5-2c7a-7f3e-8a1d-4b6c9e0f2a3b', 'device_id' => 0xFFFFFFFF, 'lat' => null, 'lon' => null, 'tipo' => 6, 'banderas' => 0x0008, 'personas' => 0, 'texto' => ''],
            // 70 caracteres "ñ" = 140 BYTES UTF-8: al límite del contrato.
            ['uuid' => '0194e8e1-9b4c-7d2f-b3e5-6a8d1c4f7e9b', 'device_id' => 7, 'lat' => -0.0000001, 'lon' => 179.9999999, 'tipo' => 7, 'banderas' => 0xFFFF, 'personas' => 255, 'texto' => str_repeat('ñ', 70)],
            ['uuid' => '0194e8e1-9b4c-7d2f-b3e5-6a8d1c4f7e9c', 'device_id' => 0, 'lat' => 90.0, 'lon' => -180.0, 'tipo' => 0, 'banderas' => 0x8000, 'personas' => 1, 'texto' => 'tildes: áéíóú ü ñ'],
        ];

        foreach ($casos as $caso) {
            $esperado = array_merge(['version' => 1], $caso);
            $this->assertSame($esperado, PayloadReporte::decode(PayloadReporte::encode($caso)), 'ida y vuelta: '.json_encode($caso, JSON_UNESCAPED_UNICODE));
        }
    }

    public function test_decode_encode_ida_y_vuelta_con_dto_reporte(): void
    {
        $reporte = new Reporte(
            uuid: '0194e8c2-5a3e-7d4f-9b2c-1e5f6a7b8c9d',
            deviceId: 305419896,
            lat: 4.711,
            lon: -74.0721,
            tipo: 3,
            banderas: 0,
            personas: 2,
            texto: 'heridos en el piso 2',
        );

        $this->assertSame($reporte->aArray(), PayloadReporte::decode(PayloadReporte::encode($reporte)));
        $this->assertEquals($reporte, Reporte::desdeArray(PayloadReporte::decode(PayloadReporte::encode($reporte))));
    }

    public function test_crc_alterado_lanza_excepcion_especifica(): void
    {
        $bytes = hex2bin(self::CASO_A);
        $bytes[strlen($bytes) - 1] = chr(ord($bytes[strlen($bytes) - 1]) ^ 0x01);

        $this->expectException(PayloadInvalidoException::class);
        PayloadReporte::decode($bytes);
    }

    public function test_byte_de_cuerpo_alterado_lanza_excepcion_por_crc(): void
    {
        $bytes = hex2bin(self::CASO_A);
        $bytes[10] = chr(ord($bytes[10]) ^ 0xFF);

        $this->expectException(PayloadInvalidoException::class);
        PayloadReporte::decode($bytes);
    }

    public function test_version_distinta_de_uno_lanza_excepcion(): void
    {
        $bytes = $this->reempaquetaConCrc(hex2bin(self::CASO_B), 0, "\x02");

        $this->expectException(PayloadInvalidoException::class);
        PayloadReporte::decode($bytes);
    }

    public function test_tipo_ocho_lanza_excepcion(): void
    {
        $bytes = $this->reempaquetaConCrc(hex2bin(self::CASO_B), 29, "\x08");

        $this->expectException(PayloadInvalidoException::class);
        PayloadReporte::decode($bytes);
    }

    public function test_payload_truncado_lanza_excepcion(): void
    {
        $this->expectException(PayloadInvalidoException::class);
        PayloadReporte::decode(substr(hex2bin(self::CASO_B), 0, 35));
    }

    public function test_bytes_sobrantes_lanzan_excepcion(): void
    {
        $this->expectException(PayloadInvalidoException::class);
        PayloadReporte::decode(hex2bin(self::CASO_B)."\x00");
    }

    public function test_long_texto_inconsistente_lanza_excepcion(): void
    {
        // long_texto dice 5 pero el texto tiene 20 B: largo total no coincide.
        $bytes = $this->reempaquetaConCrc(hex2bin(self::CASO_A), 33, "\x05");

        $this->expectException(PayloadInvalidoException::class);
        PayloadReporte::decode($bytes);
    }

    public function test_texto_de_141_bytes_utf8_lanza_excepcion_en_encode(): void
    {
        $this->expectException(PayloadInvalidoException::class);
        PayloadReporte::encode([
            'uuid' => '0194e8c2-5a3e-7d4f-9b2c-1e5f6a7b8c9d',
            'device_id' => 1,
            'lat' => null,
            'lon' => null,
            'tipo' => 0,
            'banderas' => 0,
            'personas' => 0,
            'texto' => str_repeat('ñ', 71), // 71 caracteres × 2 B = 142 B
        ]);
    }

    public function test_menos_uno_no_es_centinela(): void
    {
        // -1 (0xFFFFFFFF) NO significa "sin ubicación": solo 0x7FFFFFFF.
        $bytes = PayloadReporte::encode([
            'uuid' => '0194e8c2-5a3e-7d4f-9b2c-1e5f6a7b8c9d',
            'device_id' => 1,
            'lat' => -0.1,
            'lon' => -0.1,
            'tipo' => 0,
            'banderas' => 0,
            'personas' => 0,
            'texto' => '',
        ]);

        // -0.1° × 1e7 = -1 000 000 → 0xFFF0BDC0 por campo (ni -1 ni el centinela).
        $this->assertSame('fff0bdc0fff0bdc0', bin2hex(substr($bytes, 21, 8)));
        $this->assertSame(-0.1, PayloadReporte::decode($bytes)['lat']);
    }

    public function test_campos_fueras_de_rango_lanzan_excepcion_en_encode(): void
    {
        $base = [
            'uuid' => '0194e8c2-5a3e-7d4f-9b2c-1e5f6a7b8c9d',
            'device_id' => 1,
            'lat' => null,
            'lon' => null,
            'tipo' => 0,
            'banderas' => 0,
            'personas' => 0,
            'texto' => '',
        ];

        foreach ([
            ['tipo', 8],
            ['tipo', -1],
            ['banderas', 0x10000],
            ['personas', 256],
            ['device_id', -1],
            ['device_id', 4294967296],
            ['lat', 90.1],
            ['lon', -180.1],
        ] as [$campo, $valor]) {
            $caso = array_merge($base, [$campo => $valor]);

            try {
                PayloadReporte::encode($caso);
                $this->fail("encode() debió rechazar {$campo} = ".var_export($valor, true).'.');
            } catch (PayloadInvalidoException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(PayloadInvalidoException::class);
        PayloadReporte::encode(array_merge($base, ['uuid' => 'not-a-uuid']));
    }

    public function test_oracion_crc_16_ccitt_false_es_la_de_referencia(): void
    {
        // Segunda implementación (oráculo independiente) para no validar el codec con sí mismo.
        $this->assertSame(self::CRC_123456789, $this->crc16Oracula('123456789'));
    }

    public function test_prueba_cruzada_php_decodifica_lo_que_codifica_js(): void
    {
        $reporte = PayloadReporte::decode($this->fixtureCruzado('payload-v1-desde-js.hex'));

        $this->assertSame([
            'version' => 1,
            'uuid' => '0194e8d5-2c7a-7f3e-8a1d-4b6c9e0f2a3b',
            'device_id' => 0x7F3E2D1C,
            'lat' => 4.65,
            'lon' => -74.1,
            'tipo' => 6,
            'banderas' => 0x0400,
            'personas' => 3,
            'texto' => 'vía bloqueada por derrumbe',
        ], $reporte);
    }

    public function test_prueba_cruzada_php_decodifica_fixture_php(): void
    {
        // Espejo del test JS: el mismo reporte codificado por PHP decodifica limpio.
        $reporte = PayloadReporte::decode($this->fixtureCruzado('payload-v1-desde-php.hex'));

        $this->assertSame('0194e8e1-9b4c-7d2f-b3e5-6a8d1c4f7e9b', $reporte['uuid']);
        $this->assertSame(0x5A4C3B2D, $reporte['device_id']);
        $this->assertSame(6.2442, $reporte['lat']);
        $this->assertSame(-75.5812, $reporte['lon']);
        $this->assertSame(2, $reporte['tipo']);
        $this->assertSame(0x0008, $reporte['banderas']);
        $this->assertSame('fuego en bodega', $reporte['texto']);
    }

    /**
     * Reemplaza un byte del cuerpo y recalcula el CRC: así la prueba aísla el
     * campo que quiere provocar (versión, tipo...) sin que el CRC falle primero.
     */
    private function reempaquetaConCrc(string $bytes, int $offset, string $byte): string
    {
        $bytes[$offset] = $byte;

        return substr($bytes, 0, -2).pack('n', $this->crc16Oracula(substr($bytes, 0, -2)));
    }

    /** Lee el primer payload de un fixture congelado de la prueba cruzada. */
    private function fixtureCruzado(string $nombre): string
    {
        $lineas = array_values(array_filter(
            file(__DIR__.'/../fixtures/'.$nombre, FILE_IGNORE_NEW_LINES),
            fn (string $linea): bool => $linea !== '' && $linea[0] !== '#',
        ));

        return hex2bin($lineas[0]);
    }

    /** CRC-16/CCITT-FALSE (poly 0x1021, init 0xFFFF, sin reflexión, sin XOR final), tabla de 256 entradas. */
    private function crc16Oracula(string $bytes): int
    {
        $crc = 0xFFFF;

        for ($i = 0, $n = strlen($bytes); $i < $n; $i++) {
            $crc ^= ord($bytes[$i]) << 8;
            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc << 1) ^ (($crc & 0x8000) !== 0 ? 0x1021 : 0);
                $crc &= 0xFFFF;
            }
        }

        return $crc;
    }
}
