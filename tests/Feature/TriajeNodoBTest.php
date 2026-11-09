<?php

namespace Tests\Feature;

use App\Agents\A1\Recepcion;
use App\Protocol\PayloadReporte;
use App\Sard\Nodo\EstadoNodo;
use App\Sard\Support\Tiempo;
use App\Sard\Support\Uuid7;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\EntregaReportes;
use Tests\TestCase;

/**
 * Nodo B/C (T-007): entrega por el canal, A1 recepción y A2 triaje por reglas.
 */
final class TriajeNodoBTest extends TestCase
{
    use EntregaReportes;
    use RefreshDatabase;

    private function triaje(string $payload): object
    {
        return DB::table('triajes')->where('reporte_uuid', $this->uuidDe($payload))->first();
    }

    private function acksEnviados(): int
    {
        return Http::recorded(fn (Request $request): bool => $request['ruta'] === '/api/acks')->count();
    }

    public function test_ac001c_bandera_persona_atrapada_da_p1_por_reglas(): void
    {
        $this->comoLider();
        $payload = $this->payload(banderas: ['atrapada']);

        $this->entregar($payload)->assertOk()->assertJsonPath('duplicado', false);

        $triaje = $this->triaje($payload);
        $this->assertSame('P1', $triaje->prioridad);
        $this->assertContains(['origen' => 'critica', 'clave' => 'atrapada', 'peso' => 0], json_decode($triaje->senales, true));
    }

    public function test_ac001c_texto_estamos_atrapados_da_p1_por_reglas(): void
    {
        $this->comoLider();
        $payload = $this->payload(texto: 'Estamos atrapados bajo el techo', tipo: 1);

        $this->entregar($payload)->assertOk();

        $triaje = $this->triaje($payload);
        $this->assertSame('P1', $triaje->prioridad);
        $this->assertContains(['origen' => 'palabra', 'clave' => 'atrapada', 'peso' => 5], json_decode($triaje->senales, true));
    }

    public function test_ac011a_sin_gps_queda_sin_ubicacion_y_no_se_estima(): void
    {
        $this->comoLider();
        $payload = $this->payload(texto: 'humo en la cocina', lat: null, lon: null, tipo: 2);

        $this->entregar($payload)->assertOk();

        $reporte = DB::table('reportes')->where('reporte_uuid', $this->uuidDe($payload))->first();
        $this->assertNull($reporte->lat);
        $this->assertNull($reporte->lon);
        $this->assertTrue((bool) $reporte->sin_ubicacion);

        $triaje = $this->triaje($payload);
        $this->assertContains('sin ubicación (no se estima)', json_decode($triaje->motivos, true));
        $this->assertEqualsWithDelta(0.4, $triaje->incertidumbre, 0.001, '0.3 sin ubicación + 0.1 personas desconocidas');
    }

    public function test_ac012a_todos_los_triajes_en_modo_reglas(): void
    {
        $this->comoLider();
        foreach ([['incendio'], [], ['via'], ['gas', 'cables']] as $banderas) {
            $this->entregar($this->payload(banderas: $banderas, tipo: 7))->assertOk();
        }

        $this->assertSame(4, DB::table('triajes')->count());
        $this->assertSame(['reglas'], DB::table('triajes')->distinct()->pluck('modo')->all());
    }

    public function test_ac005a_el_mismo_codigo_procesa_en_c_si_c_es_lider(): void
    {
        $this->comoLider('C');
        $payload = $this->payload(banderas: ['herido'], tipo: 3);

        $this->entregar($payload)->assertOk()->assertJsonPath('nodo', 'C');

        $this->assertSame('P3', $this->triaje($payload)->prioridad);
        $this->assertSame(['C'], DB::table('eventos')->distinct()->pluck('nodo_origen')->all());
        Http::assertSent(fn (Request $request): bool => $request['ruta'] === '/api/acks'
            && json_decode(base64_decode($request['cuerpo_b64']), true)['nodo'] === 'C');
    }

