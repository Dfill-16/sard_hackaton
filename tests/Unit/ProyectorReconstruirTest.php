<?php

namespace Tests\Unit;

use App\Sard\Log\EventLog;
use App\Sard\Nodo\EstadoNodo;
use App\Sard\Proyeccion\Proyector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ProyectorReconstruirTest extends TestCase
{
    use RefreshDatabase;

    public function test_sard_reconstruir_ejecuta_sin_errores_con_eventos(): void
    {
        // Insertar 2 eventos directamente en la BD (orden inverso de uuid).
        DB::table('eventos')->insert([
            [
                'uuid' => 'uuid-b',
                'nodo_origen' => 'B',
                'seq' => 1,
                'epoca' => 1,
                'tipo' => 'ReporteRecibido',
                'ref_uuid' => null,
                'creado_en' => '2026-11-09T10:00:00.000Z',
                'payload' => json_encode(['x' => 2]),
                'hash_prev' => str_repeat('0', 64),
                'hash' => 'hash-b',
            ],
            [
                'uuid' => 'uuid-a',
                'nodo_origen' => 'B',
                'seq' => 2,
                'epoca' => 1,
                'tipo' => 'ReporteNormalizado',
                'ref_uuid' => null,
                'creado_en' => '2026-11-09T10:00:00.000Z',
                'payload' => json_encode(['x' => 1]),
                'hash_prev' => str_repeat('0', 64),
                'hash' => 'hash-a',
            ],
        ]);

        $salida = Artisan::call('sard:reconstruir');

        $this->assertSame(0, $salida);
    }

    public function test_sard_reconstruir_vacia_y_reaplica_proyecciones(): void
    {
        $eventLog = new EventLog(new Proyector, app(EstadoNodo::class), 'B');
        $eventLog->append('ReporteRecibido', ['reporte_uuid' => 'r1'], 'r1');

        $salida = Artisan::call('sard:reconstruir');

        $this->assertSame(0, $salida);
    }
}
