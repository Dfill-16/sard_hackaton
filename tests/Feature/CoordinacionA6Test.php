<?php

namespace Tests\Feature;

use App\Agents\A6\Coordinacion;
use App\Node\A\GeneradorSintetico;
use App\Sard\Nodo\EstadoNodo;
use Database\Seeders\SemillaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\EntregaReportes;
use Tests\TestCase;

/**
 * A6 y aprobación humana (T-010): recomienda, pero nada se despacha sin DecisionHumana.
 */
final class CoordinacionA6Test extends TestCase
{
    use EntregaReportes;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->comoLider();
        $this->seed(SemillaSeeder::class);
    }

    private function recomendacionDe(string $payload): object
    {
        $incidente = DB::table('incidente_reporte')->where('reporte_uuid', $this->uuidDe($payload))->value('incidente_uuid');

        return DB::table('recomendaciones')->where('incidente_uuid', $incidente)->orderByDesc('version')->first();
    }

    /** @param  array<string, mixed>  $datos */
    private function decidir(array $datos): TestResponse
    {
        return $this->postJson('/api/decisiones', [...['operador' => 'Mariana'], ...$datos]);
    }

    public function test_cada_incidente_recibe_una_recomendacion_de_plantilla(): void
    {
        $payload = $this->payload(banderas: ['incendio'], tipo: 2, lat: 5.0485, lon: -75.4835);
        $this->entregar($payload)->assertOk();

        $recomendacion = $this->recomendacionDe($payload);
        $this->assertSame('U-06', $recomendacion->unidad_id, 'Bomberos Estación 2 es la más cercana');
        $this->assertSame('enviar Bomberos Estación 2', $recomendacion->accion);
        $this->assertSame('plantilla', $recomendacion->modo);
        $this->assertSame(1, (int) $recomendacion->version);
        $this->assertStringStartsWith('P3 · Incendio/explosión · 1 reporte de 1 dispositivo · confianza ', $recomendacion->justificacion);
        $this->assertStringEndsWith('Bomberos Estación 2 a '.(int) $recomendacion->distancia_m.' m', $recomendacion->justificacion);
        $this->assertContains('personas: desconocido', json_decode($recomendacion->pendientes, true));
    }

    public function test_tipo_de_unidad_por_banderas_y_tipo(): void
    {
        $a6 = app(Coordinacion::class);

        $this->assertSame(['bomberos', []], $a6->tipoDeUnidad(0, ['gas']));
        $this->assertSame(['bomberos', []], $a6->tipoDeUnidad(5, []));
        $this->assertSame(['ambulancia', []], $a6->tipoDeUnidad(3, []));
        $this->assertSame(['ambulancia', []], $a6->tipoDeUnidad(0, ['respiratoria']));
        $this->assertSame(['bomberos', ['ambulancia']], $a6->tipoDeUnidad(1, ['atrapada', 'herido']));
        $this->assertSame([null, []], $a6->tipoDeUnidad(0, ['otro']));
    }

    public function test_una_unidad_ocupada_nunca_se_recomienda(): void
    {
        // U-03 (ocupada) está en La Enea; el reporte cae encima de ella.
        $payload = $this->payload(banderas: ['herido'], tipo: 3, lat: 5.0395, lon: -75.4670);
        $this->entregar($payload)->assertOk();

        $this->assertNotSame('U-03', $this->recomendacionDe($payload)->unidad_id);
        $this->assertSame('U-02', $this->recomendacionDe($payload)->unidad_id);
    }

    public function test_sin_ubicacion_recomienda_contactar_a_la_central(): void
    {
        $payload = $this->payload(banderas: ['atrapada'], tipo: 1, lat: null, lon: null);
        $this->entregar($payload)->assertOk();

        $recomendacion = $this->recomendacionDe($payload);
        $this->assertNull($recomendacion->unidad_id);
        $this->assertSame('contactar a la central', $recomendacion->accion);
        $this->assertContains('confirmar ubicación', json_decode($recomendacion->pendientes, true));
    }

    public function test_sin_unidad_disponible_escala_a_la_central(): void
    {
        DB::table('unidades')->where('tipo', 'ambulancia')->update(['estado_inicial' => 'ocupada']);
        $payload = $this->payload(banderas: ['herido'], tipo: 3);
        $this->entregar($payload)->assertOk();

        $recomendacion = $this->recomendacionDe($payload);
        $this->assertNull($recomendacion->unidad_id);
        $this->assertSame('escalar a la central', $recomendacion->accion);
        $this->assertContains('sin unidad disponible', json_decode($recomendacion->pendientes, true));
    }

    public function test_masivo_genera_una_nueva_version_de_la_recomendacion(): void
    {
        foreach ((new GeneradorSintetico(42))->masivo() as $payload) {
            $this->entregar($payload)->assertOk();
        }

        $versiones = DB::table('recomendaciones')->orderBy('version')->get();
        $this->assertGreaterThanOrEqual(2, $versiones->count());
        $this->assertTrue((bool) $versiones->last()->masivo_al_proponer);
        $this->assertSame('P2', $versiones->last()->prioridad_al_proponer);
    }

    public function test_aprobar_registra_la_decision_alerta_a_campo_y_asigna_la_unidad(): void
    {
        $payload = $this->payload(banderas: ['incendio'], tipo: 2);
        $this->entregar($payload)->assertOk();
        $recomendacion = $this->recomendacionDe($payload);

        $respuesta = $this->decidir(['recomendacion_uuid' => $recomendacion->uuid, 'accion' => 'aprobar'])->assertCreated();

        $alerta = json_decode(DB::table('eventos')->where('tipo', 'AlertaCampoEnviada')->value('payload'), true);
        $this->assertSame($respuesta->json('decision_uuid'), $alerta['decision_uuid']);
        $this->assertSame($recomendacion->unidad_id, $alerta['unidad_id']);
        $this->assertLessThanOrEqual(120, mb_strlen($alerta['texto']));
        $this->assertSame('asignada', DB::table('unidades_estado')->where('unidad_id', $recomendacion->unidad_id)->value('estado'));
        $this->assertSame('en_atencion', DB::table('incidentes')->where('uuid', $recomendacion->incidente_uuid)->value('estado'));

        $siguiente = $this->payload(banderas: ['incendio'], tipo: 2, lat: 5.0900, lon: -75.4930, deviceId: 99);
        $this->entregar($siguiente)->assertOk();
        $this->assertNotSame($recomendacion->unidad_id, $this->recomendacionDe($siguiente)->unidad_id, 'una unidad asignada no se vuelve a recomendar');
    }

    public function test_aprobar_una_recomendacion_cuya_unidad_ya_se_asigno_no_la_despacha_dos_veces(): void
    {
        // Dos incendios cerca de Milán: A6 propone Bomberos Estación 2 (U-06) para ambos.
        $primero = $this->payload(banderas: ['incendio'], tipo: 2, lat: 5.0485, lon: -75.4835, deviceId: 1);
        $segundo = $this->payload(banderas: ['incendio'], tipo: 2, lat: 5.0300, lon: -75.4835, deviceId: 2);
        $this->entregar($primero)->assertOk();
        $this->entregar($segundo)->assertOk();
        $viejaDelSegundo = $this->recomendacionDe($segundo);
        $this->assertSame('U-06', $this->recomendacionDe($primero)->unidad_id);
        $this->assertSame('U-06', $viejaDelSegundo->unidad_id);

        $this->decidir(['recomendacion_uuid' => $this->recomendacionDe($primero)->uuid, 'accion' => 'aprobar'])->assertCreated();

        // Al aprobar, A6 ya repropuso para el segundo incidente: la nueva versión no usa U-06.
        $this->assertNotSame('U-06', $this->recomendacionDe($segundo)->unidad_id);

        // Si alguien aprueba la versión vieja (pantalla sin refrescar), no se despacha dos veces.
        $this->decidir(['recomendacion_uuid' => $viejaDelSegundo->uuid, 'accion' => 'aprobar'])
            ->assertStatus(409)
            ->assertJsonPath('error', 'unidad_no_disponible');

        $nueva = $this->recomendacionDe($segundo);
        $this->assertSame((int) $viejaDelSegundo->version + 1, (int) $nueva->version);
        $this->assertNotSame('U-06', $nueva->unidad_id);
        $this->assertSame(1, DB::table('eventos')->where('tipo', 'AlertaCampoEnviada')->count());
        $this->assertSame(0, DB::table('decisiones')->where('recomendacion_uuid', $viejaDelSegundo->uuid)->count());
    }

    public function test_descartar_sin_motivo_responde_422_y_no_deja_decision(): void
    {
        $payload = $this->payload(banderas: ['incendio'], tipo: 2);
        $this->entregar($payload)->assertOk();

        $this->decidir(['recomendacion_uuid' => $this->recomendacionDe($payload)->uuid, 'accion' => 'descartar'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['motivo']);

        $this->assertSame(0, DB::table('eventos')->where('tipo', 'DecisionHumana')->count());
    }

    public function test_descartar_con_motivo_no_alerta_a_campo(): void
    {
        $payload = $this->payload(banderas: ['incendio'], tipo: 2);
        $this->entregar($payload)->assertOk();

        $this->decidir(['recomendacion_uuid' => $this->recomendacionDe($payload)->uuid, 'accion' => 'descartar', 'motivo' => 'ya atendido por radio'])
            ->assertCreated()
            ->assertJsonPath('alerta_uuid', null);

        $this->assertSame(0, DB::table('eventos')->where('tipo', 'AlertaCampoEnviada')->count());
    }

    public function test_dos_decisiones_se_conservan_y_el_incidente_queda_en_conflicto(): void
    {
        $payload = $this->payload(banderas: ['incendio'], tipo: 2);
        $this->entregar($payload)->assertOk();
        $recomendacion = $this->recomendacionDe($payload);

        $this->decidir(['recomendacion_uuid' => $recomendacion->uuid, 'accion' => 'aprobar'])->assertCreated();
        $this->decidir(['recomendacion_uuid' => $recomendacion->uuid, 'accion' => 'descartar', 'motivo' => 'duplicado', 'operador' => 'David'])
            ->assertCreated()
            ->assertJsonPath('en_conflicto', true);
        $this->decidir(['recomendacion_uuid' => $recomendacion->uuid, 'accion' => 'aprobar', 'operador' => 'Franklin'])->assertCreated();

        $this->assertSame(3, DB::table('eventos')->where('tipo', 'DecisionHumana')->count());
        $this->assertSame(1, DB::table('eventos')->where('tipo', 'AlertaCampoEnviada')->count(), 'nunca dos alertas por la misma recomendación');
        $this->assertSame('en_conflicto', DB::table('incidentes')->where('uuid', $recomendacion->incidente_uuid)->value('estado'));

        $detalle = $this->getJson("/api/incidentes/{$recomendacion->incidente_uuid}")->assertOk();
        $detalle->assertJsonCount(3, 'decisiones')->assertJsonPath('estado', 'en_conflicto')->assertJsonPath('recomendacion.decidida', true);
    }

    public function test_seguidor_no_acepta_decisiones(): void
    {
        $payload = $this->payload(banderas: ['incendio'], tipo: 2);
        $this->entregar($payload)->assertOk();
        $recomendacion = $this->recomendacionDe($payload);
        app(EstadoNodo::class)->seguir('C', 2);

        $this->decidir(['recomendacion_uuid' => $recomendacion->uuid, 'accion' => 'aprobar'])->assertStatus(409)->assertJsonPath('error', 'no_lider');
        $this->assertSame(0, DB::table('eventos')->where('tipo', 'DecisionHumana')->count());
    }

    public function test_ac016a_solo_la_decision_humana_puede_emitir_una_alerta_a_campo(): void
    {
        $emisores = collect(File::allFiles(app_path()))
            ->filter(fn ($archivo): bool => str_contains($archivo->getContents(), "append('AlertaCampoEnviada'"))
            ->map(fn ($archivo): string => str_replace('\\', '/', $archivo->getRelativePathname()))
            ->values()
            ->all();

        $this->assertSame(['Agents/A6/Coordinacion.php'], $emisores);

        $codigo = File::get(app_path('Agents/A6/Coordinacion.php'));
        $this->assertLessThan(
            strpos($codigo, "append('AlertaCampoEnviada'"),
            strpos($codigo, "append('DecisionHumana'"),
            'la alerta solo se emite después de registrar la DecisionHumana',
        );

        $llamadores = collect(File::allFiles(app_path()))
            ->filter(fn ($archivo): bool => str_contains($archivo->getContents(), '->decidir('))
            ->map(fn ($archivo): string => str_replace('\\', '/', $archivo->getRelativePathname()))
            ->values()
            ->all();
        $this->assertSame(['Http/Controllers/Tablero/DecisionController.php'], $llamadores);
    }
}
