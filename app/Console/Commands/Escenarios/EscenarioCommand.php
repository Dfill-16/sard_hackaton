<?php

namespace App\Console\Commands\Escenarios;

use App\Sard\Firma\Canonico;
use App\Sard\Support\Tiempo;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use PDO;
use RuntimeException;
use Throwable;

/**
 * T-020 · Escenarios reproducibles, métricas y estrés (E6 · REQ-009/010/018/023/024/026).
 *
 * Corre contra el stack vivo por HTTP (ADR-001, un solo PC) y mide con evidencia
 * real, nunca estimada. Cada corrida escribe su JSON y sus filas en
 * docs/resultados/resultados.md con fecha y commit; un escenario que no pudo
 * ejecutarse deja la fila «no ejecutado» con el motivo.
 *
 * Precondiciones: stack arriba (start.ps1), SARD_DEMO=true para los escenarios
 * que tocan /api/canal/config o matan/reinician nodos, y LLM apagado.
 */
final class EscenarioCommand extends Command
{
    private const ESCENARIOS = ['bytes', 'perdida', 'rumor-masivo', 'failover', 'union', 'estres', 'acuse'];

    private const NIVELES_PERDIDA = [0.0, 0.1, 0.2, 0.3, 0.4, 0.5, 0.6];

    private const REPORTE_POR_NIVEL = 50;

    private const VENTANA_PERDIDA_S = 180;

    protected $signature = 'sard:escenario
        {escenario : bytes|perdida|rumor-masivo|failover|union|estres|acuse|todos}
        {--semilla=42 : Semilla de la generación de reportes}';

    protected $description = 'T-020: escenarios reproducibles con métricas medidas (bytes, pérdida, rumor-masivo, failover, unión, estrés, acuse)';

    public function handle(Resultado $resultado): int
    {
        $escenario = (string) $this->argument('escenario');
        if (! in_array($escenario, [...self::ESCENARIOS, 'todos'], true)) {
            $this->error("Escenario inválido: [{$escenario}].");
            $this->line('Válidos: bytes, perdida, rumor-masivo, failover, union, estres, acuse, todos.');

            return self::FAILURE;
        }

        $semilla = (int) $this->option('semilla');
        foreach ($escenario === 'todos' ? self::ESCENARIOS : [$escenario] as $nombre) {
            $this->correrConRespaldo($nombre, $semilla, $resultado);
        }

        return self::SUCCESS;
    }

    private function correrConRespaldo(string $nombre, int $semilla, Resultado $resultado): void
    {
        $this->info("Escenario [{$nombre}] (semilla {$semilla}) en curso…");
        try {
            $corrida = $this->correr($nombre, $semilla);
        } catch (Throwable $excepcion) {
            $resultado->guardar($nombre, $semilla, $this->parametrosDe($nombre), null, $excepcion->getMessage());
            $this->error("  No ejecutado: {$excepcion->getMessage()}");

            return;
        }

        $resultado->guardar($nombre, $semilla, $corrida['parametros'], $corrida['metricas'], null);
        foreach ($corrida['metricas'] as $metrica => $valor) {
            $this->line('  '.$metrica.': '.($valor ?? '—'));
        }
    }

    /** @return array{parametros: string, metricas: array<string, string|int|float|null>} */
    private function correr(string $nombre, int $semilla): array
    {
        return match ($nombre) {
            'bytes' => $this->bytes($semilla),
            'perdida' => $this->perdida($semilla),
            'rumor-masivo' => $this->rumorMasivo($semilla),
            'failover' => $this->failover($semilla),
            'union' => $this->union($semilla),
            'estres' => $this->estres($semilla),
            'acuse' => $this->acuse($semilla),
            default => throw new RuntimeException("Escenario desconocido: {$nombre}."),
        };
    }

    private function parametrosDe(string $nombre): string
    {
        return match ($nombre) {
            'bytes' => 'n=100',
            'perdida' => 'n='.self::REPORTE_POR_NIVEL.'/nivel niveles=0..0.6 ventana='.self::VENTANA_PERDIDA_S.'s',
            'rumor-masivo' => 'n=30 --rumor --masivo',
            'failover' => 'n=100 + matar B',
            'union' => 'reiniciar B',
            'estres' => 'n=1000 dispositivos=50 perdida=0',
            'acuse' => 'aprobaciones=3',
            default => '',
        };
    }

