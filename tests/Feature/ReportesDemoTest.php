<?php

namespace Tests\Feature;

use App\Node\A\GeneradorSintetico;
use Database\Seeders\ReportesDemoSeeder;
use Database\Seeders\SemillaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\EntregaReportes;
use Tests\TestCase;

/**
 * Reportes de demostración para el mapa: el escenario curado muestra cada caso del tablero.
 */
final class ReportesDemoTest extends TestCase
{
    use EntregaReportes;
    use RefreshDatabase;

    public function test_el_escenario_de_demostracion_llena_cada_seccion_del_tablero(): void
    {
        $this->comoLider();
        $this->seed(SemillaSeeder::class);

        foreach ((new GeneradorSintetico(42))->demostracion() as $payload) {
            $this->entregar($payload)->assertOk();
        }

        $estado = $this->getJson('/api/tablero/estado')->assertOk();
        $cola = collect($estado->json('cola'));

        $this->assertGreaterThanOrEqual(3, $cola->where('prioridad', 'P1')->count(), 'Chipre, Versalles y Delicias son P1');
        $this->assertTrue($cola->contains(fn (array $i): bool => $i['corroborado'] && $i['dispositivos_n'] === 2), 'Chipre: corroborado por 2 dispositivos');
        $this->assertTrue($cola->contains(fn (array $i): bool => $i['contradicciones_n'] > 0), 'Milán: 2 frente a 8 personas');
        $this->assertCount(1, $estado->json('sin_ubicacion'));
        $this->assertSame(['ambulancia'], collect($estado->json('brechas'))->pluck('unidad_requerida')->all(), 'La Enea: sin ambulancia a ≤ 3 km');
    }

    public function test_el_seeder_envia_los_64_reportes_al_nodo_a_como_la_pwa(): void
    {
        Http::fake(fn () => Http::response(str_repeat("\x00", 17), 200));

        $this->seed(ReportesDemoSeeder::class);

        $urlReportes = rtrim((string) config('sard.urls.A'), '/').'/api/reportes';
        Http::assertSentCount(8 + 20 + 6 + 30);
        Http::assertSent(fn (Request $peticion): bool => $peticion->url() === $urlReportes
            && $peticion->hasHeader('Content-Type', 'application/octet-stream'));
    }
}