    public function test_entrega_duplicada_deja_un_reporte_y_dos_ack(): void
    {
        $this->comoLider();
        $payload = $this->payload(banderas: ['deslizamiento'], tipo: 4);

        $this->entregar($payload)->assertOk()->assertJsonPath('duplicado', false);
        $this->entregar($payload)->assertOk()->assertJsonPath('duplicado', true);

        $this->assertSame(1, DB::table('reportes')->count());
        $this->assertSame(1, DB::table('triajes')->count());
        $this->assertSame(1, DB::table('eventos')->where('tipo', 'ReporteNormalizado')->count());
        $this->assertSame(2, $this->acksEnviados());

        Http::assertSent(function (Request $request) use ($payload): bool {
            $ack = json_decode(base64_decode($request['cuerpo_b64']), true);

            return $request->url() === 'http://127.0.0.1:8010/api/canal/enviar'
                && $request['destino'] === 'A'
                && $request['content_type'] === 'application/json'
                && $ack === ['reporte_uuid' => $this->uuidDe($payload), 'nodo' => 'B', 'epoca' => 1];
        });
    }

    public function test_seguidor_responde_409_sin_ack(): void
    {
        $this->comoLider();
        app(EstadoNodo::class)->seguir('C', 2);

        $this->entregar($this->payload())->assertStatus(409)->assertJsonPath('error', 'no_lider');

        $this->assertSame(0, DB::table('reportes')->count());
        $this->assertSame(0, $this->acksEnviados());
    }

    public function test_nodo_a_no_expone_la_entrega(): void
    {
        $this->entregar($this->payload())->assertNotFound();
    }

    public function test_payload_corrupto_responde_400_sin_ack(): void
    {
        $this->comoLider();
        $payload = $this->payload();
        $payload[5] = chr(ord($payload[5]) ^ 0xFF);

        $this->entregar($payload)->assertStatus(400);
        $this->assertSame(0, $this->acksEnviados());
    }

    public function test_a1_no_inventa_datos(): void
    {
        $uuid = Uuid7::generar();
        $normalizado = app(Recepcion::class)->normalizar([
            'uuid' => $uuid,
            'device_id' => 0xAB,
            'lat' => null,
            'lon' => null,
            'tipo' => 9,
            'banderas' => (1 << 15) | 1,
            'personas' => 0,
            'texto' => str_repeat('a', PayloadReporte::MAXIMO_BYTES_TEXTO),
        ]);

        $this->assertSame('000000ab', $normalizado['device_id']);
        $this->assertSame(0, $normalizado['tipo']);
        $this->assertSame(1, $normalizado['banderas']);
        $this->assertSame(['tipo', 'banderas_bit15'], $normalizado['campos_desconocidos']);
        $this->assertNull($normalizado['personas']);
        $this->assertTrue($normalizado['sin_ubicacion']);
        $this->assertTrue($normalizado['truncado']);
        $this->assertSame(Uuid7::milisegundos($uuid), Tiempo::milisegundos(Tiempo::desdeIso($normalizado['creado_en'])));
    }

    public function test_triaje_incertidumbre_maxima_y_motivos_legibles(): void
    {
        $this->comoLider();
        $payload = $this->payload(texto: '', lat: null, lon: null, tipo: 0);

        $this->entregar($payload)->assertOk();

        $triaje = $this->triaje($payload);
        $this->assertSame('P4', $triaje->prioridad);
        $this->assertEqualsWithDelta(0.8, $triaje->incertidumbre, 0.001);
        $this->assertSame(
            ['sin ubicación (no se estima)', 'sin texto descriptivo', 'tipo «otro»', 'número de personas desconocido'],
            json_decode($triaje->motivos, true),
        );
    }

    public function test_reconstruir_reproduce_reportes_y_triajes_desde_el_log(): void
    {
        $this->comoLider();
        $this->entregar($this->payload(banderas: ['incendio'], tipo: 2))->assertOk();
        $this->entregar($this->payload(banderas: ['via'], tipo: 6, lat: 5.07, lon: -75.52))->assertOk();
        $antes = DB::table('triajes')->orderBy('reporte_uuid')->get();

        Artisan::call('sard:reconstruir');

        $this->assertSame(2, DB::table('reportes')->count());
        $this->assertEquals($antes, DB::table('triajes')->orderBy('reporte_uuid')->get());
        $this->assertSame(2, DB::table('incidentes')->count());
    }
}