    // ------------------------------------------------------------------
    // Escenarios
    // ------------------------------------------------------------------

    /**
     * Paso 1: bytes binario frente a JSON equivalente (deltas de /api/metricas,
     * así la medición no depende de lo que ya hubiera en el Nodo A).
     *
     * @return array{parametros: string, metricas: array<string, string|int|float|null>}
     */
    private function bytes(int $semilla): array
    {
        $antes = $this->metricas();
        $this->sembrar(100, $semilla);
        $despues = $this->metricas();

        $n = $despues['reportes'] - $antes['reportes'];
        if ($n <= 0) {
            throw new RuntimeException('El Nodo A no recibió los 100 reportes de la corrida.');
        }

        $binario = ($despues['bytes_binario'] - $antes['bytes_binario']) / $n;
        $json = ($despues['bytes_json_equivalente'] - $antes['bytes_json_equivalente']) / $n;

        return ['parametros' => 'n=100', 'metricas' => [
            'reportes_medidos' => $n,
            'bytes_binario_promedio' => round($binario, 1),
            'bytes_json_promedio' => round($json, 1),
            'ahorro_pct' => round((1 - $binario / $json) * 100, 1),
        ]];
    }

    /**
     * Paso 2: barrido de pérdida con la ventana de medición de la ficha (180 s por nivel).
     *         Cada nivel mide: reportes confirmados / 50, ACK promedio, intentos promedio.
     *
     * @return array{parametros: string, metricas: array<string, string|int|float|null>}
     */
    private function perdida(int $semilla): array
    {
        $metricas = [];
        $maxCon100 = null;

        foreach (self::NIVELES_PERDIDA as $indice => $nivel) {
            $this->configurarCanal(['perdida' => $nivel]);

            // Semilla + paciencia: medimos sondas hasta 180 s o hasta 100 %.
            $inicioIso = Tiempo::ahora()->toIso8601String();
            $this->sembrar(self::REPORTE_POR_NIVEL, $semilla + $indice);

            $confirmados = $this->sondear(self::VENTANA_PERDIDA_S, function () use (&$inicioIso): ?bool {
                $metricas = $this->metricas();
                $acuses = $this->acusesDesde($inicioIso);
                if (count($acuses) >= self::REPORTE_POR_NIVEL) {
                    return true;
                }

                return null;
            }, 3000);

            if ($confirmados !== true) {
                // Ventana agotada: medir de todos modos.
                $acuses = $this->acusesDesde($inicioIso);
                $confirmados = count($acuses);
            }

            $pct = round($confirmados / self::REPORTE_POR_NIVEL * 100, 1);
            $prefijo = 'perdida_'.$nivel;
            $metricas[$prefijo.'_confirmados'] = $confirmados;
            $metricas[$prefijo.'_confirmado_pct'] = $pct;
            $metricas[$prefijo.'_ack_promedio_s'] = $this->ackPromedio($inicioIso);
            $metricas[$prefijo.'_intentos_promedio'] = $this->intentosPromedio($inicioIso);

            if ($pct === 100.0) {
                $maxCon100 = $nivel;
            }

            $this->line("  pérdida {$nivel}: {$confirmados}/".self::REPORTE_POR_NIVEL.' confirmados');
        }

        $this->restaurarCanal();
        $metricas['perdida_max_100pct'] = $maxCon100;

        return ['parametros' => $this->parametrosDe('perdida'), 'metricas' => $metricas];
    }

    /**
     * ACKs recibidos desde $inicioIso (reportes enviados en esa ventana).
     *
     * @return array<int, string> fechas de ACK
     */
    private function acusesDesde(string $inicioIso): array
    {
        $filas = $this->colaDesde($inicioIso);
        $acuses = [];
        foreach ($filas as $fila) {
            if ($fila['confirmado_en'] !== null) {
                $acuses[] = (string) $fila['confirmado_en'];
            }
        }

        return $acuses;
    }

    /** ACK promedio desde $inicioIso. */
    private function ackPromedio(string $inicioIso): ?float
    {
        $filas = $this->colaDesde($inicioIso);
        $acuses = array_filter(array_map(
            fn (array $fila): ?float => $fila['confirmado_en'] === null ? null : round(
                Tiempo::desdeIso((string) $fila['recibido_en'])->diffInMilliseconds(Tiempo::desdeIso((string) $fila['confirmado_en'])) / 1000,
                1,
            ),
            $filas,
        ));

        return $acuses === [] ? null : round(array_sum($acuses) / count($acuses), 1);
    }

