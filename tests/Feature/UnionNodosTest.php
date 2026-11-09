<?php

namespace Tests\Feature;

use App\Agents\A5\Resiliencia;
use App\Sard\Log\EventLog;
use App\Sard\Nodo\EstadoNodo;
use Database\Seeders\SemillaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\DosNodos;
use Tests\Concerns\EntregaReportes;
use Tests\TestCase;

/**
 * T-013: B vuelve como seguidor y une su log con el de C por UUID, sin pérdidas ni duplicados.
 *
 * C usa la base de pruebas por defecto («sqlite»); B, una segunda base en memoria («nodo_b»).
 */
final class UnionNodosTest extends TestCase
{
    use DosNodos;
    use EntregaReportes;
    use RefreshDatabase;

    /** Ejecuta $accion como si fuera el nodo dado (su base y su rol) y vuelve al nodo actual. */
    private function enNodo(string $nodo, callable $accion): mixed
    {
        $conexionAnterior = DB::getDefaultConnection();
        $rolAnterior = config('sard.nodo_role');

        DB::setDefaultConnection($nodo === 'C' ? 'sqlite' : 'nodo_'.strtolower($nodo));
        config(['sard.nodo_role' => $nodo]);
        try {
            return $accion();
        } finally {
            DB::setDefaultConnection($conexionAnterior);
            config(['sard.nodo_role' => $rolAnterior]);
        }
    }

    /** Http falso: el canal acepta todo; las llamadas a C se atienden con la base y el rol de C. */
    private function conectarBconC(): void
    {
        // Fábrica nueva: los stubs de Http::fake anteriores ya no responden.
        Http::swap(new Factory);
        Http::fake(function (Request $request) {
            if (! str_starts_with($request->url(), 'http://127.0.0.1:8003')) {
                return Http::response(['mensaje_id' => 'x', 'estado' => 'en_cola'], 202);
            }

            /** @var TestResponse $respuesta */
            $respuesta = $this->enNodo('C', fn () => $request->method() === 'GET'
                ? $this->getJson(substr($request->url(), strlen('http://127.0.0.1:8003')))
                : $this->postJson(substr($request->url(), strlen('http://127.0.0.1:8003')), $request->data(), ['X-Sard-Epoca' => $request->header('X-Sard-Epoca')[0] ?? '']));

            return Http::response($respuesta->getContent(), $respuesta->getStatusCode(), ['Content-Type' => 'application/json']);
        });
    }

    private function conteos(): array
    {
        return [
            'reportes' => DB::table('reportes')->count(),
            'triajes' => DB::table('triajes')->count(),
            'incidente_reporte' => DB::table('incidente_reporte')->count(),
            'incidentes' => DB::table('incidentes')->count(),
        ];
    }

    public function test_lote_inserta_por_uuid_y_repetirlo_no_inserta_nada(): void
    {
        $this->comoLider('C');
        $this->seed(SemillaSeeder::class);
        $this->entregar($this->payload(banderas: ['incendio'], tipo: 2))->assertOk();
        $logDeC = app(EventLog::class)->desde([], 10_000);

        $this->cambiarABaseDe('B', 'C', 2);
        $this->postJson('/api/eventos/lote', ['eventos' => $logDeC])->assertOk()
            ->assertJsonPath('rechazados', [])
            ->assertJsonPath('insertados', count($logDeC))
            ->assertJsonPath('ignorados', 0);
        $this->postJson('/api/eventos/lote', ['eventos' => $logDeC])->assertOk()
            ->assertJsonPath('insertados', 0)
            ->assertJsonPath('ignorados', count($logDeC));

        $this->assertSame(1, DB::table('reportes')->count());
        $this->assertTrue(app(EventLog::class)->verificarCadena('C')['ok']);
    }

    public function test_lote_rechaza_eventos_alterados(): void
    {
        $this->comoLider('C');
        $this->entregar($this->payload(banderas: ['incendio'], tipo: 2))->assertOk();
        $evento = app(EventLog::class)->desde([], 1)[0];
        $evento['payload']['texto'] = 'alterado';

        $this->cambiarABaseDe('B', 'C', 2);
        $this->postJson('/api/eventos/lote', ['eventos' => [$evento]])->assertOk()
            ->assertJsonPath('insertados', 0)
            ->assertJsonCount(1, 'rechazados');
    }

