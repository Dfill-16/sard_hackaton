<?php

namespace Tests\Feature;

use App\Channel\Aleatorio;
use App\Channel\Canal;
use App\Sard\Support\Tiempo;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Proceso CANAL (T-006): pérdida, latencia, desorden y ventanas reproducibles.
 */
final class CanalSimuladoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['sard.nodo_role' => 'CANAL']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function mensaje(string $cuerpo = 'hola', string $destino = 'B'): array
    {
        return [
            'origen' => 'A',
            'destino' => $destino,
            'ruta' => '/api/entrega/reporte',
            'content_type' => 'application/octet-stream',
            'cuerpo_b64' => base64_encode($cuerpo),
        ];
    }

    /** @return array<int, int> seq de los mensajes perdidos */
    private function perdidosTrasEnviar(int $cantidad): array
    {
        for ($i = 0; $i < $cantidad; $i++) {
            $this->postJson('/api/canal/enviar', $this->mensaje("m{$i}"))->assertStatus(202);
        }

        return DB::table('mensajes')->where('estado', Canal::ESTADO_PERDIDO)->orderBy('seq')->pluck('seq')->all();
    }

    public function test_u_es_reproducible_y_esta_entre_0_y_1(): void
    {
        $this->assertSame(Aleatorio::u(42, 7, 1), Aleatorio::u(42, 7, 1));
        $this->assertNotSame(Aleatorio::u(42, 7, 1), Aleatorio::u(43, 7, 1));

        for ($seq = 1; $seq <= 50; $seq++) {
            $valor = Aleatorio::u(42, $seq, 1);
            $this->assertGreaterThanOrEqual(0.0, $valor);
            $this->assertLessThanOrEqual(1.0, $valor);
        }
    }

    public function test_ac026a_misma_semilla_mismos_mensajes_perdidos(): void
    {
        $primeraCorrida = $this->perdidosTrasEnviar(50);

        DB::table('mensajes')->delete();
        $segundaCorrida = $this->perdidosTrasEnviar(50);

        $this->assertNotEmpty($primeraCorrida);
        $this->assertSame($primeraCorrida, $segundaCorrida);

        $esperados = array_values(array_filter(range(1, 50), fn (int $seq): bool => Aleatorio::u(42, $seq, Aleatorio::PERDIDA) < 0.30));
        $this->assertSame($esperados, $segundaCorrida);
    }

    public function test_enviar_responde_202_con_estado_y_calcula_deliver_at(): void
    {
        Carbon::setTestNow('2026-11-09 12:00:00.000');

        $respuesta = $this->postJson('/api/canal/enviar', $this->mensaje())->assertStatus(202);

        $this->assertContains($respuesta->json('estado'), ['en_cola', 'perdido']);
        $mensaje = DB::table('mensajes')->where('id', $respuesta->json('mensaje_id'))->first();
        $this->assertSame('hola', $mensaje->cuerpo);
        $this->assertSame(4, (int) $mensaje->bytes);

        $retrasoMs = Tiempo::desdeIso($mensaje->deliver_at)->diffInMilliseconds(Tiempo::desdeIso($mensaje->creado_en));
        $this->assertGreaterThanOrEqual(200, abs($retrasoMs));
        $this->assertLessThanOrEqual(6000, abs($retrasoMs));
    }

    public function test_mensaje_mayor_a_la_mtu_responde_413(): void
    {
        $this->postJson('/api/canal/enviar', $this->mensaje(str_repeat('x', 201)))
            ->assertStatus(413)
            ->assertJsonPath('error', 'mtu_excedida');

        $this->assertSame(0, DB::table('mensajes')->count());
    }

    public function test_rutas_del_canal_no_existen_en_otro_rol(): void
    {
        config(['sard.nodo_role' => 'B']);

        $this->postJson('/api/canal/enviar', $this->mensaje())->assertNotFound();
    }

    public function test_worker_entrega_al_destino_con_cabecera_de_origen(): void
    {
        config(['sard.canal.perdida' => 0, 'sard.canal.desorden' => 0, 'sard.canal.ventana_cerrada_s' => 0]);
        Http::fake(['*' => Http::response('', 200)]);

        $this->postJson('/api/canal/enviar', $this->mensaje('binario'))->assertStatus(202);

        $canal = app(Canal::class);
        $this->assertSame(0, $canal->despacharPendientes(Tiempo::milisegundos()), 'aún no vence la latencia');
        $this->assertSame(1, $canal->despacharPendientes(Tiempo::milisegundos(), Tiempo::ahora()->addSeconds(4)));

        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://127.0.0.1:8002/api/entrega/reporte'
            && $request->body() === 'binario'
            && $request->hasHeader('X-Sard-Origen', 'A')
            && $request->hasHeader('Content-Type', 'application/octet-stream'));

        $this->assertSame(1, $canal->estadisticas()['entregados']);
    }

    public function test_respuesta_no_2xx_marca_fallido_y_el_canal_no_reintenta(): void
    {
        config(['sard.canal.perdida' => 0, 'sard.canal.ventana_cerrada_s' => 0]);
        Http::fake(['*' => Http::response(['error' => 'no_lider'], 409)]);

        $this->postJson('/api/canal/enviar', $this->mensaje())->assertStatus(202);

        $canal = app(Canal::class);
        $canal->despacharPendientes(Tiempo::milisegundos(), Tiempo::ahora()->addSeconds(10));
        $canal->despacharPendientes(Tiempo::milisegundos(), Tiempo::ahora()->addSeconds(20));

        Http::assertSentCount(1);
        $this->assertSame(1, $canal->estadisticas()['fallidos']);
    }

    public function test_ventana_cerrada_pospone_hasta_la_proxima_apertura(): void
    {
        config(['sard.canal.perdida' => 0, 'sard.canal.desorden' => 0]);
        Http::fake(['*' => Http::response('', 200)]);
        Carbon::setTestNow('2026-11-09 12:00:00.000');
        $arranqueMs = Tiempo::milisegundos();

        $this->postJson('/api/canal/enviar', $this->mensaje())->assertStatus(202);

        $canal = app(Canal::class);
        $enVentanaCerrada = Tiempo::ahora()->addSeconds(8);
        $this->assertSame(0, $canal->despacharPendientes($arranqueMs, $enVentanaCerrada));
        Http::assertNothingSent();

        $mensaje = DB::table('mensajes')->first();
        $this->assertSame('2026-11-09T12:00:20.000Z', $mensaje->deliver_at, 'abierta 5 s + cerrada 15 s');

        $this->assertSame(1, $canal->despacharPendientes($arranqueMs, Tiempo::desdeIso('2026-11-09T12:00:20.500Z')));
        Http::assertSentCount(1);
    }

    public function test_estadisticas_cuentan_perdidos_reordenados_y_bytes(): void
    {
        $this->perdidosTrasEnviar(40);

        $estadisticas = $this->getJson('/api/canal/estadisticas')->assertOk()->json();

        $this->assertSame(40, $estadisticas['recibidos']);
        $this->assertGreaterThan(0, $estadisticas['perdidos']);
        $this->assertGreaterThan(0, $estadisticas['reordenados']);
        $this->assertSame(DB::table('mensajes')->sum('bytes'), $estadisticas['bytes']);
        $this->assertSame(['recibidos', 'perdidos', 'entregados', 'reordenados', 'fallidos', 'bytes', 'en_cola'], array_keys($estadisticas));
    }

    public function test_config_en_vivo_cambia_la_perdida_solo_con_demo(): void
    {
        $this->postJson('/api/canal/config', ['perdida' => 1])->assertNoContent();
        $this->postJson('/api/canal/enviar', $this->mensaje())->assertJsonPath('estado', 'perdido');

        $this->postJson('/api/canal/config', ['perdida' => 0])->assertNoContent();
        $this->postJson('/api/canal/enviar', $this->mensaje())->assertJsonPath('estado', 'en_cola');

        config(['sard.demo' => false]);
        $this->postJson('/api/canal/config', ['perdida' => 1])->assertForbidden();
    }
}