    /** Intentos promedio desde $inicioIso. */
    private function intentosPromedio(string $inicioIso): ?float
    {
        $filas = $this->colaDesde($inicioIso);
        if ($filas === []) {
            return null;
        }

        return round(array_sum(array_column($filas, 'intentos')) / count($filas), 2);
    }

    /**
     * Paso 3: rumor (30 reportes de un dispositivo) + incidente masivo (6 dispositivos ≤ 200 m).
     *
     * @return array{parametros: string, metricas: array<string, string|int|float|null>}
     */
    private function rumorMasivo(int $semilla): array
    {
        $this->sembrar(30, $semilla, ['--rumor' => true, '--masivo' => true]);

        $lider = $this->liderActual();
        $this->esperarEstabilidad(fn (): int => (int) $this->obtener($lider, '/api/tablero/estado?todos=1')['contadores']['total']);

        $estado = $this->obtener($lider, '/api/tablero/estado?todos=1');
        $incidentes = [...$estado['cola'], ...$estado['baja_prioridad'], ...$estado['sin_ubicacion']];

        return ['parametros' => 'n=30 --rumor --masivo', 'metricas' => [
            'reportes_en_a' => $this->metricas()['reportes'],
            'incidentes_total' => $estado['contadores']['total'],
            'incidentes_masivos' => count(array_filter($incidentes, fn (array $i): bool => (bool) $i['masivo'])),
            'incidentes_masivos_declarados' => count(array_filter(
                $this->eventosNuevos($lider, []),
                fn (array $e): bool => $e['tipo'] === 'IncidenteMasivoDeclarado',
            )),
            'posibles_rumores' => count(array_filter($incidentes, fn (array $i): bool => (bool) $i['posible_rumor'])),
            'no_elevados' => count(array_filter($incidentes, fn (array $i): bool => $i['prioridad'] === $i['prioridad_base'])),
            'p1' => $estado['contadores']['P1'],
            'p2' => $estado['contadores']['P2'],
            'p3' => $estado['contadores']['P3'],
            'p4' => $estado['contadores']['P4'],
            'sin_ubicacion' => $estado['contadores']['sin_ubicacion'],
        ]];
    }

    /**
     * Paso 4: se mata B en vivo; C asume y A reenvía lo no replicado (T-012).
     *
     * @return array{parametros: string, metricas: array<string, string|int|float|null>}
     */
    private function failover(int $semilla): array
    {
        $this->exigirDemo('matar B');
        if (($lider = $this->liderActual()) !== 'B') {
            throw new RuntimeException("El líder actual es {$lider}, no B: no hay failover que medir.");
        }

        $antes = $this->metricas();
        $desde = $this->obtener($lider, '/api/eventos')['seq_max_por_origen'] ?? [];

        $this->sembrar(100, $semilla);
        $muerteMs = Tiempo::milisegundos();
        $this->script('matar-nodo.ps1', ['B']);

        $deteccionMs = null;
        $this->sondear(30, function () use (&$deteccionMs): ?array {
            $salud = $this->salud('C');
            if (($salud['rol'] ?? null) === 'lider') {
                $deteccionMs = Tiempo::milisegundos();

                return $salud;
            }

            return null;
        }, 1000);
        if ($deteccionMs === null) {
            throw new RuntimeException('C no asumió como líder en 30 s.');
        }

        // failover_s según el log: LiderAsumido.creado_en − payload.ultimo_latido (ficha, paso 4).
        $evento = $this->sondear(15, function () use ($lider, $desde): ?array {
            foreach ($this->eventosNuevos($lider, $desde) as $nuevo) {
                if ($nuevo['tipo'] === 'LiderAsumido' && ($nuevo['payload']['nodo'] ?? null) === 'C') {
                    return $nuevo;
                }
            }

            return null;
        }, 2000);
        if ($evento === null) {
            throw new RuntimeException('No apareció el evento LiderAsumido de C en 15 s.');
        }

        $ultimoLatido = $evento['payload']['ultimo_latido'] ?? null;
        $failoverS = $ultimoLatido === null ? null : round(
            Tiempo::desdeIso((string) $ultimoLatido)->diffInMilliseconds(Tiempo::desdeIso((string) $evento['creado_en'])) / 1000,
            1,
        );

        // A reenvía a C lo no replicado: se cuenta hasta estabilizar (3 sondeos iguales).
        $previo = null;
        $estables = 0;
        $unicos = 0;
        $limite = Tiempo::milisegundos() + 90_000;
        while (Tiempo::milisegundos() < $limite) {
            usleep(3000000);
            $unicos = $this->reportesUnicosEn('C', $desde);
            $estables = $unicos === $previo ? $estables + 1 : 0;
            $previo = $unicos;
            if ($estables >= 3) {
                break;
            }
        }

        $confirmados = $this->metricas()['confirmados_por_lider'] - $antes['confirmados_por_lider'];

        return ['parametros' => 'n=100 + matar B', 'metricas' => [
            'failover_reloj_s' => round(($deteccionMs - $muerteMs) / 1000, 1),
            'failover_s_latido' => $failoverS,
            'epoca_nueva' => $evento['payload']['epoca'] ?? null,
            'confirmados_en_a' => $confirmados,
            'reportes_unicos_en_c' => $unicos,
            'perdidos' => $confirmados - $unicos,
        ]];
    }

