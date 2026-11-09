<?php

namespace Tests\Feature;

use App\Agents\A5\Resiliencia;
use App\Jobs\ProcesarReporte;
use App\Node\A\GeneradorSintetico;
use App\Sard\Log\EventLog;
use App\Sard\Nodo\EstadoNodo;
use App\Sard\Support\Tiempo;
use Carbon\Carbon;
use Database\Seeders\SemillaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\DosNodos;
use Tests\Concerns\EntregaReportes;
use Tests\TestCase;

/**
 * A5 (T-011): latidos, vigilante, failover a C, aviso a A y D, réplica del log.
 */
final class ResilienciaA5Test extends TestCase
{
    use DosNodos;
    use EntregaReportes;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Este proceso como C, seguidor de B en la época 1. */
    private function comoSeguidorC(): EstadoNodo
    {
        config(['sard.nodo_role' => 'C']);
        $estado = app(EstadoNodo::class);
        $estado->seguir('B', 1);

        return $estado;
    }

    public function test_el_seguidor_registra_el_latido_del_lider(): void
    {
        $estado = $this->comoSeguidorC();
        Carbon::setTestNow('2026-11-09 12:00:00');

        $this->postJson('/api/latido', ['nodo' => 'B', 'epoca' => 1, 'seq_max' => 7], ['X-Sard-Epoca' => '1'])->assertNoContent();

        $this->assertSame('2026-11-09T12:00:00.000Z', $estado->ultimoLatido());
        $this->assertSame('seguidor', $estado->rol());
        $this->getJson('/api/salud')->assertOk()
            ->assertJsonPath('nodo', 'C')
            ->assertJsonPath('rol', 'seguidor')
            ->assertJsonPath('ultimo_latido_hace_s', 0)
            ->assertJsonStructure(['nodo', 'rol', 'epoca', 'ultimo_latido_hace_s', 'seq_max']);
    }

    public function test_latido_de_epoca_vieja_se_rechaza(): void
    {
        $estado = $this->comoSeguidorC();
        $estado->asumir(2);

        $this->postJson('/api/latido', ['nodo' => 'B', 'epoca' => 1, 'seq_max' => 0], ['X-Sard-Epoca' => '1'])
            ->assertStatus(409)
            ->assertJsonPath('error', 'epoca_vieja');
        $this->assertSame('lider', $estado->rol());
    }

    public function test_ac007a_sin_latidos_por_10_s_c_asume_en_la_epoca_2_y_reanuda(): void
    {
        $estado = $this->comoSeguidorC();
        Queue::fake();
        $this->entregarReporteReplicadoSinTriaje();

        Carbon::setTestNow('2026-11-09 12:00:00');
        $estado->marcarLatido();
        $a5 = app(Resiliencia::class);
        $arranque = Tiempo::milisegundos() - 60_000;

        Carbon::setTestNow('2026-11-09 12:00:09');
        $this->assertFalse($a5->vigilar($arranque), 'a los 9 s todavía no');

        Carbon::setTestNow('2026-11-09 12:00:10.500');
        $this->assertTrue($a5->vigilar($arranque));

        $this->assertSame('lider', $estado->rol());
        $this->assertSame('C', $estado->lider());
        $this->assertSame(2, $estado->epoca());

        $asumido = json_decode(DB::table('eventos')->where('tipo', 'LiderAsumido')->value('payload'), true);
        $this->assertSame(['nodo' => 'C', 'epoca' => 2, 'anterior' => 'B', 'motivo' => 'latidos_perdidos', 'ultimo_latido' => '2026-11-09T12:00:00.000Z'], $asumido);
        Queue::assertPushed(ProcesarReporte::class, 1);
    }

    public function test_c_no_asume_al_arrancar_si_b_tarda_20_s(): void
    {
        $this->comoSeguidorC();
        Carbon::setTestNow('2026-11-09 12:00:00');
        $arranque = Tiempo::milisegundos();
        $a5 = app(Resiliencia::class);

        Carbon::setTestNow('2026-11-09 12:00:20');
        $this->assertFalse($a5->vigilar($arranque));
        $this->postJson('/api/latido', ['nodo' => 'B', 'epoca' => 1, 'seq_max' => 0])->assertNoContent();

        Carbon::setTestNow('2026-11-09 12:00:45');
        $this->assertTrue($a5->vigilar($arranque), 'sin más latidos desde los 20 s, asume a los 30 s');

        $this->comoSeguidorC();
        Carbon::setTestNow('2026-11-09 13:00:00');
        $arranqueSinLatidos = Tiempo::milisegundos();
        DB::table('nodo_estado')->where('clave', 'ultimo_latido')->update(['valor' => null]);
        Carbon::setTestNow('2026-11-09 13:00:39');
        $this->assertFalse($a5->vigilar($arranqueSinLatidos), 'sin ningún latido: 30 s de gracia + 10 s');
    }

    public function test_el_nuevo_lider_avisa_a_a_y_d_y_reintenta_solo_lo_que_falla(): void
    {
        $estado = $this->comoSeguidorC();
        $estado->asumir(2);
        $aCaido = false;
        Http::fake(function (Request $request) use (&$aCaido) {
            if (str_starts_with($request->url(), 'http://127.0.0.1:8001')) {
                return $aCaido ? Http::response('', 503) : Http::response('', 204);
            }

            return Http::response(['message' => 'Not Found'], 404);
        });

        $this->assertSame([], app(Resiliencia::class)->notificarLider(['A', 'D']), 'D responde 404 (sin la ruta aún): se ignora');
        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://127.0.0.1:8001/api/lider'
            && $request['nodo'] === 'C' && $request['epoca'] === 2 && $request->hasHeader('X-Sard-Epoca', '2'));

