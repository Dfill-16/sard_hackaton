<?php

namespace Tests\Feature;

use App\Agents\A5\Resiliencia;
use App\Sard\Firma\Canonico;
use App\Sard\Firma\Firmador;
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
 * T-019: los lotes entre B y C van firmados con Ed25519. El hash (T-003) prueba
 * integridad y la firma prueba qué nodo envió el lote. Con SARD_FIRMA=false todo
 * funciona igual que antes.
 */
final class FirmaEd25519Test extends TestCase
{
    use DosNodos;
    use EntregaReportes;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Dos pares, como sard:llaves haría en cada nodo. La privada de pruebas se
        // guarda en storage/keys (ignorado por git) y las públicas van a config.
        if (! is_dir($directorio = storage_path('keys'))) {
            mkdir($directorio, 0700, true);
        }
        foreach (['B', 'C'] as $nodo) {
            $par = Firmador::generarPar();
            file_put_contents(storage_path('keys/firma-test-'.strtolower($nodo).'.key'), $par['par']);
            config(['sard.firma.pub.'.$nodo => $par['publica']]);
        }

        config(['sard.firma.habilitada' => true]);
    }

    /** Apunta la llave privada configurada a la del nodo dado (un solo proceso: se cambia al cambiar de rol). */
    private function usarLlaveDe(string $nodo): void
    {
        config(['sard.firma.llave_privada' => storage_path('keys/firma-test-'.strtolower($nodo).'.key')]);
    }

    /** Ejecuta $accion como si fuera el nodo dado (su base, su rol y su llave) y vuelve al nodo actual. */
    private function enNodo(string $nodo, callable $accion): mixed
    {
        $conexionAnterior = DB::getDefaultConnection();
        $rolAnterior = config('sard.nodo_role');
        $llaveAnterior = config('sard.firma.llave_privada');

        DB::setDefaultConnection($nodo === 'C' ? 'sqlite' : 'nodo_'.strtolower($nodo));
        config([
            'sard.nodo_role' => $nodo,
            'sard.firma.llave_privada' => storage_path('keys/firma-test-'.strtolower($nodo).'.key'),
        ]);
        try {
            return $accion();
        } finally {
            DB::setDefaultConnection($conexionAnterior);
            config(['sard.nodo_role' => $rolAnterior, 'sard.firma.llave_privada' => $llaveAnterior]);
        }
    }

    /** Http falso: el canal acepta todo; las llamadas a C se atienden con la base, el rol y la llave de C. */
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

    public function test_lote_firmado_se_acepta_y_un_byte_alterado_lo_rechaza_completo(): void
    {
        // C lidera y tiene eventos; B (base vacía, seguidor de C en la época 2) los recibe.
        $this->comoLider('C');
        $this->seed(SemillaSeeder::class);
        $this->entregar($this->payload(banderas: ['incendio'], tipo: 2))->assertOk();
        $logDeC = app(EventLog::class)->desde([], 10_000);

        $this->cambiarABaseDe('B', 'C', 2);
        $this->usarLlaveDe('C'); // quien firma el lote es el emisor C
        $firma = Firmador::firmar($logDeC);

        $this->postJson('/api/eventos/lote', ['eventos' => $logDeC, 'nodo' => 'C', 'firma' => $firma])
            ->assertOk()
            ->assertJsonPath('insertados', count($logDeC))
            ->assertJsonPath('rechazados', []);
        $total = DB::table('eventos')->count();

        // Un byte alterado en tránsito: la firma ya no cuadra → se rechaza el lote completo.
        $logAlterado = $logDeC;
        $logAlterado[0]['hash'] = 'f'.substr((string) $logAlterado[0]['hash'], 1);

        $this->postJson('/api/eventos/lote', ['eventos' => $logAlterado, 'nodo' => 'C', 'firma' => $firma])
            ->assertStatus(401)
            ->assertJsonPath('error', 'firma_invalida');
        $this->assertSame($total, DB::table('eventos')->count(), 'no se ingiere nada del lote rechazado');
    }

    public function test_firma_con_otra_llave_se_rechaza(): void
    {
        $this->comoLider('C');
        $this->seed(SemillaSeeder::class);
        $this->entregar($this->payload(banderas: ['incendio'], tipo: 2))->assertOk();
        $logDeC = app(EventLog::class)->desde([], 10_000);

        $this->cambiarABaseDe('B', 'C', 2);

        // El emisor dice ser C pero firmó con la llave privada de B.
        $this->usarLlaveDe('B');
        $firmaAjena = Firmador::firmar($logDeC);

        $this->postJson('/api/eventos/lote', ['eventos' => $logDeC, 'nodo' => 'C', 'firma' => $firmaAjena])
            ->assertStatus(401)
            ->assertJsonPath('error', 'firma_invalida');
        $this->assertSame(0, DB::table('eventos')->count());
    }

    public function test_con_firma_desactivada_el_cuerpo_actual_pasa_igual(): void
    {
        config(['sard.firma.habilitada' => false]);

        $this->comoLider('C');
        $this->seed(SemillaSeeder::class);
        $this->entregar($this->payload(banderas: ['incendio'], tipo: 2))->assertOk();
        $logDeC = app(EventLog::class)->desde([], 10_000);

        $this->cambiarABaseDe('B', 'C', 2);
        $this->postJson('/api/eventos/lote', ['eventos' => $logDeC])
            ->assertOk()
            ->assertJsonPath('insertados', count($logDeC));
    }

    public function test_get_eventos_lleva_la_firma_del_nodo_que_sirve(): void
    {
        $this->comoLider('C');
        $this->usarLlaveDe('C');
        $this->seed(SemillaSeeder::class);
        $this->entregar($this->payload(banderas: ['incendio'], tipo: 2))->assertOk();

        $respuesta = $this->getJson('/api/eventos?desde={}')->assertOk();
        $nodo = $respuesta->json('nodo');
        $firma = $respuesta->json('firma');
        $this->assertSame('C', $nodo);
        $this->assertNotFalse(Firmador::verificar($respuesta->json('eventos'), $firma, $nodo));

        // Si se altera un evento de la respuesta, la firma deja de verificar.
        $eventos = $respuesta->json('eventos');
        $eventos[0]['seq'] = 9999;
        $this->assertFalse(Firmador::verificar($eventos, $firma, $nodo));
    }

    public function test_la_union_completa_de_t013_pasa_con_la_firma_activada(): void
    {
        // C lidera la época 2 y recibió reportes mientras B estaba caído.
        $this->comoLider('C');
        $this->usarLlaveDe('C');
        app(EstadoNodo::class)->asumir(2);
        $this->seed(SemillaSeeder::class);
        foreach (range(1, 5) as $i) {
            $this->entregar($this->payload(banderas: ['incendio'], tipo: 2, lat: 5.0 + $i * 0.02, deviceId: $i))->assertOk();
        }

        // B vuelve con su log de antes (época 1, se creía líder) y dos reportes que C no tiene.
        $this->cambiarABaseDe('B', 'B', 1);
        $this->usarLlaveDe('B');
        $this->seed(SemillaSeeder::class);
        Http::fake(['*' => Http::response(['mensaje_id' => 'x', 'estado' => 'en_cola'], 202)]);
        $this->entregar($this->payload(banderas: ['via'], tipo: 6, lat: 5.2, deviceId: 50))->assertOk();
        $this->entregar($this->payload(banderas: ['gas'], tipo: 7, lat: 5.3, deviceId: 51))->assertOk();

        $this->conectarBconC();
        $a5 = app(Resiliencia::class);
        $this->assertTrue($a5->descubrir(), 'C tiene época mayor');

        $resultado = $a5->unir();

        $this->assertSame(0, $resultado['ignorados']);
        $this->assertGreaterThan(0, $resultado['insertados']);
        $this->assertNull(app(EstadoNodo::class)->valor('pendiente_union'));

        $enB = DB::table('reportes')->count();
        $enC = $this->enNodo('C', fn (): int => (int) DB::table('reportes')->count());
        $this->assertSame(7, $enB);
        $this->assertSame($enB, $enC, 'B queda con el mismo contenido que C');

        foreach (['B', 'C'] as $origen) {
            $this->assertTrue(app(EventLog::class)->verificarCadena($origen)['ok'], "cadena {$origen} en B");
            $this->assertTrue($this->enNodo('C', fn () => app(EventLog::class)->verificarCadena($origen)['ok']), "cadena {$origen} en C");
        }

        $repetida = $a5->unir();
        $this->assertSame(0, $repetida['insertados'], 'repetir la unión no inserta nada');
    }

    public function test_el_canonico_de_firma_coincide_byte_a_byte_con_el_de_t003(): void
    {
        $evento = app(EventLog::class)->append('PruebaFirma', ['z' => 1, 'a' => ['y' => 2, 'b' => 3]]);

        // EventLog::hash = sha256(hash_prev '|' canónico de los 8 campos de contenido).
        // Si el canónico de Firma difiriera en un byte, este hash no coincidiría.
        $contenido = collect($evento)->except(['hash_prev', 'hash'])->all();
        $this->assertSame(
            app(EventLog::class)->hash('0', $contenido),
            hash('sha256', '0|'.Canonico::json($contenido))
        );
    }
}