    /**
     * Paso 5: B vuelve como seguidor y se une por UUID (T-013).
     *
     * @return array{parametros: string, metricas: array<string, string|int|float|null>}
     */
    private function union(int $semilla): array
    {
        $this->exigirDemo('reiniciar B');
        $desdeC = $this->obtener('C', '/api/eventos')['seq_max_por_origen'] ?? [];

        $this->script('stop.ps1', ['-Nodos', 'B']);
        $this->script('start.ps1', ['-Nodos', 'B']);

        $reunido = $this->sondear(90, function () use ($desdeC): ?array {
            foreach ($this->eventosNuevos('C', $desdeC) as $nuevo) {
                if ($nuevo['tipo'] === 'NodoReunido' && ($nuevo['payload']['nodo'] ?? null) === 'B') {
                    return $nuevo['payload'];
                }
            }

            return null;
        }, 3000);
        if ($reunido === null) {
            throw new RuntimeException('B no se unió (sin NodoReunido en 90 s): la unión solo ocurre si B era líder al caer.');
        }

        $eventosB = $this->eventosNuevos('B', []);
        $eventosC = $this->eventosNuevos('C', []);

        return ['parametros' => 'reiniciar B', 'metricas' => [
            'insertados_en_union' => $reunido['insertados'] ?? null,
            'ignorados_en_union' => $reunido['ignorados'] ?? null,
            'eventos_b' => count($eventosB),
            'eventos_c' => count($eventosC),
            'duplicados_b' => count($eventosB) - count(array_unique(array_column($eventosB, 'uuid'))),
            'duplicados_c' => count($eventosC) - count(array_unique(array_column($eventosC, 'uuid'))),
            'cadena_a_en_b' => $this->cadena($eventosB, 'A') ? 'ok' : 'ROTO',
            'cadena_b_en_b' => $this->cadena($eventosB, 'B') ? 'ok' : 'ROTO',
            'cadena_a_en_c' => $this->cadena($eventosC, 'A') ? 'ok' : 'ROTO',
            'cadena_b_en_c' => $this->cadena($eventosC, 'B') ? 'ok' : 'ROTO',
        ]];
    }

    /**
     * Paso 6: 1000 reportes de 50 dispositivos con pérdida 0 (caudal máximo; se restaura al salir).
     *
     * @return array{parametros: string, metricas: array<string, string|int|float|null>}
     */
    private function estres(int $semilla): array
    {
        $this->configurarCanal(['perdida' => 0.0]);
        try {
            $antes = $this->metricas();
            $inicioMs = Tiempo::milisegundos();

            $salida = $this->sembrar(1000, $semilla, ['--dispositivos' => 50]);
            $ingestaS = (Tiempo::milisegundos() - $inicioMs) / 1000;
            $rechazados = substr_count($salida, 'Reporte rechazado');

            // La cola de A queda vacía cuando todo reporte obtuvo su ACK del líder.
            if ($this->sondear(600, function (): ?bool {
                return $this->salud('A')['cola_pendiente'] === 0 ? true : null;
            }, 5000) === null) {
                throw new RuntimeException('La cola del Nodo A no se vació en 600 s.');
            }

            $vaciadoS = (Tiempo::milisegundos() - $inicioMs) / 1000;
            $confirmados = $this->metricas()['confirmados_por_lider'] - $antes['confirmados_por_lider'];

            return ['parametros' => 'n=1000 dispositivos=50 perdida=0', 'metricas' => [
                'reportes' => $this->metricas()['reportes'] - $antes['reportes'],
                'rechazados' => $rechazados,
                'ingesta_s' => round($ingestaS, 1),
                'reportes_s_en_a' => round(1000 / max(0.1, $ingestaS), 1),
                'vaciado_cola_s' => round($vaciadoS, 1),
                'confirmados_en_lider' => $confirmados,
                'reportes_s_hasta_lider' => round($confirmados / max(0.1, $vaciadoS), 1),
            ]];
        } finally {
            $this->restaurarCanal();
        }
    }

