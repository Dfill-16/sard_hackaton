<?php

namespace Tests\Feature;

use Database\Seeders\SemillaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\EntregaReportes;
use Tests\TestCase;

/**
 * T-017: recursos y unidades en el mapa (datos de demostración) y brechas de cobertura.
 */
final class RecursosBrechasTest extends TestCase
{
    use EntregaReportes;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->comoLider();
        $this->seed(SemillaSeeder::class);
    }

    public function test_la_capa_trae_los_12_recursos_y_las_7_unidades_con_su_estado(): void
    {
        $estado = $this->getJson('/api/tablero/estado')->assertOk();

        $estado->assertJsonCount(12, 'recursos')->assertJsonCount(7, 'unidades');
        $unidades = collect($estado->json('unidades'))->keyBy('id');
        $this->assertSame('ocupada', $unidades['U-03']['estado']);
        $this->assertSame('disponible', $unidades['U-01']['estado']);
        $this->assertEqualsCanonicalizing(['Albergue', 'Acopio', 'Sangre'], collect($estado->json('recursos'))->pluck('tipo')->unique()->values()->all());
    }

    public function test_con_las_4_ambulancias_ocupadas_un_medico_p1_es_brecha(): void
    {
        DB::table('unidades')->where('tipo', 'ambulancia')->update(['estado_inicial' => 'ocupada']);
        $payload = $this->payload(banderas: ['respiratoria'], tipo: 3, deviceId: 1);
        $this->entregar($payload)->assertOk();

        $brechas = $this->getJson('/api/tablero/estado')->assertOk()->json('brechas');

        $this->assertCount(1, $brechas);
        $this->assertSame('P1', $brechas[0]['prioridad']);
        $this->assertSame('ambulancia', $brechas[0]['unidad_requerida']);
        $this->assertNull($brechas[0]['unidad_mas_cercana']);
    }

    public function test_incidente_lejos_de_toda_unidad_disponible_es_brecha_con_la_mas_cercana(): void
    {
        // Al nororiente, a más de 3 km de las tres estaciones de bomberos.
        $this->entregar($this->payload(banderas: ['incendio', 'atrapada'], tipo: 2, lat: 5.1200, lon: -75.4300, deviceId: 2))->assertOk();

        $brecha = $this->getJson('/api/tablero/estado')->assertOk()->json('brechas.0');

        $this->assertSame('bomberos', $brecha['unidad_requerida']);
        $this->assertNotNull($brecha['unidad_mas_cercana']);
        $this->assertGreaterThan(3000, $brecha['unidad_mas_cercana']['distancia_m']);
    }

    public function test_con_unidad_disponible_cerca_no_hay_brecha(): void
    {
        $this->entregar($this->payload(banderas: ['atrapada'], tipo: 1, lat: 5.0558, lon: -75.4930, deviceId: 3))->assertOk();

        $this->getJson('/api/tablero/estado')->assertOk()->assertJsonCount(0, 'brechas');
    }

    public function test_sin_ubicacion_y_baja_prioridad_no_son_brechas(): void
    {
        DB::table('unidades')->update(['estado_inicial' => 'ocupada']);
        $this->entregar($this->payload(banderas: ['atrapada'], tipo: 1, lat: null, lon: null, deviceId: 4))->assertOk();
        $this->entregar($this->payload(banderas: ['via'], tipo: 6, deviceId: 5))->assertOk();

        $this->getJson('/api/tablero/estado')->assertOk()->assertJsonCount(0, 'brechas');
    }

    public function test_una_unidad_aprobada_aparece_asignada(): void
    {
        $payload = $this->payload(banderas: ['incendio'], tipo: 2, deviceId: 6);
        $this->entregar($payload)->assertOk();
        $recomendacion = DB::table('recomendaciones')->first();
        $this->postJson('/api/decisiones', ['recomendacion_uuid' => $recomendacion->uuid, 'accion' => 'aprobar', 'operador' => 'Mariana'])->assertCreated();

        $unidades = collect($this->getJson('/api/tablero/estado')->assertOk()->json('unidades'))->keyBy('id');
        $this->assertSame('asignada', $unidades[$recomendacion->unidad_id]['estado']);
    }

    public function test_el_detalle_muestra_el_albergue_mas_cercano(): void
    {
        $this->entregar($this->payload(banderas: ['derrumbe'], tipo: 1, lat: 5.0610, lon: -75.4955, deviceId: 7))->assertOk();
        $uuid = DB::table('incidentes')->value('uuid');

        $this->getJson("/api/incidentes/{$uuid}")->assertOk()
            ->assertJsonPath('albergue_cercano.id', 'R-01')
            ->assertJsonPath('albergue_cercano.nombre', 'Coliseo Mayor');
    }
}
