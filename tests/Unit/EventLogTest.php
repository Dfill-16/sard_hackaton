<?php

namespace Tests\Unit;

use App\Sard\Log\EventLog;
use App\Sard\Nodo\EstadoNodo;
use App\Sard\Proyeccion\Proyector;
use App\Sard\Support\Uuid7;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class EventLogTest extends TestCase
{
    use RefreshDatabase;

    private EventLog $log;

    private Proyector $proyector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->proyector = new Proyector;
        $this->log = new EventLog($this->proyector, app(EstadoNodo::class), 'B');
    }

    public function test_uuid7_genera_y_extrae_milisegundos(): void
    {
        $uuid = Uuid7::generar();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $uuid
        );

        $ms = Uuid7::milisegundos($uuid);
        $this->assertGreaterThan(0, $ms);
        $this->assertLessThanOrEqual((int) (microtime(true) * 1000) + 1000, $ms);
    }

    public function test_append_primero_evento_tiene_hash_prev_de_génesis(): void
    {
        $evento = $this->log->append('ReporteRecibido', ['reporte_uuid' => 'r1'], 'r1');

        $this->assertSame(1, $evento['seq']);
        $this->assertSame('B', $evento['nodo_origen']);
        $this->assertSame(str_repeat('0', 64), $evento['hash_prev']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $evento['hash']);
        $this->assertSame('ReporteRecibido', $evento['tipo']);
        $this->assertSame('r1', $evento['ref_uuid']);
    }

    public function test_append_segundo_evento_encadena_hash_prev(): void
    {
        $primero = $this->log->append('ReporteRecibido', ['reporte_uuid' => 'r1'], 'r1');
        $segundo = $this->log->append('ReporteNormalizado', ['reporte_uuid' => 'r1'], 'r1');

        $this->assertSame(2, $segundo['seq']);
        $this->assertSame($primero['hash'], $segundo['hash_prev']);
        $this->assertNotSame($primero['hash'], $segundo['hash']);
    }

    public function test_verificar_cadena_devuelve_ok_si_es_integra(): void
    {
        $this->log->append('ReporteRecibido', ['reporte_uuid' => 'r1'], 'r1');
        $this->log->append('ReporteNormalizado', ['reporte_uuid' => 'r1'], 'r1');

        $resultado = $this->log->verificarCadena('B');

        $this->assertTrue($resultado['ok']);
        $this->assertNull($resultado['roto_en']);
    }

    public function test_verificar_cadena_detecta_payload_alterado(): void
    {
        $this->log->append('ReporteRecibido', ['reporte_uuid' => 'r1', 'datos' => 'original'], 'r1');

        // Desactivar temporalmente el trigger append-only para alterar el payload (AC-027a).
        DB::unprepared('DROP TRIGGER IF EXISTS eventos_before_update');
        try {
            DB::table('eventos')->where('seq', 1)->update([
                'payload' => json_encode(['reporte_uuid' => 'r1', 'datos' => 'alterado']),
            ]);
        } finally {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER eventos_before_update BEFORE UPDATE ON eventos
                BEGIN
                    SELECT RAISE(ABORT, 'append-only: eventos es solo-lectura tras insert');
                END;
SQL
            );
        }

        $resultado = $this->log->verificarCadena('B');

        $this->assertFalse($resultado['ok']);
        $this->assertSame(1, $resultado['roto_en']);
    }

    public function test_ingerir_evento_duplicado_devuelve_false_y_no_duplica(): void
    {
        $evento = [
            'uuid' => Uuid7::generar(),
            'nodo_origen' => 'A',
            'seq' => 1,
            'epoca' => 1,
            'tipo' => 'ReporteRecibido',
            'ref_uuid' => 'r1',
            'creado_en' => '2026-11-09T10:00:00.000Z',
            'payload' => ['reporte_uuid' => 'r1'],
            'hash_prev' => str_repeat('0', 64),
        ];
        $evento['hash'] = $this->log->hash(str_repeat('0', 64), $evento);

        $primera = $this->log->ingerir($evento);
        $segunda = $this->log->ingerir($evento);

        $this->assertTrue($primera);
        $this->assertFalse($segunda);
        $this->assertSame(1, DB::table('eventos')->where('uuid', $evento['uuid'])->count());
    }

    public function test_ingerir_hash_invalido_lanza_excepcion(): void
    {
        $evento = [
            'uuid' => Uuid7::generar(),
            'nodo_origen' => 'A',
            'seq' => 1,
            'epoca' => 1,
            'tipo' => 'ReporteRecibido',
            'ref_uuid' => 'r1',
            'creado_en' => '2026-11-09T10:00:00.000Z',
            'payload' => ['reporte_uuid' => 'r1'],
            'hash_prev' => str_repeat('0', 64),
            'hash' => str_repeat('f', 64), // hash incorrecto a propósito
        ];

        $this->expectException(\RuntimeException::class);
        $this->log->ingerir($evento);
    }

    public function test_update_sobre_eventos_falla_por_trigger(): void
    {
        $this->log->append('ReporteRecibido', ['reporte_uuid' => 'r1'], 'r1');

        $this->expectException(QueryException::class);
        DB::table('eventos')->where('seq', 1)->update(['tipo' => 'Otro']);
    }

    public function test_delete_sobre_eventos_falla_por_trigger(): void
    {
        $this->log->append('ReporteRecibido', ['reporte_uuid' => 'r1'], 'r1');

        $this->expectException(QueryException::class);
        DB::table('eventos')->where('seq', 1)->delete();
    }

    public function test_desde_devuelve_eventos_desde_punto_de_sincronia(): void
    {
        $this->log->append('ReporteRecibido', ['reporte_uuid' => 'r1'], 'r1');
        $this->log->append('ReporteNormalizado', ['reporte_uuid' => 'r1'], 'r1');

        $desdeCero = $this->log->desde([], 500);
        $this->assertSame(2, count($desdeCero));

        $desdeUno = $this->log->desde(['B' => 1], 500);
        $this->assertSame(1, count($desdeUno));
        $this->assertSame(2, $desdeUno[0]['seq']);
    }

    public function test_estado_nodo_epoca_inicial_y_asumir(): void
    {
        $estado = app(EstadoNodo::class);

        $this->assertSame('B', $estado->lider());
        $this->assertSame(1, $estado->epoca());
        $this->assertFalse($estado->esLider());

        $estado->asumir(3);
        $this->assertTrue($estado->esLider());
        $this->assertSame(3, $estado->epoca());

        $estado->seguir('A', 2);
        $this->assertFalse($estado->esLider());
        $this->assertSame('A', $estado->lider());
        $this->assertSame(2, $estado->epoca());
    }
}