    /**
     * Paso 7: tres aprobaciones → segundos entre AlertaCampoEnviada y AckCampo (T-015, T-010).
     *
     * @return array{parametros: string, metricas: array<string, string|int|float|null>}
     */
    private function acuse(int $semilla): array
    {
        $lider = $this->liderActual();
        $pendientes = $this->recomendacionesPendientes($lider);
        if ($pendientes === []) {
            throw new RuntimeException('No hay recomendaciones pendientes que aprobar.');
        }

        $desde = $this->obtener($lider, '/api/eventos')['seq_max_por_origen'] ?? [];
        $aprobadas = 0;
        foreach (array_slice($pendientes, 0, 3) as $uuid) {
            if ($this->aprobar($lider, $uuid)) {
                $aprobadas++;
            }
        }
        if ($aprobadas === 0) {
            throw new RuntimeException('Ninguna aprobación pudo aplicarse (409 sin recomendación alternativa).');
        }

        // La alerta sale por el canal hacia D y el acuse vuelve por el canal: se espera hasta 180 s.
        $this->sondear(180, function () use ($lider, $desde, $aprobadas): ?bool {
            $acuses = array_filter($this->eventosNuevos($lider, $desde), fn (array $e): bool => $e['tipo'] === 'AckCampo');

            return count($acuses) >= $aprobadas ? true : null;
        }, 5000);

        $alertas = [];
        $segundos = [];
        foreach ($this->eventosNuevos($lider, $desde) as $nuevo) {
            if ($nuevo['tipo'] === 'AlertaCampoEnviada') {
                $alertas[$nuevo['payload']['alerta_uuid']] = $nuevo['creado_en'];
            }

            $alertaUuid = $nuevo['tipo'] === 'AckCampo' ? ($nuevo['payload']['alerta_uuid'] ?? null) : null;
            if ($alertaUuid !== null && isset($alertas[$alertaUuid])) {
                $segundos[] = round(
                    Tiempo::desdeIso((string) $alertas[$alertaUuid])->diffInMilliseconds(Tiempo::desdeIso((string) $nuevo['creado_en'])) / 1000,
                    1,
                );
            }
        }

        return ['parametros' => 'aprobaciones=3', 'metricas' => [
            'aprobaciones' => $aprobadas,
            'alertas_enviadas' => count($alertas),
            'acuses_recibidos' => count($segundos),
            'acuses_faltantes' => $aprobadas - count($segundos),
            'ack_promedio_s' => $segundos === [] ? null : round(array_sum($segundos) / count($segundos), 1),
            'ack_max_s' => $segundos === [] ? null : max($segundos),
        ]];
    }

    // ------------------------------------------------------------------
    // Ayudas HTTP y de proceso
    // ------------------------------------------------------------------

    private function url(string $nodo): string
    {
        return rtrim((string) config('sard.urls.'.$nodo), '/');
    }

    /** @return array<string, mixed> */
    private function obtener(string $nodo, string $ruta): array
    {
        try {
            $respuesta = Http::acceptJson()->timeout(15)->get($this->url($nodo).$ruta);
        } catch (ConnectionException $excepcion) {
            throw new RuntimeException("{$nodo} no responde: ".$excepcion->getMessage());
        }

        if (! $respuesta->successful()) {
            throw new RuntimeException("GET {$ruta} en {$nodo} devolvió HTTP {$respuesta->status()}.");
        }

        return $respuesta->json() ?? [];
    }

    /** @return array<string, mixed> */
    private function salud(string $nodo): array
    {
        return $this->obtener($nodo, '/api/salud');
    }

    /** @return array<string, mixed> */
    private function metricas(): array
    {
        return $this->obtener('A', '/api/metricas');
    }

