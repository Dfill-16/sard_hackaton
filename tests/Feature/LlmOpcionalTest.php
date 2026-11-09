<?php

namespace Tests\Feature;

use App\Agents\A6\Coordinacion;
use App\Jobs\ProcesarReporte;
use App\Sard\Llm\Cadena;
use App\Sard\Nodo\EstadoNodo;
use Database\Seeders\SemillaSeeder;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\EntregaReportes;
use Tests\TestCase;

/**
 * T-016: el LLM solo eleva (A2) o redacta (A6); nunca baja, descarta ni decide.
 * Todo con un cliente HTTP simulado: ninguna prueba sale a internet.
 */
final class LlmOpcionalTest extends TestCase
{
    use EntregaReportes;
    use RefreshDatabase;

    private const CLAVES = ['clave-secreta-uno', 'clave-secreta-dos', 'clave-secreta-tres'];

    /** @var array<string, callable(Request): mixed> respuesta simulada por proveedor (host) */
    private array $respuestas = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'sard.nodo_role' => 'B',
            'sard.llm_habilitado' => true,
            'sard.llm.proveedores' => [
                ['url' => 'https://llm1.test/v1', 'key' => self::CLAVES[0], 'modelo' => 'modelo-uno'],
                ['url' => 'https://llm2.test/v1', 'key' => self::CLAVES[1], 'modelo' => 'modelo-dos'],
                ['url' => 'https://llm3.test/v1', 'key' => self::CLAVES[2], 'modelo' => 'modelo-tres'],
            ],
        ]);
        app(EstadoNodo::class)->asumir(1);
        $this->seed(SemillaSeeder::class);

        Http::fake(function (Request $request) {
            $host = (string) parse_url($request->url(), PHP_URL_HOST);
            if (isset($this->respuestas[$host])) {
                return ($this->respuestas[$host])($request);
            }
            if (str_ends_with($host, '.test')) {
                return Http::response(['error' => 'no configurado en la prueba'], 500);
            }

            return Http::response(['mensaje_id' => 'x', 'estado' => 'en_cola'], 202);
        });
    }

    /** @param  array<string, mixed>  $contenido */
    private static function chat(array|string $contenido): PromiseInterface
    {
        return Http::response(['choices' => [['message' => ['content' => is_string($contenido) ? $contenido : json_encode($contenido)]]]]);
    }

    /** El proveedor responde según el agente: A2 (elevar) o A6 (justificación). */
    private function proveedor(string $host, ?array $a2, ?array $a6 = null): void
    {
        $this->respuestas[$host] = function (Request $request) use ($a2, $a6) {
            $esA6 = str_starts_with($request['messages'][0]['content'], 'Redactas en español');
            $contenido = $esA6 ? $a6 : $a2;

            return $contenido === null ? Http::response(['error' => 'cuota'], 429) : self::chat($contenido);
        };
    }

    /** @return array<int, Request> llamadas a proveedores LLM */
    private function llamadasLlm(?string $host = null): array
    {
        return Http::recorded(fn (Request $r): bool => str_ends_with((string) parse_url($r->url(), PHP_URL_HOST), '.test')
            && ($host === null || parse_url($r->url(), PHP_URL_HOST) === $host))
            ->map(fn (array $par): Request => $par[0])->values()->all();
    }

    /** Reporte ambiguo: por reglas es P4 (tipo «otro», sin banderas ni palabras clave). */
    private function reporteAmbiguo(int $deviceId = 1): string
    {
        $payload = $this->payload(texto: 'olor raro en el colegio y varios ninos tosiendo', tipo: 0, deviceId: $deviceId);
        $this->entregar($payload)->assertOk();

        return $this->uuidDe($payload);
    }

    public function test_el_llm_eleva_un_reporte_ambiguo_como_sugerencia(): void
    {
        $this->proveedor('llm1.test', ['elevar' => true, 'prioridad' => 'P2', 'explicacion' => 'Posible intoxicación colectiva de menores.'],
            ['justificacion' => 'Varios menores con síntomas: verificar y enviar apoyo.']);

        $uuid = $this->reporteAmbiguo();

        $evento = json_decode(DB::table('eventos')->where('tipo', 'PrioridadElevadaLLM')->value('payload'), true);
        $this->assertSame(['reporte_uuid' => $uuid, 'de' => 'P4', 'a' => 'P2', 'explicacion' => 'Posible intoxicación colectiva de menores.',
            'proveedor' => 'llm1.test · modelo-uno', 'etiqueta' => 'sugerencia'], $evento);

        $triaje = DB::table('triajes')->where('reporte_uuid', $uuid)->first();
        $this->assertSame(['P2', 'P4', 'IA'], [$triaje->prioridad, $triaje->prioridad_reglas, $triaje->origen]);
        $this->assertSame('P2', DB::table('incidentes')->value('prioridad_base'));

        $detalle = $this->getJson('/api/incidentes/'.DB::table('incidentes')->value('uuid'))->assertOk();
        $detalle->assertJsonPath('reportes.0.origen', 'IA')
            ->assertJsonPath('reportes.0.prioridad_reglas', 'P4')
            ->assertJsonPath('reportes.0.elevacion.etiqueta', 'sugerencia');
        $this->getJson('/api/tablero/estado')->assertJsonPath('modo', 'llm');
    }

    public function test_ac012a_una_sugerencia_de_bajar_o_igual_se_ignora(): void
    {
        // Reglas: incendio → P3. El LLM sugiere P4 (bajar).
        $this->proveedor('llm1.test', ['elevar' => true, 'prioridad' => 'P4', 'explicacion' => 'No parece grave.']);
        $bajar = $this->payload(banderas: ['incendio'], tipo: 2, deviceId: 1);
        $this->entregar($bajar)->assertOk();

        // El LLM dice «no elevar».
        $this->proveedor('llm1.test', ['elevar' => false, 'prioridad' => 'P3', 'explicacion' => 'Sin cambios.']);
        $igual = $this->payload(banderas: ['via'], tipo: 6, lat: 5.10, deviceId: 2);
        $this->entregar($igual)->assertOk();

        $this->assertSame(0, DB::table('eventos')->where('tipo', 'PrioridadElevadaLLM')->count());
        $this->assertSame('P3', DB::table('triajes')->where('reporte_uuid', $this->uuidDe($bajar))->value('prioridad'));
        $this->assertSame(['reglas'], DB::table('triajes')->distinct()->pluck('origen')->all());
    }

    public function test_un_p1_por_reglas_no_consulta_al_llm_para_elevar(): void
    {
        $this->proveedor('llm1.test', ['elevar' => true, 'prioridad' => 'P1', 'explicacion' => 'x'], ['justificacion' => 'Texto de A6.']);

        $this->entregar($this->payload(banderas: ['atrapada'], tipo: 1))->assertOk();

        $this->assertSame([], array_filter($this->llamadasLlm(), fn (Request $r): bool => str_contains($r['messages'][0]['content'], 'triaje')));
    }

    public function test_si_el_proveedor_1_no_responde_se_usa_el_2(): void
    {
        $this->respuestas['llm1.test'] = fn () => throw new ConnectionException('timeout');
        $this->proveedor('llm2.test', ['elevar' => true, 'prioridad' => 'P3', 'explicacion' => 'Varias personas con síntomas.']);

        $this->reporteAmbiguo();

        $this->assertSame('llm2.test · modelo-dos', json_decode(DB::table('eventos')->where('tipo', 'PrioridadElevadaLLM')->value('payload'), true)['proveedor']);
    }

    public function test_una_respuesta_que_no_cumple_el_esquema_pasa_al_siguiente(): void
    {
        $this->respuestas['llm1.test'] = fn () => self::chat(['elevar' => 'si', 'prioridad' => 'urgente', 'explicacion' => 'x']);
        $this->respuestas['llm2.test'] = fn () => self::chat("```json\n{\"elevar\": true, \"prioridad\": \"P3\", \"explicacion\": \"".str_repeat('a', 400)."\"}\n```");

        $this->reporteAmbiguo();

        $evento = json_decode(DB::table('eventos')->where('tipo', 'PrioridadElevadaLLM')->value('payload'), true);
        $this->assertSame('llm2.test · modelo-dos', $evento['proveedor']);
        $this->assertLessThanOrEqual(200, mb_strlen($evento['explicacion']), 'el texto largo se recorta a 200');
    }

    public function test_si_los_3_fallan_queda_reglas_y_modo_reglas(): void
    {
        $this->reporteAmbiguo();

        $this->assertSame(0, DB::table('eventos')->where('tipo', 'PrioridadElevadaLLM')->count());
        $this->assertSame('P4', DB::table('triajes')->value('prioridad'));
        $this->assertSame('plantilla', DB::table('recomendaciones')->value('modo'));
        $this->getJson('/api/tablero/estado')->assertOk()->assertJsonPath('modo', 'reglas');
        $this->assertCount(6, $this->llamadasLlm(), '3 proveedores × (A2 y A6)');
    }

    public function test_a6_redacta_la_justificacion_sin_cambiar_unidad_accion_ni_pendientes(): void
    {
        $this->proveedor('llm1.test', ['elevar' => false, 'prioridad' => 'P3', 'explicacion' => 'x'],
            ['justificacion' => str_repeat('Fuego en local comercial; enviar la estación más cercana. ', 10)]);

        $this->entregar($this->payload(banderas: ['incendio'], tipo: 2, lat: 5.0485, lon: -75.4835))->assertOk();

        $recomendacion = DB::table('recomendaciones')->first();
        $this->assertSame('llm', $recomendacion->modo);
        $this->assertLessThanOrEqual(300, mb_strlen($recomendacion->justificacion));
        $this->assertStringStartsWith('Fuego en local comercial', $recomendacion->justificacion);

        $plantilla = app(Coordinacion::class)->proponer(DB::table('incidentes')->first(), $recomendacion->prioridad_al_proponer);
        $this->assertSame([$plantilla['unidad_id'], $plantilla['accion'], $plantilla['pendientes']],
            [$recomendacion->unidad_id, $recomendacion->accion, json_decode($recomendacion->pendientes, true)]);
        $this->assertSame('U-06', $recomendacion->unidad_id);
    }

    public function test_no_se_llama_dos_veces_por_el_mismo_reporte(): void
    {
        $this->proveedor('llm1.test', ['elevar' => false, 'prioridad' => 'P4', 'explicacion' => 'Sin cambios.'], ['justificacion' => 'Texto.']);
        $uuid = $this->reporteAmbiguo();
        $antes = count($this->llamadasLlm());

        ProcesarReporte::dispatchSync($uuid);
        ProcesarReporte::dispatchSync($uuid);

        $this->assertSame($antes, count($this->llamadasLlm()));
        $this->assertTrue(DB::table('llm_cache')->where('clave', "a2:{$uuid}")->exists());
    }

    public function test_la_clave_no_aparece_en_logs_ni_en_la_cache(): void
    {
        $mensajes = [];
        Event::listen(MessageLogged::class, function (MessageLogged $log) use (&$mensajes) {
            $mensajes[] = $log->message.' '.json_encode($log->context);
        });
        $this->respuestas['llm1.test'] = fn () => Http::response(['error' => ['message' => 'Invalid API key']], 401);
        $this->respuestas['llm2.test'] = fn () => throw new ConnectionException('cURL error 28: timeout');
        $this->respuestas['llm3.test'] = fn () => self::chat('no es json');

        $this->reporteAmbiguo();

        $this->assertNotEmpty($mensajes);
        $todo = implode("\n", $mensajes).json_encode(DB::table('llm_cache')->get()).json_encode(DB::table('eventos')->get());
        foreach (self::CLAVES as $clave) {
            $this->assertStringNotContainsString($clave, $todo);
        }
        Http::assertSent(fn (Request $r): bool => $r->hasHeader('Authorization', 'Bearer '.self::CLAVES[0]));
    }

    public function test_ac015a_con_el_llm_apagado_el_recorrido_completo_pasa_sin_llamarlo(): void
    {
        config(['sard.llm_habilitado' => false]);
        $this->proveedor('llm1.test', ['elevar' => true, 'prioridad' => 'P1', 'explicacion' => 'x'], ['justificacion' => 'x']);

        $this->entregar($this->payload(banderas: ['incendio'], tipo: 2))->assertOk();
        $recomendacion = DB::table('recomendaciones')->first();
        $this->postJson('/api/decisiones', ['recomendacion_uuid' => $recomendacion->uuid, 'accion' => 'aprobar', 'operador' => 'Mariana'])->assertCreated();

        $this->assertSame([], $this->llamadasLlm());
        $this->assertFalse(Cadena::habilitado());
        $this->assertSame('plantilla', $recomendacion->modo);
        $this->assertSame(1, DB::table('eventos')->where('tipo', 'AlertaCampoEnviada')->count());
        $this->getJson('/api/tablero/estado')->assertJsonPath('modo', 'reglas');
    }

    public function test_si_el_modelo_no_acepta_response_format_se_reintenta_sin_el(): void
    {
        $this->respuestas['llm1.test'] = fn (Request $r) => isset($r['response_format'])
            ? Http::response(['error' => 'response_format no soportado'], 400)
            : self::chat(['elevar' => true, 'prioridad' => 'P3', 'explicacion' => 'Síntomas en varias personas.']);

        $this->reporteAmbiguo();

        $this->assertSame('llm1.test · modelo-uno', json_decode(DB::table('eventos')->where('tipo', 'PrioridadElevadaLLM')->value('payload'), true)['proveedor']);
    }
}