        $aCaido = true;
        $this->assertSame(['A'], app(Resiliencia::class)->notificarLider(['A']));
    }

    public function test_el_lider_late_y_si_recibe_409_pasa_a_seguidor_con_union_pendiente(): void
    {
        config(['sard.nodo_role' => 'B']);
        $estado = app(EstadoNodo::class);
        $estado->asumir(1);
        Http::fake([
            '127.0.0.1:8003/api/latido' => Http::response(['error' => 'epoca_vieja'], 409),
            '127.0.0.1:8003/api/lider' => Http::response(['nodo' => 'C', 'epoca' => 2]),
        ]);

        app(Resiliencia::class)->latir();

        $this->assertSame('seguidor', $estado->rol());
        $this->assertSame('C', $estado->lider());
        $this->assertSame(2, $estado->epoca());
        $this->assertSame('1', $estado->valor('pendiente_union'));
    }

    public function test_eventos_se_sirven_por_origen_y_seq_con_punto_de_sincronia(): void
    {
        $this->comoLider('B');
        $this->entregar($this->payload(banderas: ['incendio'], tipo: 2))->assertOk();

        $todos = $this->getJson('/api/eventos?desde={}&limite=500')->assertOk();
        $seqs = array_column($todos->json('eventos'), 'seq');
        $this->assertSame(range(1, count($seqs)), $seqs);
        $this->assertIsArray($todos->json('eventos.0.payload'));
        $this->assertSame(count($seqs), $todos->json('seq_max_por_origen.B'));

        $this->getJson('/api/eventos?desde='.urlencode('{"B":2}'))->assertOk()
            ->assertJsonPath('eventos.0.seq', 3);
        $this->getJson('/api/eventos?desde='.urlencode('{"C":5}'))->assertOk()
            ->assertJsonCount(count($seqs), 'eventos');
    }

    public function test_replica_trae_el_log_del_lider_y_reconstruye_las_proyecciones(): void
    {
        config(['sard.nodo_role' => 'B']);
        app(EstadoNodo::class)->asumir(1);
        $this->seed(SemillaSeeder::class);
        $logDeB = [];
        Http::fake(function (Request $request) use (&$logDeB) {
            return str_contains($request->url(), '/api/eventos')
                ? Http::response(['eventos' => $logDeB, 'seq_max_por_origen' => ['B' => count($logDeB)]])
                : Http::response(['mensaje_id' => 'x', 'estado' => 'en_cola'], 202);
        });
        foreach ((new GeneradorSintetico(42))->masivo() as $payload) {
            $this->entregar($payload)->assertOk();
        }
        $logDeB = app(EventLog::class)->desde([], 10_000);
        $incidentesEnB = DB::table('incidentes')->get(['uuid', 'masivo', 'reportes_n', 'dispositivos_n']);

        $this->cambiarABaseDe('C', 'B', 1);

        $insertados = app(Resiliencia::class)->replicar();

        $this->assertSame(count($logDeB), $insertados);
        $this->assertSame(6, DB::table('reportes')->count());
        $this->assertEquals($incidentesEnB, DB::table('incidentes')->get(['uuid', 'masivo', 'reportes_n', 'dispositivos_n']));
        $this->assertTrue(app(EventLog::class)->verificarCadena('B')['ok']);
        $this->assertSame(0, app(Resiliencia::class)->replicar(), 'replicar de nuevo no duplica');
    }

    public function test_ac015a_a5_no_depende_de_un_cliente_llm(): void
    {
        $codigo = collect(File::allFiles(app_path('Agents/A5')))->map(fn ($archivo): string => $archivo->getContents())->implode("\n");

        $this->assertDoesNotMatchRegularExpression('/^use\s+[^;]*(Llm|OpenAi|OpenAI|Anthropic|Gemini)[^;]*;/m', $codigo, 'sin imports de clientes LLM');
        $this->assertDoesNotMatchRegularExpression("/config\(\s*'sard\.llm|LLM_\d_(URL|KEY)/", $codigo, 'sin configuración de LLM');
        $this->assertDoesNotMatchRegularExpression('#https?://[^\s\'"]*(openai|openrouter|googleapis|nvidia|anthropic)#i', $codigo, 'sin URLs de proveedores');
    }

    /** Un reporte normalizado (como si viniera replicado de B) al que B no alcanzó a triar. */
    private function entregarReporteReplicadoSinTriaje(): void
    {
        app(EventLog::class)->append('ReporteNormalizado', [
            'reporte_uuid' => $uuid = $this->uuidDe($this->payload()),
            'device_id' => '0a0b0c0d',
            'tipo' => 1,
            'banderas' => 1,
            'personas' => null,
            'texto' => '',
            'lat' => 5.0558,
            'lon' => -75.493,
            'sin_ubicacion' => false,
            'creado_en' => Tiempo::iso(),
            'truncado' => false,
            'campos_desconocidos' => [],
        ], $uuid);
    }
}
