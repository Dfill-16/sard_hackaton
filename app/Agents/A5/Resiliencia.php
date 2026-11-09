<?php

namespace App\Agents\A5;

use App\Jobs\ProcesarReporte;
use App\Sard\Log\EventLog;
use App\Sard\Nodo\EstadoNodo;
use App\Sard\Support\Tiempo;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A5 · Resiliencia (B y C). Lógica convencional: NO usa LLM (REQ-015, AC-015a).
 *
 * - Líder: envía un latido directo (fuera del canal simulado) al otro nodo cada LATIDO_S.
 * - Seguidor: replica el log del líder y, si pasan LATIDOS_PERDIDOS·LATIDO_S sin latido,
 *   sube de época, asume, avisa a A y D y reanuda lo que el líder anterior no terminó.
 * - Al volver (T-013): descubre la época mayor, queda como seguidor y une su log por UUID.
 */
final class Resiliencia
{
    public const TIMEOUT_S = 2;

    public const GRACIA_ARRANQUE_S = 30;

    public const LOTE_EVENTOS = 500;

    public const LOTE_UNION = 200;

    public function __construct(
        private EstadoNodo $estado,
        private EventLog $log,
    ) {}

    public function nodo(): string
    {
        return (string) config('sard.nodo_role');
    }

    /** El otro nodo del par B/C. */
    public function otro(): string
    {
        return $this->nodo() === 'B' ? 'C' : 'B';
    }

    /**
     * Pregunta al otro nodo quién lidera. Si tiene una época mayor, este nodo lo sigue y queda
     * pendiente de unir su log (T-013). Devuelve true si cambió a seguidor.
     */
    public function descubrir(): bool
    {
        $respuesta = $this->pedir('get', $this->otro(), '/api/lider');
        if ($respuesta === null || ! $respuesta->successful()) {
            return false;
        }

        $epocaRemota = (int) $respuesta->json('epoca');
        if ($epocaRemota <= $this->estado->epoca()) {
            return false;
        }

        $this->pasarASeguidor((string) $respuesta->json('nodo'), $epocaRemota);

        return true;
    }

    /** Sigue a un líder de época mayor, reinicia el vigilante y marca la unión pendiente. */
    public function pasarASeguidor(string $lider, int $epoca): void
    {
        $eraLider = $this->estado->esLider();
        $this->estado->seguir($lider, $epoca);
        $this->estado->marcarLatido();
        if ($eraLider) {
            $this->estado->guardar('pendiente_union', '1');
        }
    }

    /**
     * Latido del líder. Un 409 (época vieja) significa que otro nodo asumió: se redescubre.
     */
    public function latir(): void
    {
        if ($this->estado->aislado() || ! $this->estado->esLider()) {
            return;
        }

        $respuesta = $this->pedir('post', $this->otro(), '/api/latido', [
            'nodo' => $this->nodo(),
            'epoca' => $this->estado->epoca(),
            'seq_max' => $this->log->seqMaxPorOrigen()[$this->nodo()] ?? 0,
        ]);

        if ($respuesta === null) {
            return;
        }

        if ($respuesta->status() === 409) {
            $this->descubrir();

            return;
        }

        if ($respuesta->successful()) {
            $this->estado->marcarLatido();
        }
    }

    /**
     * Vigilante del seguidor: asume si el líder lleva más de LATIDOS_PERDIDOS·LATIDO_S sin latir.
     * Sin ningún latido aún, empieza a contar GRACIA_ARRANQUE_S después de arrancar.
     *
     * @return bool true si asumió en este tick
     */
    public function vigilar(int $arranqueMs): bool
    {
        if ($this->estado->aislado() || $this->estado->esLider()) {
            return false;
        }

        $ultimo = $this->estado->ultimoLatido();
        $referenciaMs = $ultimo !== null
            ? Tiempo::milisegundos(Tiempo::desdeIso($ultimo))
            : $arranqueMs + self::GRACIA_ARRANQUE_S * 1000;

        $limiteMs = (int) config('sard.latidos_perdidos') * (int) config('sard.latido_s') * 1000;
        if (Tiempo::milisegundos() - $referenciaMs <= $limiteMs) {
            return false;
        }

        $this->asumir();

        return true;
    }

    /** Sube de época, emite LiderAsumido y reanuda el procesamiento pendiente. */
    public function asumir(): void
    {
        $anterior = $this->estado->lider();
        $ultimoLatido = $this->estado->ultimoLatido();
        $epoca = $this->estado->epoca() + 1;

        $this->estado->asumir($epoca);
        $this->log->append('LiderAsumido', [
            'nodo' => $this->nodo(),
            'epoca' => $epoca,
            'anterior' => $anterior,
            'motivo' => 'latidos_perdidos',
            'ultimo_latido' => $ultimoLatido,
        ]);

        Log::warning("A5: {$this->nodo()} asume como líder en la época {$epoca} (anterior {$anterior}).");
        $this->reanudar();
    }

    /**
     * Avisa el nuevo líder a A y D. Devuelve los destinos que hay que reintentar
     * (sin respuesta). Un 404 se ignora: el destino aún no implementa la ruta.
     *
     * @param  array<int, string>  $destinos
     * @return array<int, string>
     */
    public function notificarLider(array $destinos): array
    {
        $pendientes = [];
        foreach ($destinos as $destino) {
            $respuesta = $this->pedir('post', $destino, '/api/lider', ['nodo' => $this->nodo(), 'epoca' => $this->estado->epoca()]);
            if ($respuesta === null || $respuesta->serverError()) {
                $pendientes[] = $destino;
            }
        }

        return $pendientes;
    }

