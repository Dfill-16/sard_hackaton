<?php

namespace Tests\Feature;

use App\Agents\A3\Dbscan;
use App\Agents\A3\Vecindad;
use App\Node\A\GeneradorSintetico;
use App\Sard\Proyeccion\Manejadores\RecalculoIncidente;
use App\Sard\Reglas\PrioridadIncidente;
use App\Sard\Support\Tiempo;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\EntregaReportes;
use Tests\TestCase;

/**
 * A3 (T-008): correlación espacio-temporal, incidente masivo y posible rumor.
 */
final class CorrelacionA3Test extends TestCase
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

    /** @param  array<int, string>  $payloads */
    private function entregarTodos(array $payloads): void
    {
        foreach ($payloads as $payload) {
            $this->entregar($payload)->assertOk();
        }
    }

    public function test_ac001d_seis_dispositivos_cercanos_forman_un_incidente_masivo(): void
    {
        $payloads = (new GeneradorSintetico(42))->masivo();
        $this->entregarTodos($payloads);

        $this->assertSame(1, DB::table('incidentes')->count());
        $incidente = DB::table('incidentes')->first();
        $this->assertTrue((bool) $incidente->masivo);
        $this->assertSame(6, (int) $incidente->dispositivos_n);
        $this->assertSame(6, (int) $incidente->reportes_n);

        $declaraciones = DB::table('eventos')->where('tipo', 'IncidenteMasivoDeclarado')->get();
        $this->assertCount(1, $declaraciones, 'se declara una sola vez');
        $this->assertSame(5, json_decode($declaraciones[0]->payload, true)['dispositivos'], 'se declara al llegar al umbral');

        $this->assertSame('P3', $incidente->prioridad_base);
        $this->assertSame('P2', PrioridadIncidente::efectiva($incidente, Tiempo::ahora())['prioridad'], 'masivo sube un nivel');
    }

    public function test_ac006a_treinta_reportes_de_un_dispositivo_son_posible_rumor_sin_elevar(): void
    {
        $this->entregarTodos((new GeneradorSintetico(42))->rumor());

        $this->assertSame(1, DB::table('incidentes')->count());
        $incidente = DB::table('incidentes')->first();
        $this->assertSame(30, (int) $incidente->reportes_n);
        $this->assertSame(1, (int) $incidente->dispositivos_n);
        $this->assertFalse((bool) $incidente->masivo);
        $this->assertTrue(PrioridadIncidente::posibleRumor($incidente));
        $this->assertSame(0, DB::table('eventos')->where('tipo', 'IncidenteMasivoDeclarado')->count());

        $efectiva = PrioridadIncidente::efectiva($incidente, Tiempo::ahora());
        $this->assertSame('P3', $incidente->prioridad_base);
        $this->assertSame('P3', $efectiva['prioridad'], 'el rumor no se eleva');
    }

    public function test_ac013a_el_incidente_referencia_cada_original_y_no_borra_ninguno(): void
    {
        $payloads = (new GeneradorSintetico(7))->masivo();
        $this->entregarTodos($payloads);

        $uuids = array_map(fn (string $payload): string => $this->uuidDe($payload), $payloads);
        $incidente = DB::table('incidentes')->value('uuid');

        $miembros = DB::table('incidente_reporte')->where('incidente_uuid', $incidente)->orderBy('reporte_uuid')->pluck('reporte_uuid')->all();
        sort($uuids);
        $this->assertSame($uuids, $miembros);
        $this->assertSame(6, DB::table('reportes')->whereIn('reporte_uuid', $uuids)->count());

        $masivo = json_decode(DB::table('eventos')->where('tipo', 'IncidenteMasivoDeclarado')->value('payload'), true);
        $this->assertCount(5, $masivo['reportes'], 'originales al momento de declarar (umbral 5)');
        $this->assertSame([], array_diff($masivo['reportes'], $uuids));
        $this->assertSame(6, DB::table('eventos')->where('tipo', 'IncidenteAgrupado')->where('ref_uuid', $incidente)->count());
    }

    public function test_dos_reportes_a_dos_km_forman_dos_incidentes(): void
    {
        $this->entregar($this->payload(banderas: ['incendio'], tipo: 2, lat: 5.0558, lon: -75.4930, deviceId: 1))->assertOk();
        $this->entregar($this->payload(banderas: ['incendio'], tipo: 2, lat: 5.0738, lon: -75.4930, deviceId: 2))->assertOk();

        $this->assertSame(2, DB::table('incidentes')->count());
        $this->assertSame(2, DB::table('eventos')->where('tipo', 'IncidenteAgrupado')->where('payload', 'like', '%"nuevo":true%')->count());
    }

    public function test_cercanos_sin_tipo_ni_bandera_en_comun_no_se_agrupan(): void
    {
        $this->entregar($this->payload(banderas: ['incendio'], tipo: 2, deviceId: 1))->assertOk();
        $this->entregar($this->payload(banderas: ['via'], tipo: 6, lat: 5.0559, deviceId: 2))->assertOk();

        $this->assertSame(2, DB::table('incidentes')->count());
    }

    public function test_bandera_inferida_por_palabra_cuenta_como_bandera_en_comun(): void
    {
        $this->entregar($this->payload(banderas: ['incendio'], tipo: 2, deviceId: 1))->assertOk();
        $this->entregar($this->payload(texto: 'sale humo del techo', tipo: 1, lat: 5.0560, deviceId: 2))->assertOk();

        $this->assertSame(1, DB::table('incidentes')->count());
        $incidente = DB::table('incidentes')->first();
        $this->assertSame(1, count(json_decode($incidente->contradicciones, true)), 'tipos distintos se conservan como contradicción');
    }

    public function test_fuera_de_la_ventana_de_tiempo_no_se_agrupan(): void
    {
        $this->entregar($this->payload(banderas: ['incendio'], tipo: 2, deviceId: 1, uuid: $this->uuidEn('2026-11-09T08:00:00.000Z')))->assertOk();
        $this->entregar($this->payload(banderas: ['incendio'], tipo: 2, deviceId: 2, uuid: $this->uuidEn('2026-11-09T08:45:00.000Z')))->assertOk();
        $this->entregar($this->payload(banderas: ['incendio'], tipo: 2, deviceId: 3, uuid: $this->uuidEn('2026-11-09T09:10:00.000Z')))->assertOk();

        $this->assertSame(2, DB::table('incidentes')->count(), '08:00 queda solo; 08:45 y 09:10 (25 min) se agrupan');
    }

    public function test_sin_ubicacion_queda_en_incidente_propio(): void
    {
        $this->entregar($this->payload(banderas: ['incendio'], tipo: 2, deviceId: 1))->assertOk();
        $this->entregar($this->payload(banderas: ['incendio'], tipo: 2, lat: null, lon: null, deviceId: 2))->assertOk();
        $this->entregar($this->payload(banderas: ['incendio'], tipo: 2, lat: null, lon: null, deviceId: 3))->assertOk();

        $this->assertSame(3, DB::table('incidentes')->count());
        $this->assertSame(2, DB::table('incidentes')->where('sin_ubicacion', true)->count());
        $this->assertSame(0, DB::table('incidentes')->where('sin_ubicacion', true)->whereNotNull('lat')->count());
    }

    public function test_reporte_entre_dos_incidentes_entra_al_de_uuid_menor_sin_fusionar(): void
    {
        $this->entregar($this->payload(banderas: ['incendio'], tipo: 2, lat: 5.0500, deviceId: 1))->assertOk();
        $this->entregar($this->payload(banderas: ['incendio'], tipo: 2, lat: 5.0540, deviceId: 2))->assertOk();
        $incidentes = DB::table('incidentes')->orderBy('uuid')->pluck('uuid')->all();
        $this->assertCount(2, $incidentes);

        $puente = $this->payload(banderas: ['incendio'], tipo: 2, lat: 5.0520, deviceId: 3);
        $this->entregar($puente)->assertOk();

        $this->assertSame(2, DB::table('incidentes')->count());
        $this->assertSame($incidentes[0], DB::table('incidente_reporte')->where('reporte_uuid', $this->uuidDe($puente))->value('incidente_uuid'));
    }

    public function test_dbscan_por_lotes_coincide_con_la_asignacion_incremental(): void
    {
        $generador = new GeneradorSintetico(42);
        $this->entregarTodos([...$generador->masivo(), ...$generador->rumor(), ...$generador->dispersos(20, null, 0)]);

        $reportes = DB::table('reportes')->whereNotNull('lat')->get();
        $lote = (new Dbscan)->agrupar($reportes->map(fn (object $r): array => Vecindad::punto($r))->all());
        $loteComoTexto = array_map(fn (array $grupo): string => implode(',', $grupo), $lote);

        $incremental = DB::table('incidente_reporte')->orderBy('reporte_uuid')->get()->groupBy('incidente_uuid')
            ->map(fn ($filas): string => $filas->pluck('reporte_uuid')->implode(','))->values()->all();

        $this->assertEqualsCanonicalizing($loteComoTexto, $incremental);

        Artisan::call('sard:a3-verificar');
        $this->assertStringContainsString('Grupos idénticos', Artisan::output());
    }

    public function test_prioridad_efectiva_envejece_sin_pasar_de_p2(): void
    {
        $incidente = (object) ['prioridad_base' => 'P4', 'masivo' => false, 'creado_en' => '2026-11-09T08:00:00.000Z'];
        $momento = Tiempo::desdeIso('2026-11-09T08:00:00.000Z');

        $this->assertSame(['prioridad' => 'P4', 'envejecido' => false], PrioridadIncidente::efectiva($incidente, $momento->addMinutes(9)));
        $this->assertSame(['prioridad' => 'P3', 'envejecido' => true], PrioridadIncidente::efectiva($incidente, $momento->addMinutes(10)));
        $this->assertSame(['prioridad' => 'P2', 'envejecido' => true], PrioridadIncidente::efectiva($incidente, $momento->addMinutes(25)));
        $this->assertSame(['prioridad' => 'P2', 'envejecido' => true], PrioridadIncidente::efectiva($incidente, $momento->addHours(5)));
        $this->assertSame(['prioridad' => 'P4', 'envejecido' => false], PrioridadIncidente::efectiva($incidente, $momento->addHours(5), tieneDecision: true));

        $masivo = (object) ['prioridad_base' => 'P2', 'masivo' => true, 'creado_en' => '2026-11-09T08:00:00.000Z'];
        $this->assertSame(['prioridad' => 'P1', 'envejecido' => false], PrioridadIncidente::efectiva($masivo, $momento->addHours(1)));
    }

    public function test_confianza_y_contradicciones_de_personas(): void
    {
        $recalculo = new RecalculoIncidente;
        $this->assertSame(0.44, $recalculo->confianza([0.2, 0.2], 1), '(1 − 0.2) · (0.4 + 0.15)');
        $this->assertSame(0.8, $recalculo->confianza([0.2], 6));

        $this->entregar($this->payload(banderas: ['derrumbe'], tipo: 1, personas: 1, deviceId: 1))->assertOk();
        $this->entregar($this->payload(banderas: ['derrumbe'], tipo: 1, personas: 9, deviceId: 2))->assertOk();

        $contradicciones = json_decode(DB::table('incidentes')->value('contradicciones'), true);
        $this->assertSame('personas', $contradicciones[0]['tipo']);
        $this->assertSame('1 frente a 9 personas', $contradicciones[0]['detalle']);
    }
}
