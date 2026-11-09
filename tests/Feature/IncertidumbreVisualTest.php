<?php

namespace Tests\Feature;

use App\Node\A\GeneradorSintetico;
use Database\Seeders\SemillaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\EntregaReportes;
use Tests\TestCase;

/**
 * T-014: el tablero distingue lo corroborado de lo dudoso y muestra las fuentes, sin ocultar nada.
 */
final class IncertidumbreVisualTest extends TestCase
{
    use EntregaReportes;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->comoLider();
        $this->seed(SemillaSeeder::class);

        $generador = new GeneradorSintetico(42);
        foreach ([...$generador->masivo(), ...$generador->rumor()] as $payload) {
            $this->entregar($payload)->assertOk();
        }
    }

    /** @return array<string, mixed> */
    private function incidente(string $clave): array
    {
        $todos = collect($this->getJson('/api/tablero/estado?todos=1')->assertOk()->json())
            ->only(['cola', 'baja_prioridad'])
            ->flatten(1);

        return $todos->firstWhere($clave, true);
    }

    public function test_masivo_corroborado_y_rumor_dudoso_en_el_estado(): void
    {
        $masivo = $this->incidente('masivo');
        $rumor = $this->incidente('posible_rumor');

        $this->assertTrue($masivo['corroborado']);
        $this->assertGreaterThanOrEqual(0.6, $masivo['confianza']);
        $this->assertFalse($rumor['corroborado']);
        $this->assertLessThan(0.6, $rumor['confianza']);

        $this->assertGreaterThanOrEqual(60, $masivo['radio_m']);
        $this->assertLessThanOrEqual(200, $masivo['radio_m'], 'los 6 reportes del masivo están a ≤ 90 m del centro');
    }

    public function test_ac008a_detalle_del_masivo_con_6_fuentes_distintas(): void
    {
        $detalle = $this->getJson('/api/incidentes/'.$this->incidente('masivo')['uuid'])->assertOk();

        $detalle->assertJsonCount(6, 'fuentes')
            ->assertJsonPath('corroborado', true)
            ->assertJsonStructure(['fuentes' => [['device_id', 'reportes_n', 'primero', 'ultimo']]]);
        $this->assertSame([1, 1, 1, 1, 1, 1], array_column($detalle->json('fuentes'), 'reportes_n'));
    }

    public function test_ac006a_el_rumor_muestra_1_dispositivo_con_30_reportes(): void
    {
        $detalle = $this->getJson('/api/incidentes/'.$this->incidente('posible_rumor')['uuid'])->assertOk();

        $detalle->assertJsonCount(1, 'fuentes')
            ->assertJsonPath('fuentes.0.reportes_n', 30)
            ->assertJsonPath('posible_rumor', true)
            ->assertJsonPath('corroborado', false);
        $this->assertLessThanOrEqual($detalle->json('fuentes.0.ultimo'), $detalle->json('fuentes.0.primero'));
    }

    public function test_sin_ubicacion_no_tiene_radio(): void
    {
        $this->entregar($this->payload(banderas: ['herido'], tipo: 3, lat: null, lon: null, deviceId: 77))->assertOk();

        $sinUbicacion = $this->getJson('/api/tablero/estado')->assertOk()->json('sin_ubicacion.0');
        $this->assertNull($sinUbicacion['radio_m']);
        $this->assertArrayHasKey('corroborado', $sinUbicacion);
    }

    public function test_la_vista_incluye_la_leyenda_y_el_panel_de_fuentes(): void
    {
        $this->withoutVite();

        $this->get('/tablero')->assertOk()
            ->assertSee('Sólido = corroborado (≥ 60 %) · Punteado = dudoso', false)
            ->assertSee('titulo-fuentes', false);
        $this->assertSame(2, DB::table('incidentes')->count());
    }
}
