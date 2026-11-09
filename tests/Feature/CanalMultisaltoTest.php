<?php

namespace Tests\Feature;

use App\Channel\Aleatorio;
use App\Channel\Canal;
use App\Sard\Support\Tiempo;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * T-018: canal de 2 o 3 saltos; cada salto aplica su propia pérdida, latencia y desorden.
 */
final class CanalMultisaltoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'sard.nodo_role' => 'CANAL',
            'sard.canal.desorden' => 0,
            'sard.canal.ventana_cerrada_s' => 0,
        ]);
        Http::fake(['*' => Http::response('', 200)]);
        Carbon::setTestNow('2026-11-09 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Encola $cantidad mensajes y deja correr el canal hasta que ninguno siga en tránsito. */
    private function correr(int $cantidad): void
    {
        $canal = app(Canal::class);
        for ($i = 0; $i < $cantidad; $i++) {
            $canal->encolar(['origen' => 'A', 'destino' => 'B', 'ruta' => '/api/entrega/reporte', 'content_type' => 'application/octet-stream'], "m{$i}");
        }

        $arranqueMs = Tiempo::milisegundos();
        for ($paso = 1; $paso <= 60 && DB::table('mensajes')->where('estado', Canal::ESTADO_EN_COLA)->exists(); $paso++) {
            $canal->despacharPendientes($arranqueMs, Tiempo::ahora()->addSeconds(4 * $paso));
        }
    }

    public function test_con_3_saltos_y_pérdida_0_2_la_pérdida_efectiva_se_acerca_a_1_menos_0_8_al_cubo(): void
    {
        config(['sard.canal.saltos' => 3, 'sard.canal.perdida' => 0.2]);

        $this->correr(400);

        $estadisticas = app(Canal::class)->estadisticas();
        $efectiva = $estadisticas['perdidos'] / $estadisticas['recibidos'];
        $this->assertEqualsWithDelta(1 - 0.8 ** 3, $efectiva, 0.07);
        $this->assertSame(400, $estadisticas['perdidos'] + $estadisticas['entregados']);
        $this->assertCount(3, $estadisticas['perdidos_por_salto']);
        $this->assertSame($estadisticas['perdidos'], array_sum($estadisticas['perdidos_por_salto']));
        $this->assertGreaterThan($estadisticas['perdidos_por_salto'][2], $estadisticas['perdidos_por_salto'][0], 'cada salto pierde sobre lo que sobrevivió al anterior');

        fwrite(STDOUT, sprintf("\n[T-018] 3 saltos · pérdida 0.2/salto · efectiva %.3f (teórica %.3f) · por salto %s\n", $efectiva, 1 - 0.8 ** 3, json_encode($estadisticas['perdidos_por_salto'])));
    }

    public function test_misma_semilla_mismo_resultado_por_salto(): void
    {
        config(['sard.canal.saltos' => 2, 'sard.canal.perdida' => 0.3]);

        $this->correr(80);
        $primera = DB::table('mensajes')->orderBy('seq')->pluck('perdido_en_salto', 'seq')->all();

        DB::table('mensajes')->delete();
        $this->correr(80);
        $segunda = DB::table('mensajes')->orderBy('seq')->pluck('perdido_en_salto', 'seq')->all();

        $this->assertSame($primera, $segunda);
    }

    public function test_con_1_salto_el_comportamiento_es_identico_a_t006(): void
    {
        config(['sard.canal.saltos' => 1, 'sard.canal.perdida' => 0.3]);

        $this->correr(50);

        $esperados = array_values(array_filter(range(1, 50), fn (int $seq): bool => Aleatorio::u(42, $seq, Aleatorio::PERDIDA) < 0.3));
        $this->assertSame($esperados, DB::table('mensajes')->where('estado', Canal::ESTADO_PERDIDO)->orderBy('seq')->pluck('seq')->all());
        $this->assertSame([count($esperados)], app(Canal::class)->estadisticas()['perdidos_por_salto']);
    }

    public function test_solo_se_entrega_al_destino_tras_el_ultimo_salto(): void
    {
        config(['sard.canal.saltos' => 3, 'sard.canal.perdida' => 0]);
        $canal = app(Canal::class);
        $canal->encolar(['origen' => 'A', 'destino' => 'B', 'ruta' => '/api/entrega/reporte', 'content_type' => 'application/octet-stream'], 'x');
        $arranqueMs = Tiempo::milisegundos();

        $canal->despacharPendientes($arranqueMs, Tiempo::ahora()->addSeconds(4));
        $canal->despacharPendientes($arranqueMs, Tiempo::ahora()->addSeconds(8));
        Http::assertNothingSent();
        $this->assertSame(3, (int) DB::table('mensajes')->value('salto_actual'));

        $canal->despacharPendientes($arranqueMs, Tiempo::ahora()->addSeconds(12));
        Http::assertSentCount(1);
        $this->assertSame(Canal::ESTADO_ENTREGADO, DB::table('mensajes')->value('estado'));
    }

    public function test_cambiar_los_saltos_en_vivo_no_afecta_a_los_mensajes_en_transito(): void
    {
        $this->postJson('/api/canal/enviar', ['origen' => 'A', 'destino' => 'B', 'ruta' => '/x', 'content_type' => 'text/plain', 'cuerpo_b64' => base64_encode('antes')])->assertStatus(202);
        $this->postJson('/api/canal/config', ['saltos' => 3])->assertNoContent();
        $this->postJson('/api/canal/enviar', ['origen' => 'A', 'destino' => 'B', 'ruta' => '/x', 'content_type' => 'text/plain', 'cuerpo_b64' => base64_encode('despues')])->assertStatus(202);

        $this->assertSame([1, 3], DB::table('mensajes')->orderBy('seq')->pluck('saltos')->map(fn ($s): int => (int) $s)->all());
        $this->getJson('/api/canal/estadisticas')->assertOk()->assertJsonPath('saltos', 3)->assertJsonCount(3, 'perdidos_por_salto');
        $this->postJson('/api/canal/config', ['saltos' => 4])->assertUnprocessable();
    }
}
