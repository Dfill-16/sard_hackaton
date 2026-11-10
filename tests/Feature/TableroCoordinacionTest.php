<?php

namespace Tests\Feature;

use App\Node\A\GeneradorSintetico;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\EntregaReportes;
use Tests\TestCase;

/**
 * Tablero de coordinación (T-009): JSON de estado, detalle con originales y vista.
 */
final class TableroCoordinacionTest extends TestCase
{
    use EntregaReportes;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->comoLider();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Reportes P1 separados ≥ 2 km para que cada uno sea su propio incidente. */
    private function entregarP1Separados(int $cantidad): void
    {
        for ($i = 0; $i < $cantidad; $i++) {
            $this->entregar($this->payload(banderas: ['atrapada'], tipo: 1, lat: 5.0 + $i * 0.02, deviceId: $i + 1))->assertOk();
        }
    }

    public function test_estado_json_tiene_la_forma_del_contrato(): void
    {
        $this->entregar($this->payload(banderas: ['atrapada'], texto: 'hay dos personas atrapadas en la casa de la esquina del parque', tipo: 1))->assertOk();

        $respuesta = $this->getJson('/api/tablero/estado')->assertOk();

        $respuesta->assertJsonStructure([
            'nodo', 'lider', 'epoca', 'rol', 'modo', 'ultimo_latido_hace_s', 'generado_en',
            'contadores' => ['P1', 'P2', 'P3', 'P4', 'total', 'sin_ubicacion'],
            'cola' => [['uuid', 'prioridad', 'envejecido', 'tipo', 'masivo', 'posible_rumor', 'reportes_n',
                'dispositivos_n', 'confianza', 'contradicciones_n', 'lat', 'lon', 'resumen', 'creado_en', 'estado']],
            'baja_prioridad', 'sin_ubicacion',
        ]);
        $respuesta->assertJsonPath('nodo', 'B')
            ->assertJsonPath('rol', 'lider')
            ->assertJsonPath('modo', 'reglas')
            ->assertJsonPath('ultimo_latido_hace_s', null)
            ->assertJsonPath('contadores.P1', 1)
            ->assertJsonPath('contadores.total', 1);
        $this->assertSame(60, mb_strlen($respuesta->json('cola.0.resumen')));
    }

    public function test_ac008b_sin_ubicacion_visible_con_cero_y_con_elementos(): void
    {
        $this->getJson('/api/tablero/estado')->assertOk()
            ->assertJsonPath('sin_ubicacion', [])
            ->assertJsonPath('contadores.sin_ubicacion', 0);

        $this->entregar($this->payload(banderas: ['herido'], tipo: 3, lat: null, lon: null))->assertOk();

        $respuesta = $this->getJson('/api/tablero/estado')->assertOk();
        $respuesta->assertJsonCount(1, 'sin_ubicacion')
            ->assertJsonPath('contadores.sin_ubicacion', 1)
            ->assertJsonPath('sin_ubicacion.0.lat', null)
            ->assertJsonCount(0, 'cola')
            ->assertJsonCount(0, 'baja_prioridad');
    }

    public function test_ac002a_cola_corta_top_10_y_ver_todos(): void
    {
        $this->entregarP1Separados(12);

        $this->getJson('/api/tablero/estado')->assertOk()
            ->assertJsonCount(10, 'cola')
            ->assertJsonPath('cola_total', 12);

        $this->getJson('/api/tablero/estado?todos=1')->assertOk()->assertJsonCount(12, 'cola');
    }

    public function test_ac014a_p3_p4_aparecen_con_conteo_y_marca_envejecido(): void
    {
        $this->entregar($this->payload(banderas: ['via'], tipo: 6, deviceId: 1))->assertOk();
        $this->entregar($this->payload(banderas: ['grieta'], tipo: 1, lat: 5.10, deviceId: 2))->assertOk();

        $respuesta = $this->getJson('/api/tablero/estado')->assertOk();
        $respuesta->assertJsonCount(2, 'baja_prioridad')->assertJsonPath('baja_prioridad.0.envejecido', false);
        $this->assertSame(['P3', 'P4'], collect($respuesta->json('baja_prioridad'))->pluck('prioridad')->sort()->values()->all());

        Carbon::setTestNow(now()->addMinutes(11));
        $envejecido = $this->getJson('/api/tablero/estado')->assertOk();
        $this->assertTrue(collect($envejecido->json('baja_prioridad'))->every(fn (array $i): bool => $i['envejecido']));
        $this->assertSame(['P2', 'P3'], collect($envejecido->json())->only(['cola', 'baja_prioridad'])->flatten(1)->pluck('prioridad')->sort()->values()->all());
    }