    private function liderActual(): string
    {
        foreach (['B', 'C'] as $nodo) {
            try {
                if ($this->salud($nodo)['rol'] === 'lider') {
                    return $nodo;
                }
            } catch (RuntimeException) {
                continue;
            }
        }

        throw new RuntimeException('Ningún nodo B o C es líder: ¿el stack está arriba?');
    }

    /**
     * Siembra con sard:sembrar (los reportes salen por HTTP al Nodo A).
     *
     * @param  array<string, bool|int>  $opciones  flags de sard:sembrar (--rumor, --masivo, --dispositivos)
     */
    private function sembrar(int $n, int $semilla, array $opciones = []): string
    {
        $codigo = Artisan::call('sard:sembrar', ['n' => $n, '--semilla' => $semilla] + $opciones);
        $salida = trim(Artisan::output());
        if ($codigo !== 0) {
            throw new RuntimeException('sard:sembrar falló: '.$salida);
        }

        return $salida;
    }

    private function configurarCanal(array $cambios): void
    {
        $respuesta = Http::acceptJson()->timeout(5)->post($this->url('CANAL').'/api/canal/config', $cambios);
        if ($respuesta->status() === 403) {
            throw new RuntimeException('/api/canal/config requiere SARD_DEMO=true.');
        }

        if (! $respuesta->successful()) {
            throw new RuntimeException('POST /api/canal/config devolvió HTTP '.$respuesta->status().'.');
        }
    }

    /** Restaura la pérdida configurada (los escenarios deben ser independientes). */
    private function restaurarCanal(): void
    {
        $this->configurarCanal(['perdida' => (float) config('sard.canal.perdida')]);
    }

    private function exigirDemo(string $operacion): void
    {
        if (! config('sard.demo')) {
            throw new RuntimeException("La operación [{$operacion}] requiere SARD_DEMO=true.");
        }
    }

    /** @param  array<int, string>  $argumentos */
    private function script(string $archivo, array $argumentos): void
    {
        $corrida = Process::run(['powershell', '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', base_path('scripts/'.$archivo), ...$argumentos]);
        if (! $corrida->successful()) {
            throw new RuntimeException("El script {$archivo} falló: ".trim($corrida->errorOutput()));
        }
    }

    /**
     * Eventos de un nodo a partir del punto de sincronía, paginando hasta terminar.
     *
     * @param  array<string, int>|(object)  $desde  seq máx por origen ya conocidos
     * @return array<int, array<string, mixed>>
     */
    private function eventosNuevos(string $nodo, array|object $desde, int $limite = 5000): array
    {
        $todos = [];
        $cursor = (object) $desde;
        do {
            $respuesta = $this->obtener($nodo, '/api/eventos?desde='.urlencode(json_encode($cursor)).'&limite='.$limite);
            $lote = $respuesta['eventos'] ?? [];
            $todos = [...$todos, ...$lote];
            $cursor = (object) ($respuesta['seq_max_por_origen'] ?? []);
        } while ($lote !== []);

        return $todos;
    }

    /**
     * Reportes únicos que el nodo recibió del Nodo A (eventos ReporteRecibido ingeridos).
     *
     * @param  array<string, int>  $desde
     */
    private function reportesUnicosEn(string $nodo, array $desde): int
    {
        $uuids = array_map(
            fn (array $evento): string => (string) $evento['payload']['reporte_uuid'],
            array_filter($this->eventosNuevos($nodo, $desde), fn (array $evento): bool => $evento['tipo'] === 'ReporteRecibido'),
        );

        return count(array_unique($uuids));
    }

    /**
     * Recomendaciones propuestas sin decisión, según el log del líder.
     *
     * @return array<int, string>
     */
    private function recomendacionesPendientes(string $lider): array
    {
        $propuestas = [];
        foreach ($this->eventosNuevos($lider, []) as $evento) {
            if ($evento['tipo'] === 'RecomendacionPropuesta') {
                $propuestas[$evento['payload']['recomendacion_uuid']] = true;
            }

            if ($evento['tipo'] === 'DecisionHumana') {
                unset($propuestas[$evento['payload']['recomendacion_uuid']]);
            }
        }

        return array_keys($propuestas);
    }

