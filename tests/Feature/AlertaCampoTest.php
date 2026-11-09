<?php

namespace Tests\Feature;

use App\Agents\A6\EnvioAlertas;
use App\Channel\Canal;
use App\Node\D\Acuses;
use App\Node\D\MensajeAlerta;
use App\Sard\Nodo\EstadoNodo;
use App\Sard\Support\Tiempo;
use App\Sard\Support\Uuid7;
use Carbon\Carbon;
use Database\Seeders\SemillaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\EntregaReportes;
use Tests\TestCase;

/**
 * T-015: la alerta aprobada viaja por el canal al Nodo D y el acuse «Recibido» vuelve al líder.
 */
final class AlertaCampoTest extends TestCase
{
    use EntregaReportes;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->comoLider('B');
        $this->seed(SemillaSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Un incidente con recomendación aprobada: devuelve la fila de `alertas`. */
    private function alertaAprobada(): object
    {
        $this->entregar($this->payload(banderas: ['incendio'], tipo: 2))->assertOk();
        $recomendacion = DB::table('recomendaciones')->first();
        $this->postJson('/api/decisiones', ['recomendacion_uuid' => $recomendacion->uuid, 'accion' => 'aprobar', 'operador' => 'Mariana'])->assertCreated();

        return DB::table('alertas')->first();
    }

    /** @return array<int, Request> mensajes enviados al canal con esa ruta */
    private function enviadosAlCanal(string $ruta): array
    {
        return Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/api/canal/enviar') && $request['ruta'] === $ruta)
            ->map(fn (array $par): Request => $par[0])
            ->values()
            ->all();
    }

    private function comoNodoD(): void
    {
        config(['sard.nodo_role' => 'D']);
    }

    public function test_mensaje_de_alerta_cabe_en_la_mtu_aun_con_texto_largo_y_tildes(): void
    {
        $alerta = [
            'alerta_uuid' => Uuid7::generar(),
            'incidente_uuid' => Uuid7::generar(),
            'unidad_id' => 'U-06',
            'texto' => str_repeat('Atención: derrumbe con heridos en la vía ', 3),
            'enviada_en' => '2026-11-09T12:00:00.123Z',
        ];

        $cuerpo = MensajeAlerta::codificar($alerta, 200);
        $vuelta = MensajeAlerta::decodificar($cuerpo);

        $this->assertLessThanOrEqual(200, strlen($cuerpo));
        $this->assertStringEndsWith(MensajeAlerta::ELIPSIS, $vuelta['texto']);
        $this->assertSame([$alerta['alerta_uuid'], $alerta['incidente_uuid'], 'U-06', '2026-11-09T12:00:00.123Z'],
            [$vuelta['alerta_uuid'], $vuelta['incidente_uuid'], $vuelta['unidad_id'], $vuelta['enviada_en']]);

        $corto = MensajeAlerta::decodificar(MensajeAlerta::codificar([...$alerta, 'texto' => 'P2 Inundación · 6 rep']));
        $this->assertSame('P2 Inundación · 6 rep', $corto['texto']);
    }

    public function test_ac016a_sin_decision_humana_no_sale_ninguna_alerta_hacia_d(): void
    {
        $this->entregar($this->payload(banderas: ['incendio'], tipo: 2))->assertOk();

        $this->assertSame(0, app(EnvioAlertas::class)->ciclo());
        $this->assertSame([], $this->enviadosAlCanal(EnvioAlertas::RUTA_ALERTA));
    }

    public function test_el_lider_envia_la_alerta_a_d_y_reintenta_con_backoff_hasta_el_acuse(): void
    {
        Carbon::setTestNow('2026-11-09 12:00:00');
        $alerta = $this->alertaAprobada();
        $envio = app(EnvioAlertas::class);

        $this->assertSame(1, $envio->ciclo());
        $this->assertSame(0, $envio->ciclo(), 'no reenvía antes del backoff');
        $this->assertSame(1, $envio->ciclo(Tiempo::ahora()->addSeconds(2)));

        $enviada = $this->enviadosAlCanal(EnvioAlertas::RUTA_ALERTA)[0];
        $this->assertSame('D', $enviada['destino']);
        $cuerpo = base64_decode($enviada['cuerpo_b64']);
        $this->assertLessThanOrEqual(200, strlen($cuerpo));
        $this->assertSame($alerta->uuid, MensajeAlerta::decodificar($cuerpo)['alerta_uuid']);

        $this->call('POST', '/api/entrega/ack-campo', [], [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['alerta_uuid' => $alerta->uuid, 'recibido_en_campo' => '2026-11-09T12:00:05.000Z']))->assertNoContent();

        $this->assertSame(0, $envio->ciclo(Tiempo::ahora()->addMinutes(5)), 'con acuse ya no se reenvía');
    }

    public function test_un_ack_duplicado_no_crea_un_segundo_ackcampo_pero_siempre_confirma_a_d(): void
    {
        $alerta = $this->alertaAprobada();
        $cuerpo = json_encode(['alerta_uuid' => $alerta->uuid, 'recibido_en_campo' => '2026-11-09T12:00:05.000Z']);

        $this->call('POST', '/api/entrega/ack-campo', [], [], [], ['CONTENT_TYPE' => 'application/json'], $cuerpo)->assertNoContent();
        $this->call('POST', '/api/entrega/ack-campo', [], [], [], ['CONTENT_TYPE' => 'application/json'], $cuerpo)->assertNoContent();

        $this->assertSame(1, DB::table('eventos')->where('tipo', 'AckCampo')->count());
        $this->assertCount(2, $this->enviadosAlCanal(EnvioAlertas::RUTA_ACK_RECIBIDO));
        $this->assertNotNull(DB::table('alertas')->value('ack_en'));

        $incidente = DB::table('alertas')->value('incidente_uuid');
        $this->getJson("/api/incidentes/{$incidente}")->assertOk()->assertJsonPath('alertas.0.ack_en', DB::table('alertas')->value('ack_en'));
        $tarjeta = collect($this->getJson('/api/tablero/estado?todos=1')->json())->only(['cola', 'baja_prioridad'])->flatten(1)->firstWhere('uuid', $incidente);
        $this->assertNotNull($tarjeta['alerta']['ack_en']);
    }

    public function test_un_seguidor_rechaza_el_acuse_y_una_alerta_desconocida_da_404(): void
    {
        $alerta = $this->alertaAprobada();
        $cuerpo = json_encode(['alerta_uuid' => $alerta->uuid, 'recibido_en_campo' => '2026-11-09T12:00:05.000Z']);

        $this->call('POST', '/api/entrega/ack-campo', [], [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['alerta_uuid' => Uuid7::generar(), 'recibido_en_campo' => 'x']))->assertNotFound();

        app(EstadoNodo::class)->seguir('C', 2);
        $this->call('POST', '/api/entrega/ack-campo', [], [], [], ['CONTENT_TYPE' => 'application/json'], $cuerpo)->assertStatus(409);
        $this->assertSame(0, DB::table('eventos')->where('tipo', 'AckCampo')->count());
    }

    public function test_d_guarda_la_alerta_una_vez_y_el_acuse_sale_hacia_el_lider(): void
    {
        $alerta = $this->alertaAprobada();
        $cuerpo = MensajeAlerta::codificar((array) ['alerta_uuid' => $alerta->uuid, 'incidente_uuid' => $alerta->incidente_uuid,
            'unidad_id' => $alerta->unidad_id, 'texto' => $alerta->texto, 'enviada_en' => $alerta->enviada_en]);
        $this->comoNodoD();

        $this->call('POST', '/api/entrega/alerta', [], [], [], ['CONTENT_TYPE' => 'application/json'], $cuerpo)->assertNoContent();
        $this->call('POST', '/api/entrega/alerta', [], [], [], ['CONTENT_TYPE' => 'application/json'], $cuerpo)->assertNoContent();
        $this->assertSame(1, DB::table('alertas_campo')->count());

        $this->getJson('/api/campo/alertas')->assertOk()
            ->assertJsonPath('alertas.0.uuid', $alerta->uuid)
            ->assertJsonPath('alertas.0.ack_en', null);

        $this->postJson('/api/campo/ack', ['alerta_uuid' => $alerta->uuid])->assertStatus(202);
        $acuse = $this->enviadosAlCanal(Acuses::RUTA_ACK_CAMPO);
        $this->assertCount(1, $acuse);
        $this->assertSame('B', $acuse[0]['destino']);
        $this->assertSame($alerta->uuid, json_decode(base64_decode($acuse[0]['cuerpo_b64']), true)['alerta_uuid']);

        $this->call('POST', '/api/entrega/ack-recibido', [], [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['a' => str_replace('-', '', $alerta->uuid)]))->assertNoContent();
        $this->assertNotNull(DB::table('alertas_campo')->value('confirmado_en'));
        $this->assertSame(0, app(Acuses::class)->ciclo(Tiempo::ahora()->addHour()), 'confirmado: no se reintenta');

        $this->postJson('/api/campo/ack', ['alerta_uuid' => Uuid7::generar()])->assertNotFound();
    }

    public function test_si_cambia_el_lider_d_envia_el_acuse_al_nuevo_lider(): void
    {
        $alerta = $this->alertaAprobada();
        $this->comoNodoD();
        app(Acuses::class)->recibir(['alerta_uuid' => $alerta->uuid, 'incidente_uuid' => $alerta->incidente_uuid,
            'unidad_id' => $alerta->unidad_id, 'texto' => $alerta->texto, 'enviada_en' => $alerta->enviada_en]);
        DB::table('alertas_campo')->update(['ack_en' => Tiempo::iso(), 'ack_intentos' => 5, 'ack_proximo_intento' => Tiempo::iso(Tiempo::ahora()->addMinute())]);

        $this->postJson('/api/lider', ['nodo' => 'C', 'epoca' => 1])->assertStatus(409);
        $this->postJson('/api/lider', ['nodo' => 'C', 'epoca' => 2], ['X-Sard-Epoca' => '2'])->assertNoContent();

        $this->assertSame(1, app(Acuses::class)->ciclo());
        $ultimo = collect($this->enviadosAlCanal(Acuses::RUTA_ACK_CAMPO))->last();
        $this->assertSame('C', $ultimo['destino']);
    }

    public function test_con_perdida_del_30_por_ciento_la_alerta_llega_y_el_acuse_vuelve(): void
    {
        Carbon::setTestNow('2026-11-09 12:00:00.000');
        $alerta = $this->alertaAprobada();
        $canal = app(Canal::class);
        $envio = app(EnvioAlertas::class);
        $acuses = app(Acuses::class);

        Http::swap(new Factory);
        Http::fake(function (Request $request) use ($canal) {
            if (str_ends_with($request->url(), '/api/canal/enviar')) {
                return Http::response($canal->encolar($request->data(), base64_decode($request['cuerpo_b64'])), 202);
            }

            $nodo = str_starts_with($request->url(), 'http://127.0.0.1:8004') ? 'D' : 'B';
            $rolAnterior = config('sard.nodo_role');
            config(['sard.nodo_role' => $nodo]);
            $respuesta = $this->call('POST', parse_url($request->url(), PHP_URL_PATH), [], [], [], ['CONTENT_TYPE' => 'application/json'], $request->body());
            config(['sard.nodo_role' => $rolAnterior]);

            return Http::response($respuesta->getContent(), $respuesta->getStatusCode());
        });

        $arranqueMs = Tiempo::milisegundos();
        $inicio = Tiempo::ahora();
        for ($momento = $inicio; $momento <= $inicio->addMinutes(5); $momento = $momento->addMilliseconds(500)) {
            Carbon::setTestNow($momento);
            $envio->ciclo($momento);
            $acuses->ciclo($momento);
            $canal->despacharPendientes($arranqueMs, $momento);

            // El equipo de campo toca «Recibido» en cuanto ve la alerta.
            if (DB::table('alertas_campo')->whereNull('ack_en')->exists()) {
                config(['sard.nodo_role' => 'D']);
                $this->postJson('/api/campo/ack', ['alerta_uuid' => $alerta->uuid])->assertStatus(202);
                config(['sard.nodo_role' => 'B']);
            }

            if (DB::table('alertas')->whereNotNull('ack_en')->exists() && DB::table('alertas_campo')->whereNotNull('confirmado_en')->exists()) {
                break;
            }
        }

        $this->assertNotNull(DB::table('alertas')->value('ack_en'), 'el líder recibió el acuse');
        $this->assertNotNull(DB::table('alertas_campo')->value('confirmado_en'), 'D recibió la confirmación');
        $this->assertSame(1, DB::table('eventos')->where('tipo', 'AckCampo')->count());
        $this->assertGreaterThan(0, $canal->estadisticas()['perdidos']);

        fwrite(STDOUT, sprintf("\n[T-015] pérdida 0.30: acuse en %ds · canal %s\n",
            $inicio->diffInSeconds(Tiempo::desdeIso(DB::table('alertas')->value('ack_en'))), json_encode($canal->estadisticas())));
    }
}
