<?php

namespace App\Console\Commands\Escenarios;

use Illuminate\Support\Facades\Process;

/**
 * T-020 · AC-023a: persiste cada corrida con fecha y commit.
 *
 * El JSON crudo queda en storage/app/resultados/<escenario>-<fecha>.json y
 * docs/resultados/resultados.md recibe una fila por métrica. La última corrida
 * de un escenario reemplaza sus filas (el historial vive en los JSON). Un
 * escenario que no corrió deja la fila «no ejecutado» y su motivo: el archivo
 * nunca lleva celdas inventadas.
 */
final class Resultado
{
    public function __construct(
        private readonly ?string $directorioJson = null,
        private readonly ?string $rutaMd = null,
    ) {}

    /**
     * @param  array<string, string|int|float|null>  $metricas  null → corrida fallida (se usa $motivo)
     * @return string ruta del JSON escrito
     */
    public function guardar(string $escenario, int $semilla, string $parametros, ?array $metricas, ?string $motivo): string
    {
        $fecha = now('UTC')->format('Y-m-d\TH:i:s.v\Z');
        $etiqueta = now('UTC')->format('Ymd-His');
        $commit = self::commit();

        $directorio = $this->directorioJson ?? storage_path('app/resultados');
        if (! is_dir($directorio)) {
            mkdir($directorio, 0777, true);
        }

        $ruta = $directorio."/{$escenario}-{$etiqueta}.json";
        file_put_contents($ruta, json_encode([
            'escenario' => $escenario,
            'semilla' => $semilla,
            'parametros' => $parametros,
            'fecha' => $fecha,
            'commit' => $commit,
            'ejecutado' => $metricas !== null,
            'motivo' => $motivo,
            'metricas' => $metricas === null ? null : (object) $metricas,
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));

        $this->escribirFilas($escenario, $semilla, $parametros, $metricas, $motivo, $fecha, $commit);

        return $ruta;
    }

    /**
     * Reescribe resultados.md sin las filas previas de este escenario y con las nuevas al final
     * de la tabla. Las secciones «Errores observados» y «Limitaciones» (ficha T-020, paso 8)
     * quedan intactas tras la tabla.
     *
     * @param  array<string, string|int|float|null>|null  $metricas
     */
    private function escribirFilas(string $escenario, int $semilla, string $parametros, ?array $metricas, ?string $motivo, string $fecha, string $commit): void
    {
        $ruta = $this->rutaMd ?? base_path('docs/resultados/resultados.md');
        if (! is_dir(dirname($ruta))) {
            mkdir(dirname($ruta), 0777, true);
        }

        $previo = is_file($ruta) ? (string) file_get_contents($ruta) : '';
        if ($previo === '') {
            $previo = "# Resultados de escenarios (T-020 · E6 · AC-023a)\n\n"
                ."Métricas medidas en vivo por `sard:escenario` — nada estimado. La última corrida de cada\n"
                ."escenario reemplaza sus filas; el historial completo queda en los JSON de storage/app/resultados/.\n\n"
                ."| Escenario | Semilla | Parámetros | Métrica | Valor | Fecha | Commit |\n"
                ."|---|---|---|---|---|---|---|\n\n"
                .self::secciones();
        }

        // Las secciones (## …) van tras la tabla: se separan y se reponen sin tocar.
        $corte = strpos($previo, "\n## ");
        $tabla = $corte === false ? $previo : substr($previo, 0, $corte + 1);
        $secciones = $corte === false ? '' : substr($previo, $corte + 1);

        if ($secciones === '') {
            // Archivo previo en formato antiguo (solo tabla): se agregan las secciones.
            $secciones = self::secciones();
        }

        $lineas = array_values(array_filter(
            explode("\n", $tabla),
            fn (string $linea): bool => ! str_starts_with($linea, '| '.$escenario.' |'),
        ));

        if ($metricas === null) {
            $lineas[] = '| '.$escenario.' | '.$semilla.' | '.$this->celda($parametros).' | no ejecutado | '.$this->celda($motivo).' | '.$fecha.' | '.$commit.' |';
        } else {
            foreach ($metricas as $metrica => $valor) {
                $lineas[] = '| '.$escenario.' | '.$semilla.' | '.$this->celda($parametros).' | '.$this->celda((string) $metrica).' | '.$this->celda($valor).' | '.$fecha.' | '.$commit.' |';
            }
        }

        file_put_contents($ruta, implode("\n", $lineas).($secciones === '' ? "\n" : "\n".$secciones));
    }

    /** @param  string|int|float|null  $valor */
    private function celda(mixed $valor): string
    {
        return str_replace('|', '/', (string) ($valor ?? '—'));
    }

    /** Secciones fijas del informe (ficha T-020, paso 8), fuera de la tabla. */
    private static function secciones(): string
    {
        return "## Errores observados\n\nNinguno en la última corrida.\n\n"
            ."## Limitaciones\n\n"
            ."- El hash encadenado prueba integridad y orden del log, no autoría de cada evento (ADR-003:\n"
            ."  sin firmas por evento; la firma Ed25519 de T-019 cubre la huella del lote).\n"
            ."- El canal es simulado (proceso propio con pérdida/latencia/desorden por semilla), no una red real.\n"
            ."- Los reportes son datos sintéticos de demostración (GeneradorSintetico, barrios ficticios).\n";
    }

    /** Commit del repo al momento de medir (AC-023a); 'sin-commit' si git no está disponible. */
    private static function commit(): string
    {
        $corrida = Process::path(base_path())->run(['git', 'rev-parse', '--short', 'HEAD']);

        return $corrida->successful() ? trim($corrida->output()) : 'sin-commit';
    }
}