    /**
     * Reencola los reportes que el líder anterior no terminó (sin triaje, sin incidente
     * o con incidente sin recomendación). ProcesarReporte es idempotente.
     *
     * @return int reportes reencolados
     */
    public function reanudar(): int
    {
        $sinTriajeOIncidente = DB::table('reportes')
            ->whereNotIn('reporte_uuid', DB::table('triajes')->select('reporte_uuid'))
            ->orWhereNotIn('reporte_uuid', DB::table('incidente_reporte')->select('reporte_uuid'))
            ->pluck('reporte_uuid');

        $incidentesSinRecomendacion = DB::table('incidente_reporte')
            ->whereNotIn('incidente_uuid', DB::table('recomendaciones')->select('incidente_uuid'))
            ->groupBy('incidente_uuid')
            ->selectRaw('MIN(reporte_uuid) AS reporte_uuid')
            ->pluck('reporte_uuid');

        $uuids = $sinTriajeOIncidente->merge($incidentesSinRecomendacion)->unique()->values();
        foreach ($uuids as $uuid) {
            ProcesarReporte::dispatch($uuid);
        }

        return $uuids->count();
    }

    /**
     * Réplica del seguidor: trae del líder los eventos que faltan, por seq de cada origen.
     *
     * @return int eventos insertados
     */
    public function replicar(): int
    {
        if ($this->estado->aislado() || $this->estado->esLider()) {
            return 0;
        }

        $insertados = 0;
        do {
            $respuesta = $this->pedir('get', $this->estado->lider(), '/api/eventos', [
                'desde' => json_encode((object) $this->log->seqMaxPorOrigen()),
                'limite' => self::LOTE_EVENTOS,
            ]);
            if ($respuesta === null || ! $respuesta->successful()) {
                break;
            }

            $eventos = $respuesta->json('eventos') ?? [];
            foreach ($eventos as $evento) {
                if ($this->ingerirSeguro($evento)) {
                    $insertados++;
                }
            }
        } while (count($eventos) === self::LOTE_EVENTOS);

        return $insertados;
    }

    /**
     * Unión al volver (T-013): envía al líder lo que solo tiene este nodo, trae lo que le falta
     * y registra NodoReunido. Conmutativa e idempotente: repetirla no inserta nada.
     *
     * @return array{insertados: int, ignorados: int, traidos: int}|null null si el líder no responde
     */
    public function unir(): ?array
    {
        if ($this->estado->esLider()) {
            $this->estado->guardar('pendiente_union', null);

            return null;
        }

        $lider = $this->estado->lider();
        $puntoLider = $this->pedir('get', $lider, '/api/eventos', ['desde' => json_encode((object) $this->log->seqMaxPorOrigen()), 'limite' => 1]);
        if ($puntoLider === null || ! $puntoLider->successful()) {
            return null;
        }

        $seqLider = $puntoLider->json('seq_max_por_origen') ?? [];
        $resultado = $this->enviarLote($lider, $this->log->desde($seqLider, PHP_INT_MAX));
        if ($resultado === null) {
            return null;
        }

        $traidos = $this->replicar();

        $reunido = $this->log->append('NodoReunido', [
            'nodo' => $this->nodo(),
            'epoca_lider' => $this->estado->epoca(),
            'insertados' => $resultado['insertados'],
            'ignorados' => $resultado['ignorados'],
        ]);
        $reunido['payload'] = json_decode($reunido['payload'], true);
        $this->enviarLote($lider, [$reunido]);

        $this->estado->guardar('pendiente_union', null);
        Log::info("A5: {$this->nodo()} unido a {$lider}", [...$resultado, 'traidos' => $traidos]);

        return [...$resultado, 'traidos' => $traidos];
    }

    /**
     * @param  array<int, array<string, mixed>>  $eventos
     * @return array{insertados: int, ignorados: int}|null
     */
    private function enviarLote(string $destino, array $eventos): ?array
    {
        $total = ['insertados' => 0, 'ignorados' => 0];
        foreach (array_chunk($eventos, self::LOTE_UNION) as $lote) {
            $respuesta = $this->pedir('post', $destino, '/api/eventos/lote', ['eventos' => $lote]);
            if ($respuesta === null || ! $respuesta->successful()) {
                return null;
            }
            $total['insertados'] += (int) $respuesta->json('insertados');
            $total['ignorados'] += (int) $respuesta->json('ignorados');
        }

        return $total;
    }

    /** @param  array<string, mixed>  $evento */
    private function ingerirSeguro(array $evento): bool
    {
        try {
            return $this->log->ingerir($evento);
        } catch (Throwable $excepcion) {
            Log::error('A5: evento replicado rechazado', ['uuid' => $evento['uuid'] ?? null, 'error' => $excepcion->getMessage()]);

            return false;
        }
    }

    /** @param  array<string, mixed>  $datos */
    private function pedir(string $metodo, string $destino, string $ruta, array $datos = []): ?Response
    {
        $base = config("sard.urls.{$destino}");
        if (! is_string($base) || $base === '') {
            return null;
        }

        try {
            return Http::withHeaders([
                'X-Sard-Nodo' => $this->nodo(),
                'X-Sard-Epoca' => (string) $this->estado->epoca(),
            ])->acceptJson()->timeout(self::TIMEOUT_S)->{$metodo}(rtrim($base, '/').$ruta, $datos);
        } catch (ConnectionException $excepcion) {
            return null;
        }
    }
}