    public function test_ac007a_b_vuelve_como_seguidor_y_queda_igual_que_c_sin_duplicados(): void
    {
        // C lidera la época 2 y recibió reportes mientras B estaba caído.
        $this->comoLider('C');
        app(EstadoNodo::class)->asumir(2);
        $this->seed(SemillaSeeder::class);
        foreach (range(1, 5) as $i) {
            $this->entregar($this->payload(banderas: ['incendio'], tipo: 2, lat: 5.0 + $i * 0.02, deviceId: $i))->assertOk();
        }

        // B vuelve con su log de antes (época 1, se creía líder) y dos reportes que C no tiene.
        $this->cambiarABaseDe('B', 'B', 1);
        $this->seed(SemillaSeeder::class);
        Http::fake(['*' => Http::response(['mensaje_id' => 'x', 'estado' => 'en_cola'], 202)]);
        $this->entregar($this->payload(banderas: ['via'], tipo: 6, lat: 5.2, deviceId: 50))->assertOk();
        $this->entregar($this->payload(banderas: ['gas'], tipo: 7, lat: 5.3, deviceId: 51))->assertOk();

        $this->conectarBconC();
        $a5 = app(Resiliencia::class);
        $this->assertTrue($a5->descubrir(), 'C tiene época mayor');
        $this->assertSame('seguidor', app(EstadoNodo::class)->rol());
        $this->assertSame(2, app(EstadoNodo::class)->epoca());

        $resultado = $a5->unir();

        $this->assertSame(0, $resultado['ignorados']);
        $this->assertGreaterThan(0, $resultado['insertados']);
        $this->assertNull(app(EstadoNodo::class)->valor('pendiente_union'));

        $enB = $this->conteos();
        $enC = $this->enNodo('C', fn (): array => $this->conteos());
        $this->assertSame(['reportes' => 7, 'triajes' => 7, 'incidente_reporte' => 7, 'incidentes' => 7], $enB);
        $this->assertSame($enB, $enC);

        foreach (['B', 'C'] as $origen) {
            $this->assertTrue(app(EventLog::class)->verificarCadena($origen)['ok'], "cadena {$origen} en B");
            $this->assertTrue($this->enNodo('C', fn () => app(EventLog::class)->verificarCadena($origen)['ok']), "cadena {$origen} en C");
        }
        $this->assertSame(1, $this->enNodo('C', fn () => DB::table('eventos')->where('tipo', 'NodoReunido')->count()));

        $repetida = $a5->unir();
        $this->assertSame(0, $repetida['insertados'], 'repetir la unión no inserta nada');
    }

    public function test_aislamiento_y_decisiones_en_ambos_nodos_dejan_el_incidente_en_conflicto(): void
    {
        // Antes del aislamiento: C lidera, hay un incidente con recomendación y B lo tiene replicado.
        $this->comoLider('C');
        $this->seed(SemillaSeeder::class);
        $this->entregar($this->payload(banderas: ['incendio'], tipo: 2))->assertOk();
        $recomendacion = DB::table('recomendaciones')->first();
        $logDeC = app(EventLog::class)->desde([], 10_000);

        $this->cambiarABaseDe('B', 'C', 1);
        $this->seed(SemillaSeeder::class);
        $this->postJson('/api/eventos/lote', ['eventos' => $logDeC])->assertOk();

        // Aislados: B se cree líder y aprueba; C descarta la misma recomendación.
        app(EstadoNodo::class)->asumir(1);
        $this->postJson('/api/decisiones', ['recomendacion_uuid' => $recomendacion->uuid, 'accion' => 'aprobar', 'operador' => 'Ana en B'])->assertCreated();
        $this->enNodo('C', fn () => $this->postJson('/api/decisiones', [
            'recomendacion_uuid' => $recomendacion->uuid, 'accion' => 'descartar', 'motivo' => 'falsa alarma', 'operador' => 'Luis en C',
        ])->assertCreated());

        // Al reconectar, B descubre que C tiene época mayor... aquí ambos están en la época 1:
        // C sube a 2 al asumir durante el aislamiento.
        $this->enNodo('C', fn () => app(EstadoNodo::class)->asumir(2));
        $this->conectarBconC();
        $a5 = app(Resiliencia::class);
        $this->assertTrue($a5->descubrir());
        $a5->unir();

        foreach (['B', 'C'] as $nodo) {
            $this->enNodo($nodo, function () use ($recomendacion, $nodo) {
                $this->assertSame(2, DB::table('decisiones')->where('recomendacion_uuid', $recomendacion->uuid)->count(), "ambas decisiones en {$nodo}");
                $this->assertSame('en_conflicto', DB::table('incidentes')->where('uuid', $recomendacion->incidente_uuid)->value('estado'), "en conflicto en {$nodo}");
            });
        }
    }

    public function test_el_mismo_reporte_agrupado_en_dos_nodos_no_deja_incidentes_fantasma(): void
    {
        $payload = $this->payload(banderas: ['incendio'], tipo: 2);

        $this->comoLider('C');
        $this->entregar($payload)->assertOk();

        $this->cambiarABaseDe('B', 'B', 1);
        Http::fake(['*' => Http::response(['mensaje_id' => 'x', 'estado' => 'en_cola'], 202)]);
        $this->entregar($payload)->assertOk();
        $logDeB = app(EventLog::class)->desde([], 10_000);

        $this->enNodo('C', function () use ($logDeB) {
            $this->postJson('/api/eventos/lote', ['eventos' => $logDeB])->assertOk();

            $this->assertSame(1, DB::table('reportes')->count());
            $this->assertSame(1, DB::table('incidentes')->count(), 'el incidente perdedor sale de la proyección');
            $ganador = DB::table('eventos')->where('tipo', 'IncidenteAgrupado')->orderBy('uuid')->first();
            $this->assertSame(json_decode($ganador->payload, true)['incidente_uuid'], DB::table('incidentes')->value('uuid'));
            $this->assertSame(1, (int) DB::table('incidentes')->value('reportes_n'));
        });
    }

    public function test_aislar_corta_latidos_y_replica_solo_en_demo(): void
    {
        config(['sard.nodo_role' => 'B']);
        $this->postJson('/api/demo/aislar', ['on' => true])->assertNoContent();

        $this->postJson('/api/latido', ['nodo' => 'C', 'epoca' => 2, 'seq_max' => 0])->assertStatus(503);
        $this->getJson('/api/eventos?desde={}')->assertStatus(503);
        $this->getJson('/api/tablero/estado')->assertOk()->assertJsonPath('aislado', true);

        $this->postJson('/api/demo/aislar', ['on' => false])->assertNoContent();
        $this->getJson('/api/eventos?desde={}')->assertOk();

        config(['sard.demo' => false]);
        $this->postJson('/api/demo/aislar', ['on' => true])->assertForbidden();
    }
}
