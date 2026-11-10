<?php

namespace Tests\Feature;

use App\Console\Commands\Escenarios\Resultado;
use App\Node\A\GeneradorSintetico;
use App\Sard\Nodo\EstadoNodo;
use Database\Seeders\SemillaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\EntregaReportes;
use Tests\TestCase;

/**
 * T-020 · AC-026a: los escenarios con la misma semilla dan las mismas cifras, y la
 * clase Resultado respeta el formato del informe (AC-023a). Los escenarios pesados
 * (pérdida, failover, unión, estrés, acuse) corren en vivo contra el stack y quedan
 * en docs/resultados/resultados.md; su mecánica ya está cubierta por la suite.
 */
final class EscenariosTest extends TestCase
{
    use EntregaReportes;
    use RefreshDatabase;

    public function test_ac_026a_con_semilla_42_las_cifras_de_bytes_y_rumor_masivo_se_repiten(): void
    {
        $primera = $this->correrYPedirCifras($this->generar());

        // Segunda corrida sobre una base fresca en memoria: la tabla eventos es
        // append-only (trigger), así que se compara contra una base limpia nueva.
        DB::purge('corrida_dos');
        config(['database.connections.corrida_dos' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        $this->artisan('migrate', ['--database' => 'corrida_dos', '--force' => true]);

        $conexionAnterior = DB::getDefaultConnection();
        DB::setDefaultConnection('corrida_dos');
        try {
            $segunda = $this->correrYPedirCifras($this->generar());
        } finally {
            DB::setDefaultConnection($conexionAnterior);
        }

        $this->assertSame($primera, $segunda, 'la semilla 42 debe dar cifras idénticas en las dos corridas');
    }

    public function test_la_generacion_con_la_misma_semilla_da_los_mismos_tamanos(): void
    {
        $primera = $this->generar();
        $segunda = $this->generar();

        $this->assertSame(count($primera), count($segunda));
        // El UUIDv7 cambia entre corridas (lleva la hora), pero su tamaño es fijo:
        // los bytes del payload solo dependen del contenido, que sale de la semilla.
        $this->assertSame(array_map('strlen', $primera), array_map('strlen', $segunda));
    }

    public function test_sard_escenario_rechaza_un_escenario_invalido_listando_los_validos(): void
    {
        $this->artisan('sard:escenario', ['escenario' => 'chisme'])
            ->expectsOutput('Válidos: bytes, perdida, rumor-masivo, failover, union, estres, acuse, todos.')
            ->assertExitCode(1);
    }

    public function test_resultado_escribe_json_y_filas_y_un_escenario_fallido_queda_no_ejecutado(): void
    {
        $directorio = storage_path('framework/testing/escenarios-json');
        $rutaMd = storage_path('framework/testing/resultados.md');
        try {
            $resultado = new Resultado($directorio, $rutaMd);

            $rutaJson = $resultado->guardar('bytes', 42, 'n=100', ['ahorro_pct' => 71.2, 'reportes_medidos' => 100], null);

            $this->assertFileExists($rutaJson);
            $corrida = json_decode((string) file_get_contents($rutaJson), true);
            $this->assertTrue($corrida['ejecutado']);
            $this->assertMatchesRegularExpression('/^[0-9a-f]+$/', (string) $corrida['commit']);
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', (string) $corrida['fecha']);

            $informe = (string) file_get_contents($rutaMd);
            $this->assertSame(2, substr_count($informe, '| bytes |'), 'una fila por métrica');
            // Las secciones del paso 8 de la ficha nacen con el informe.
            $this->assertStringContainsString('## Errores observados', $informe);
            $this->assertStringContainsString('## Limitaciones', $informe);
            $this->assertStringContainsString('no autoría de cada evento', $informe);
            $this->assertStringContainsString('canal es simulado', $informe);

            // Segunda corrida del mismo escenario: reemplaza sus filas, no duplica
            // (aquí la última corrida midió una sola métrica, así que queda una fila).
            $resultado->guardar('bytes', 42, 'n=100', ['ahorro_pct' => 70.9], null);
            $informe = (string) file_get_contents($rutaMd);
            $this->assertSame(1, substr_count($informe, '| bytes |'));
            $this->assertStringContainsString('| 70.9 |', $informe);
            $this->assertStringNotContainsString('71.2', $informe);
            // Las secciones sobreviven a los reemplazos y quedan después de la tabla.
            $this->assertStringContainsString('## Errores observados', $informe);
            $this->assertGreaterThan(strpos($informe, '| ---'), strpos($informe, '## Limitaciones'));

            // Un escenario que no pudo correr queda «no ejecutado» con su motivo.
            $resultado->guardar('failover', 42, 'n=100 + matar B', null, 'C no asumió como líder en 30 s.');
            $informe = (string) file_get_contents($rutaMd);
            $this->assertSame(1, substr_count($informe, '| failover |'));
            $this->assertStringContainsString('| no ejecutado | C no asumió como líder en 30 s. |', $informe);
            $this->assertStringContainsString('## Limitaciones', $informe);
        } finally {
            array_map('unlink', (array) glob($directorio.'/*.json'));
            @rmdir($directorio);
            @unlink($rutaMd);
        }
    }

    /**
     * Genera los payloads de rumor-masivo con la semilla fija (n=30 + --rumor + --masivo).
     */
    private function generar(): array
    {
        $generador = new GeneradorSintetico(42);

        return [...$generador->dispersos(30), ...$generador->masivo(), ...$generador->rumor()];
    }

    /**
     * Siembra los payloads en A, los procesa como líder y devuelve las cifras medidas
     * (la misma comparación que hace sard:escenario entre dos corridas de AC-026a).
     */
    private function correrYPedirCifras(array $payloads): array
    {
        // Ingesta en A (los reportes entran binarios y quedan en cola_salida).
        config(['sard.nodo_role' => 'A']);
        foreach ($payloads as $payload) {
            $this->postBinario('/api/reportes', $payload)->assertOk();
        }

        $metricas = $this->getJson('/api/metricas')->assertOk()->json();

        config(['sard.nodo_role' => 'B']);
        app(EstadoNodo::class)->asumir(1);
        $this->seed(SemillaSeeder::class);
        Http::fake(['*' => Http::response(['mensaje_id' => 'x', 'estado' => 'en_cola'], 202)]);

        foreach ($payloads as $payload) {
            $this->entregar($payload)->assertOk();
        }

        $estado = $this->getJson('/api/tablero/estado?todos=1')->assertOk()->json();
        $incidentes = [...$estado['cola'], ...$estado['baja_prioridad'], ...$estado['sin_ubicacion']];

        return [
            'reportes' => $metricas['reportes'],
            'bytes_binario' => $metricas['bytes_binario'],
            'bytes_json_equivalente' => $metricas['bytes_json_equivalente'],
            'incidentes' => count($incidentes),
            'masivos' => count(array_filter($incidentes, fn (array $i): bool => (bool) $i['masivo'])),
            'posibles_rumores' => count(array_filter($incidentes, fn (array $i): bool => (bool) $i['posible_rumor'])),
            'p1' => $estado['contadores']['P1'],
            'p2' => $estado['contadores']['P2'],
            'p3' => $estado['contadores']['P3'],
            'p4' => $estado['contadores']['P4'],
            'sin_ubicacion' => $estado['contadores']['sin_ubicacion'],
        ];
    }

    /** Envía un cuerpo crudo (raw) como application/octet-stream, como hace la PWA. */
    private function postBinario(string $ruta, string $cuerpo): TestResponse
    {
        return $this->call('POST', $ruta, [], [], [], [
            'CONTENT_TYPE' => 'application/octet-stream',
        ], $cuerpo);
    }
}