    /** Aprueba una recomendación; si la unidad ya salió, intenta la versión nueva que propone A6. */
    private function aprobar(string $lider, string $recomendacionUuid): bool
    {
        $cuerpo = ['recomendacion_uuid' => $recomendacionUuid, 'accion' => 'aprobar', 'operador' => 'sard:escenario'];
        $respuesta = Http::acceptJson()->timeout(5)->post($this->url($lider).'/api/decisiones', $cuerpo);

        if ($respuesta->status() === 409 && is_string($nueva = $respuesta->json('nueva_recomendacion_uuid'))) {
            $respuesta = Http::acceptJson()->timeout(5)->post($this->url($lider).'/api/decisiones', [...$cuerpo, 'recomendacion_uuid' => $nueva]);
        }

        if (! $respuesta->successful()) {
            $this->warn("  Aprobación de [{$recomendacionUuid}] rechazada: HTTP ".$respuesta->status());

            return false;
        }

        return true;
    }

    /**
     * Verifica la cadena de hash de un origen sobre los eventos traídos por HTTP:
     * mismo algoritmo que EventLog::verificarCadena con el canónico de T-003.
     *
     * @param  array<int, array<string, mixed>>  $eventos
     */
    private function cadena(array $eventos, string $origen): bool
    {
        $previo = str_repeat('0', 64);
        $delOrigen = array_values(array_filter($eventos, fn (array $evento): bool => $evento['nodo_origen'] === $origen));
        usort($delOrigen, fn (array $a, array $b): int => $a['seq'] <=> $b['seq']);

        foreach ($delOrigen as $evento) {
            if (($evento['hash_prev'] ?? null) !== $previo) {
                return false;
            }

            $hash = hash('sha256', $previo.'|'.Canonico::json([
                'uuid' => $evento['uuid'],
                'nodo_origen' => $evento['nodo_origen'],
                'seq' => $evento['seq'],
                'epoca' => $evento['epoca'],
                'tipo' => $evento['tipo'],
                'ref_uuid' => $evento['ref_uuid'],
                'creado_en' => $evento['creado_en'],
                'payload' => $evento['payload'],
            ]));

            if ($hash !== $evento['hash']) {
                return false;
            }

            $previo = $evento['hash'];
        }

        return true;
    }

    /**
     * Filas de cola_salida del Nodo A recibidas desde $desdeIso, leídas con una conexión
     * propia en modo de solo lectura (misma PC, ADR-001): intentos y tiempos de ACK que
     * /api/metricas no expone.
     *
     * @return array<int, array{recibido_en: string, confirmado_en: ?string, intentos: int}>
     */
    private function colaDesde(string $desdeIso): array
    {
        $base = database_path('nodo_a.sqlite');
        if (! is_file($base)) {
            throw new RuntimeException("No existe la base del Nodo A en [{$base}].");
        }

        $uri = 'sqlite:file:///'.str_replace('\\', '/', $base).'?mode=ro';
        try {
            $pdo = new PDO($uri);
        } catch (\PDOException) {
            $pdo = new PDO('sqlite:'.$base); // si el URI no aplica, la guarda queda igual
        }

        $pdo->exec('PRAGMA query_only = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');

        $consulta = $pdo->prepare('SELECT recibido_en, confirmado_en, intentos FROM cola_salida WHERE recibido_en >= ?');
        $consulta->execute([$desdeIso]);
        $filas = $consulta->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $pdo = null;

        return $filas;
    }

    /**
     * Sondea $paso hasta que devuelva algo distinto de null o se agote el tiempo (null).
     */
    private function sondear(int $segundos, callable $paso, int $intervaloMs = 1000): mixed
    {
        $fin = Tiempo::milisegundos() + $segundos * 1000;
        while (true) {
            $valor = $paso();
            if ($valor !== null) {
                return $valor;
            }

            if (Tiempo::milisegundos() >= $fin) {
                return null;
            }

            usleep($intervaloMs * 1000);
        }
    }

    /**
     * Espera a que el valor observado deje de cambiar ($iguales sondeos seguidos).
     */
    private function esperarEstabilidad(callable $valor, int $segundos = 120, int $iguales = 2): void
    {
        $anterior = null;
        $vistos = 0;
        $fin = Tiempo::milisegundos() + $segundos * 1000;
        while (Tiempo::milisegundos() < $fin) {
            usleep(2000000);
            $actual = $valor();
            $vistos = $actual === $anterior ? $vistos + 1 : 0;
            $anterior = $actual;
            if ($vistos >= $iguales) {
                return;
            }
        }

        throw new RuntimeException('El procesamiento no se estabilizó en '.$segundos.' s.');
    }
}