    public function test_masivo_y_rumor_llevan_sus_etiquetas(): void
    {
        $generador = new GeneradorSintetico(42);
        foreach ([...$generador->masivo(), ...$generador->rumor()] as $payload) {
            $this->entregar($payload)->assertOk();
        }

        $incidentes = collect($this->getJson('/api/tablero/estado?todos=1')->assertOk()->json())
            ->only(['cola', 'baja_prioridad'])->flatten(1);

        $masivo = $incidentes->firstWhere('masivo', true);
        $this->assertSame(6, $masivo['dispositivos_n']);
        $this->assertSame('P2', $masivo['prioridad']);

        $rumor = $incidentes->firstWhere('posible_rumor', true);
        $this->assertSame(1, $rumor['dispositivos_n']);
        $this->assertFalse($rumor['masivo']);
    }

    public function test_ac008a_ac013a_detalle_con_originales_confianza_origen_y_eventos(): void
    {
        $payloads = (new GeneradorSintetico(42))->masivo();
        foreach ($payloads as $payload) {
            $this->entregar($payload)->assertOk();
        }
        $uuid = DB::table('incidentes')->value('uuid');

        $detalle = $this->getJson("/api/incidentes/{$uuid}")->assertOk();

        $detalle->assertJsonCount(6, 'reportes')
            ->assertJsonPath('dispositivos_n', 6)
            ->assertJsonPath('reportes.0.origen', 'reglas')
            ->assertJsonStructure(['confianza', 'contradicciones', 'reportes' => [[
                'uuid', 'texto', 'device_id', 'banderas', 'personas', 'hora', 'senales', 'incertidumbre', 'origen',
            ]], 'eventos' => [['uuid', 'tipo', 'nodo', 'seq', 'hash']]]);
        $this->assertGreaterThan(0, $detalle->json('confianza'));

        $originales = array_map(fn (string $p): string => $this->uuidDe($p), $payloads);
        $this->assertEqualsCanonicalizing($originales, array_column($detalle->json('reportes'), 'uuid'));

        $tipos = array_count_values(array_column($detalle->json('eventos'), 'tipo'));
        $this->assertSame(6, $tipos['ReporteNormalizado']);
        $this->assertSame(6, $tipos['TriajeAsignado']);
        $this->assertSame(6, $tipos['IncidenteAgrupado']);
        $this->assertSame(1, $tipos['IncidenteMasivoDeclarado']);
        $this->assertSame(12, strlen($detalle->json('eventos.0.hash')));
    }

    public function test_detalle_inexistente_responde_404(): void
    {
        $this->getJson('/api/incidentes/no-existe')->assertNotFound()->assertJsonPath('error', 'no_encontrado');
    }

    public function test_vista_del_tablero_se_sirve_en_b_y_c_pero_no_en_a(): void
    {
        $this->withoutVite();

        $this->get('/tablero')->assertOk()
            ->assertSee('Coordinación · Manizales')
            ->assertSee('Atender primero')
            ->assertSee('Sin ubicación')
            ->assertSee('Baja prioridad')
            ->assertSee('127.0.0.1:8003', false)
            ->assertSee('manizales.pmtiles', false);

        config(['sard.nodo_role' => 'C']);
        $this->get('/tablero')->assertOk();

        config(['sard.nodo_role' => 'A']);
        $this->get('/tablero')->assertNotFound();
        $this->getJson('/api/tablero/estado')->assertNotFound();
    }

    public function test_el_mapa_trae_todos_los_incidentes_con_ubicacion_aunque_la_cola_venga_recortada(): void
    {
        // 12 P1 separados ~1,1 km entre sí (no se agrupan): la cola corta trae 10, el mapa los 12.
        foreach (range(0, 11) as $i) {
            $this->entregar($this->payload(
                banderas: ['atrapada'],
                lat: 4.95 + $i * 0.01,
                lon: -75.61,
                deviceId: 1000 + $i,
            ))->assertOk();
        }
        $this->entregar($this->payload(banderas: ['atrapada'], lat: null, lon: null, deviceId: 2000))->assertOk();

        $estado = $this->getJson('/api/tablero/estado')->assertOk();

        $estado->assertJsonPath('cola_total', 12)
            ->assertJsonCount(10, 'cola')
            ->assertJsonCount(12, 'mapa')
            ->assertJsonCount(1, 'sin_ubicacion');
        $this->assertEqualsCanonicalizing(
            ['uuid', 'lat', 'lon', 'prioridad', 'tipo_nombre', 'reportes_n', 'confianza', 'corroborado', 'masivo', 'radio_m'],
            array_keys($estado->json('mapa.0')),
        );
        $this->assertNotContains(null, array_column($estado->json('mapa'), 'lat'), 'los sin ubicación no van al mapa');
    }
}
