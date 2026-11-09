<?php

namespace Tests\Feature;

use App\Agents\A4\Comunicacion;
use App\Sard\Nodo\EstadoNodo;
use App\Sard\Support\Tiempo;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\EntregaReportes;
use Tests\TestCase;

/**
 * T-012: A adopta al nuevo líder, redirige la cola y reenvía lo que el anterior confirmó y el nuevo no tiene.
 */
final class FailoverA4Test extends TestCase
{
    use EntregaReportes;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return array<int, string> uuids ingresados en la cola de A */
    private function ingresarEnA(int $cantidad): array
    {
        $uuids = [];
        for ($i = 0; $i < $cantidad; $i++) {
            $payload = $this->payload(banderas: ['incendio'], tipo: 2, deviceId: $i + 1);
            $this->call('POST', '/api/reportes', [], [], [], ['CONTENT_TYPE' => 'application/octet-stream'], $payload)->assertOk();
            $uuids[] = $this->uuidDe($payload);
        }

        return $uuids;
    }

    public function test_lider_con_epoca_vieja_o_igual_se_rechaza_con_409(): void
    {
        $this->postJson('/api/lider', ['nodo' => 'C', 'epoca' => 1])->assertStatus(409)->assertJsonPath('error', 'epoca_vieja');
        $this->postJson('/api/lider', ['nodo' => 'B', 'epoca' => 0])->assertUnprocessable();

        $this->assertSame('B', app(EstadoNodo::class)->lider());
    }

    public function test_nuevo_lider_redirige_la_cola_y_reinicia_el_backoff(): void
    {
        Http::fake(['*' => Http::response(['mensaje_id' => 'x', 'estado' => 'en_cola'], 202)]);
        Carbon::setTestNow('2026-11-09 12:00:00');
        $this->ingresarEnA(2);
        DB::table('cola_salida')->update(['intentos' => 6, 'proximo_intento' => '2026-11-09T12:00:30.000Z']);

        $this->postJson('/api/lider', ['nodo' => 'C', 'epoca' => 2], ['X-Sard-Epoca' => '2'])->assertNoContent();

        $estado = app(EstadoNodo::class);
        $this->assertSame('C', $estado->lider());
        $this->assertSame(2, $estado->epoca());
        $this->assertSame('B', $estado->valor('pendiente_reconciliar'));
        $this->assertSame([0], DB::table('cola_salida')->distinct()->pluck('intentos')->all());

        $this->assertSame(2, app(Comunicacion::class)->ciclo());
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/canal/enviar') && $request['destino'] === 'C');
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/canal/enviar') && $request['destino'] === 'B');
    }

    public function test_reconciliacion_devuelve_a_la_cola_lo_que_b_confirmo_y_c_no_tiene(): void
    {
        $uuids = $this->ingresarEnA(3);
        DB::table('cola_salida')->update(['confirmado_en' => '2026-11-09T11:59:00.000Z', 'confirmado_por' => 'B']);
        app(EstadoNodo::class)->seguir('C', 2);
        app(EstadoNodo::class)->guardar('pendiente_reconciliar', 'B');

        Http::fake(['127.0.0.1:8003/api/reportes/faltantes' => Http::response(['faltantes' => [$uuids[1]]])]);

        $this->assertSame(1, app(Comunicacion::class)->reconciliar());

        $this->assertNull(DB::table('cola_salida')->where('reporte_uuid', $uuids[1])->value('confirmado_en'));
        $this->assertSame(2, DB::table('cola_salida')->whereNotNull('confirmado_en')->count());
        $this->assertNull(app(EstadoNodo::class)->valor('pendiente_reconciliar'));
        Http::assertSent(fn (Request $request): bool => $request['uuids'] === $uuids || $request['uuids'] == $uuids);
    }

    public function test_si_el_nuevo_lider_no_responde_la_reconciliacion_queda_pendiente(): void
    {
        $this->ingresarEnA(1);
        DB::table('cola_salida')->update(['confirmado_en' => '2026-11-09T11:59:00.000Z', 'confirmado_por' => 'B']);
        app(EstadoNodo::class)->seguir('C', 2);
        app(EstadoNodo::class)->guardar('pendiente_reconciliar', 'B');
        Http::fake(['*' => Http::response('', 503)]);

        $this->assertNull(app(Comunicacion::class)->reconciliar());
        $this->assertSame('B', app(EstadoNodo::class)->valor('pendiente_reconciliar'));
        $this->assertSame(1, DB::table('cola_salida')->whereNotNull('confirmado_en')->count(), 'nada se pierde ni se reenvía a ciegas');
    }

    public function test_faltantes_devuelve_solo_los_reportes_ausentes(): void
    {
        $this->comoLider('C');
        $presente = $this->payload(banderas: ['via'], tipo: 6);
        $this->entregar($presente)->assertOk();
        $ausente = $this->uuidDe($this->payload());

        $this->postJson('/api/reportes/faltantes', ['uuids' => [$this->uuidDe($presente), $ausente]])
            ->assertOk()
            ->assertExactJson(['faltantes' => [$ausente]]);
    }

    public function test_sin_acks_a_descubre_al_lider_de_mayor_epoca(): void
    {
        Carbon::setTestNow('2026-11-09 12:00:00');
        $this->ingresarEnA(1);
        DB::table('cola_salida')->update(['intentos' => 5]);
        Http::fake([
            '127.0.0.1:8002/api/lider' => Http::response(['nodo' => 'B', 'epoca' => 1]),
            '127.0.0.1:8003/api/lider' => Http::response(['nodo' => 'C', 'epoca' => 3]),
            '*' => Http::response(['mensaje_id' => 'x', 'estado' => 'en_cola'], 202),
        ]);

        app(Comunicacion::class)->descubrirLiderSiNoHayAcks(Tiempo::ahora());

        $this->assertSame('C', app(EstadoNodo::class)->lider());
        $this->assertSame(3, app(EstadoNodo::class)->epoca());

        Http::fake(['*' => Http::response('', 500)]);
        app(Comunicacion::class)->descubrirLiderSiNoHayAcks(Tiempo::ahora()->addSeconds(3));
        Http::assertNothingSent();
    }
}
