<?php

namespace Tests\Feature;

use App\Agents\A4\Comunicacion;
use App\Channel\Canal;
use App\Node\A\GeneradorSintetico;
use App\Protocol\PayloadReporte;
use App\Sard\Support\Tiempo;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\EntregaReportes;
use Tests\TestCase;

/**
 * A4 en el Nodo A (T-006): orden por puntaje, envejecimiento, backoff y confirmación por ACK.
 */
final class A4ComunicacionTest extends TestCase
{
    use EntregaReportes;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function ingresarEnA(string $payload): void
    {
        $this->call('POST', '/api/reportes', [], [], [], ['CONTENT_TYPE' => 'application/octet-stream'], $payload)
            ->assertOk();
    }

    /** @return array<int, string> uuids enviados al canal, en orden */
    private function enviadosAlCanal(): array
    {
        return Http::recorded()
            ->map(fn (array $par): string => PayloadReporte::decode(base64_decode($par[0]['cuerpo_b64']))['uuid'])
            ->all();
    }

    public function test_ac025a_un_p1_sale_antes_que_un_p4_encolado_antes(): void
    {
        Http::fake(['*' => Http::response(['mensaje_id' => 'x', 'estado' => 'en_cola'], 202)]);
        Carbon::setTestNow('2026-11-09 12:00:00');

        $p4 = $this->payload(texto: 'necesitamos informacion', tipo: 0);
        $this->ingresarEnA($p4);

        Carbon::setTestNow('2026-11-09 12:00:30');
        $p1 = $this->payload(banderas: ['atrapada'], texto: 'hay gente atrapada', personas: 3);
        $this->ingresarEnA($p1);

        $this->assertSame(2, app(Comunicacion::class)->ciclo());

        $this->assertSame([$this->uuidDe($p1), $this->uuidDe($p4)], $this->enviadosAlCanal());
        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://127.0.0.1:8010/api/canal/enviar'
            && $request['destino'] === 'B'
            && $request['ruta'] === '/api/entrega/reporte'
            && $request['content_type'] === 'application/octet-stream');
    }

    public function test_empate_de_puntaje_sale_primero_el_mas_corto(): void
    {
        Http::fake(['*' => Http::response(['mensaje_id' => 'x', 'estado' => 'en_cola'], 202)]);

        $largo = $this->payload(banderas: ['incendio'], texto: 'incendio en el segundo piso del edificio');
        $corto = $this->payload(banderas: ['incendio']);
        $this->ingresarEnA($largo);
        $this->ingresarEnA($corto);

        app(Comunicacion::class)->ciclo();

        $this->assertSame([$this->uuidDe($corto), $this->uuidDe($largo)], $this->enviadosAlCanal());
    }

    public function test_ac014a_un_p4_sigue_en_la_cola_y_su_puntaje_efectivo_sube(): void
    {
        Http::fake(['*' => Http::response(['mensaje_id' => 'x', 'estado' => 'perdido'], 202)]);
        Carbon::setTestNow('2026-11-09 12:00:00');
        $this->ingresarEnA($this->payload(texto: '', tipo: 0));

        $a4 = app(Comunicacion::class);
        $fila = DB::table('cola_salida')->first();
        $this->assertSame(0, $a4->puntajeEfectivo(0, $fila->recibido_en, Tiempo::ahora()));
        $this->assertSame(2, $a4->puntajeEfectivo(0, $fila->recibido_en, Tiempo::ahora()->addMinutes(11)));
        $this->assertSame(5, $a4->puntajeEfectivo(0, $fila->recibido_en, Tiempo::ahora()->addHours(3)), 'tope +5');

        for ($minuto = 0; $minuto < 60; $minuto++) {
            $a4->ciclo(Tiempo::ahora()->addMinutes($minuto));
        }

        $fila = DB::table('cola_salida')->first();
        $this->assertNull($fila->confirmado_en);
        $this->assertGreaterThan(5, (int) $fila->intentos, 'sin ACK se reintenta indefinidamente');
    }

    public function test_backoff_reprograma_el_siguiente_intento_con_jitter(): void
    {
        Http::fake(['*' => Http::response(['mensaje_id' => 'x', 'estado' => 'en_cola'], 202)]);
        Carbon::setTestNow('2026-11-09 12:00:00');
        $this->ingresarEnA($this->payload(banderas: ['herido']));

        $a4 = app(Comunicacion::class);
        $a4->ciclo();

        $fila = DB::table('cola_salida')->first();
        $this->assertSame(1, (int) $fila->intentos);
        $this->assertSame('B', $fila->enviado_a);
        $this->assertSame('1', $fila->epoca_envio);
        $this->assertSame(4, (int) $fila->puntaje_base);
        $esperaMs = Tiempo::ahora()->diffInMilliseconds(Tiempo::desdeIso($fila->proximo_intento));
        $this->assertEqualsWithDelta(1000, $esperaMs, 200);

        $this->assertSame(0, $a4->ciclo(), 'no se reenvía antes de proximo_intento');

        foreach ([2 => 2000, 3 => 4000, 4 => 8000, 5 => 16000, 6 => 30000, 12 => 30000] as $intentos => $baseMs) {
            $espera = $a4->esperaMs($intentos);
            $this->assertGreaterThanOrEqual((int) ($baseMs * 0.8), $espera);
            $this->assertLessThanOrEqual((int) ($baseMs * 1.2), $espera);
        }
    }

    public function test_ack_confirma_una_sola_vez_y_es_idempotente(): void
    {
        $payload = $this->payload();
        $this->ingresarEnA($payload);
        $uuid = $this->uuidDe($payload);

        $this->postJson('/api/acks', ['reporte_uuid' => $uuid, 'nodo' => 'B', 'epoca' => 1])->assertNoContent();
        $primera = DB::table('cola_salida')->first();
        $this->postJson('/api/acks', ['reporte_uuid' => $uuid, 'nodo' => 'C', 'epoca' => 2])->assertNoContent();
        $segunda = DB::table('cola_salida')->first();

        $this->assertSame('B', $segunda->confirmado_por);
        $this->assertSame($primera->confirmado_en, $segunda->confirmado_en);
        $this->assertSame(1, $this->getJson('/api/metricas')->json('confirmados_por_lider'));
        $this->assertSame(0, $this->getJson('/api/salud')->json('cola_pendiente'));

        Http::fake();
        $this->assertSame(0, app(Comunicacion::class)->ciclo(Tiempo::ahora()->addHour()), 'un confirmado no vuelve a salir');
    }

    /**
     * AC-004b: con PERDIDA 0.30 (ida y vuelta), 50 reportes sintéticos quedan confirmados en ≤ 5 min.
     *
     * Simula el sistema completo con el reloj de pruebas: A4 real, canal real (tabla `mensajes`,
     * semilla 42, latencia, desorden y ventanas 5/15 s) y un receptor B de prueba que solo responde ACK.
     */
    public function test_ac004b_50_reportes_confirmados_en_cinco_minutos_con_perdida(): void
    {
        Carbon::setTestNow('2026-11-09 12:00:00.000');
        $inicio = Tiempo::ahora();

        foreach ((new GeneradorSintetico(42))->dispersos(50) as $payload) {
            $this->ingresarEnA($payload);
        }

        $canal = app(Canal::class);
        $recibidosEnB = [];
        $entregasEnB = 0;

        Http::fake(function (Request $request) use ($canal, &$recibidosEnB, &$entregasEnB) {
            if (str_ends_with($request->url(), '/api/canal/enviar')) {
                return Http::response($canal->encolar($request->data(), base64_decode($request['cuerpo_b64'])), 202);
            }

            if (str_ends_with($request->url(), '/api/entrega/reporte')) {
                $entregasEnB++;
                $uuid = PayloadReporte::decode($request->body())['uuid'];
                $recibidosEnB[$uuid] = true;
                $canal->encolar(
                    ['origen' => 'B', 'destino' => 'A', 'ruta' => '/api/acks', 'content_type' => 'application/json'],
                    (string) json_encode(['reporte_uuid' => $uuid, 'nodo' => 'B', 'epoca' => 1]),
                );

                return Http::response(['reporte_uuid' => $uuid], 200);
            }

            if (str_ends_with($request->url(), '/api/acks')) {
                $this->postJson('/api/acks', json_decode($request->body(), true))->assertNoContent();

                return Http::response('', 204);
            }

            return Http::response('', 404);
        });

        $a4 = app(Comunicacion::class);
        $arranqueMs = Tiempo::milisegundos($inicio);
        $limite = $inicio->addMinutes(5);
        $confirmadosEn = null;

        for ($momento = $inicio; $momento <= $limite; $momento = $momento->addMilliseconds(250)) {
            Carbon::setTestNow($momento);
            $a4->ciclo($momento);
            $canal->despacharPendientes($arranqueMs, $momento);

            if (DB::table('cola_salida')->whereNull('confirmado_en')->doesntExist()) {
                $confirmadosEn = $momento;
                break;
            }
        }

        $estadisticas = $canal->estadisticas();
        $this->assertNotNull($confirmadosEn, 'quedaron sin confirmar: '.DB::table('cola_salida')->whereNull('confirmado_en')->count());
        $this->assertSame(50, DB::table('cola_salida')->whereNotNull('confirmado_en')->count());
        $this->assertCount(50, $recibidosEnB, 'el receptor guarda cada UUID una sola vez');
        $this->assertGreaterThan(0, $estadisticas['perdidos']);
        $this->assertGreaterThanOrEqual(50, $entregasEnB);

        fwrite(STDOUT, sprintf(
            "\n[AC-004b] semilla 42: 50/50 confirmados en %ds · canal %s · entregas en B %d\n",
            $inicio->diffInSeconds($confirmadosEn),
            json_encode($estadisticas),
            $entregasEnB,
        ));
    }
}
