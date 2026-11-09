<?php

namespace App\Agents\A4;

use App\Channel\ClienteCanal;
use App\Protocol\PayloadInvalidoException;
use App\Protocol\PayloadReporte;
use App\Sard\Nodo\EstadoNodo;
use App\Sard\Reglas\Puntaje;
use App\Sard\Support\Tiempo;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * A4 · Comunicación (Nodo A, sin IA, sin LLM).
 *
 * Decide qué reporte sale primero hacia el líder y reintenta con backoff hasta el ACK.
 * Orden: puntaje_efectivo DESC, bytes ASC, reporte_uuid ASC, donde
 * puntaje_efectivo = puntaje_base + min(A4_ENVEJ_TOPE, ⌊minutos_en_cola / A4_ENVEJ_MIN⌋).
 */
final class Comunicacion
{
    public const LOTE = 10;

    public const RUTA_ENTREGA = '/api/entrega/reporte';

    public const LOTE_FALTANTES = 500;

    public const INTENTOS_ANTES_DE_DESCUBRIR = 4;

    public const INTERVALO_DESCUBRIMIENTO_S = 10;

    public const TIMEOUT_CONTROL_S = 2;

    public function __construct(
        private ClienteCanal $canal,
        private EstadoNodo $estado,
    ) {}

    /**
     * Un ciclo de A4: toma hasta 10 reportes vencidos, los envía al líder por el canal
     * y reprograma su próximo intento.
     *
     * @return int reportes enviados al canal en este ciclo
     */
    public function ciclo(?CarbonImmutable $ahora = null): int
    {
        $ahora ??= Tiempo::ahora();
        $this->reconciliar($ahora);
        $this->descubrirLiderSiNoHayAcks($ahora);

        $lote = $this->siguienteLote($ahora);
        if ($lote->isEmpty()) {
            return 0;
        }

        $lider = $this->estado->lider();
        $epoca = $this->estado->epoca();

        foreach ($lote as $fila) {
            $this->canal->enviar($lider, self::RUTA_ENTREGA, 'application/octet-stream', $fila->payload);

            $intentos = (int) $fila->intentos + 1;
            DB::table('cola_salida')->where('reporte_uuid', $fila->reporte_uuid)->update([
                'intentos' => $intentos,
                'proximo_intento' => Tiempo::iso($ahora->addMilliseconds($this->esperaMs($intentos))),
                'enviado_a' => $lider,
                'epoca_envio' => (string) $epoca,
            ]);
        }

        return $lote->count();
    }

    /**
     * Adopta un nuevo líder (T-012). Solo con época mayor a la local (fencing).
     * Los no confirmados reinician su backoff para salir de inmediato hacia el nuevo líder,
     * y queda pendiente reconciliar lo que confirmó el líder anterior.
     */
    public function adoptarLider(string $nodo, int $epoca, ?CarbonImmutable $ahora = null): bool
    {
        if ($epoca <= $this->estado->epoca()) {
            return false;
        }

        $anterior = $this->estado->lider();
        $this->estado->seguir($nodo, $epoca);
        if ($anterior !== $nodo) {
            $this->estado->guardar('pendiente_reconciliar', $anterior);
        }

        DB::table('cola_salida')->whereNull('confirmado_en')->update([
            'intentos' => 0,
            'proximo_intento' => Tiempo::iso($ahora),
        ]);

        return true;
    }

    /**
     * Reconciliación (una vez por cambio de líder): pregunta al nuevo líder cuáles de los reportes
     * que confirmó el anterior no tiene, y los devuelve a la cola. Idempotente por UUID.
     *
     * @return int|null reportes devueltos a la cola; null si no había nada pendiente o el líder no respondió
     */
    public function reconciliar(?CarbonImmutable $ahora = null): ?int
    {
        $anterior = $this->estado->valor('pendiente_reconciliar');
        if ($anterior === null) {
            return null;
        }

        $lider = $this->estado->lider();
        $devueltos = 0;
        $uuids = DB::table('cola_salida')->where('confirmado_por', $anterior)->orderBy('reporte_uuid')->pluck('reporte_uuid');

        foreach ($uuids->chunk(self::LOTE_FALTANTES) as $lote) {
            $respuesta = $this->control('post', $lider, '/api/reportes/faltantes', ['uuids' => $lote->values()->all()]);
            if ($respuesta === null || ! $respuesta->successful()) {
                return null;
            }

            $faltantes = $respuesta->json('faltantes') ?? [];
            $devueltos += DB::table('cola_salida')->whereIn('reporte_uuid', $faltantes)->update([
                'confirmado_en' => null,
                'confirmado_por' => null,
                'intentos' => 0,
                'proximo_intento' => Tiempo::iso($ahora),
            ]);
        }

        $this->estado->guardar('pendiente_reconciliar', null);

        return $devueltos;
    }

    /**
     * Si hay reportes que llevan varios intentos sin ACK (p. ej. el anuncio del nuevo líder no llegó
     * o el destino responde 409 no_lider), consulta GET /api/lider en B y C y adopta la mayor época.
     * Como mucho una vez cada INTERVALO_DESCUBRIMIENTO_S.
     */
    public function descubrirLiderSiNoHayAcks(?CarbonImmutable $ahora = null): void
    {
        $ahora ??= Tiempo::ahora();
        $ultimo = $this->estado->valor('ultimo_descubrimiento');
        if ($ultimo !== null && Tiempo::desdeIso($ultimo)->addSeconds(self::INTERVALO_DESCUBRIMIENTO_S) > $ahora) {
            return;
        }

        $atascados = DB::table('cola_salida')
            ->whereNull('confirmado_en')
            ->where('intentos', '>=', self::INTENTOS_ANTES_DE_DESCUBRIR)
            ->exists();
        if (! $atascados) {
            return;
        }

        $this->estado->guardar('ultimo_descubrimiento', Tiempo::iso($ahora));

        $mejor = null;
        foreach (['B', 'C'] as $nodo) {
            $respuesta = $this->control('get', $nodo, '/api/lider');
            if ($respuesta !== null && $respuesta->successful() && ($mejor === null || (int) $respuesta->json('epoca') > $mejor['epoca'])) {
                $mejor = ['nodo' => (string) $respuesta->json('nodo'), 'epoca' => (int) $respuesta->json('epoca')];
            }
        }

        if ($mejor !== null) {
            $this->adoptarLider($mejor['nodo'], $mejor['epoca'], $ahora);
        }
    }

    /** Llamada de control directa (fuera del canal simulado) a B o C. */
    private function control(string $metodo, string $destino, string $ruta, array $datos = []): ?Response
    {
        $base = config("sard.urls.{$destino}");
        if (! is_string($base) || $base === '') {
            return null;
        }

        try {
            return Http::withHeaders(['X-Sard-Nodo' => 'A', 'X-Sard-Epoca' => (string) $this->estado->epoca()])
                ->acceptJson()
                ->timeout(self::TIMEOUT_CONTROL_S)
                ->{$metodo}(rtrim($base, '/').$ruta, $datos);
        } catch (ConnectionException $excepcion) {
            return null;
        }
    }

    /**
     * Reportes no confirmados con proximo_intento vencido, ya ordenados por la política de A4.
     *
     * @return Collection<int, object>
     */
    public function siguienteLote(CarbonImmutable $ahora, int $limite = self::LOTE): Collection
    {
        $candidatos = DB::table('cola_salida')
            ->whereNull('confirmado_en')
            ->where('proximo_intento', '<=', Tiempo::iso($ahora))
            ->get();

        foreach ($candidatos as $fila) {
            if ($fila->puntaje_base === null) {
                $fila->puntaje_base = $this->puntajeBase($fila->payload);
                DB::table('cola_salida')
                    ->where('reporte_uuid', $fila->reporte_uuid)
                    ->update(['puntaje_base' => $fila->puntaje_base]);
            }

            $fila->puntaje_efectivo = $this->puntajeEfectivo((int) $fila->puntaje_base, $fila->recibido_en, $ahora);
        }

        return $candidatos
            ->sort(fn (object $a, object $b): int => [$b->puntaje_efectivo, $a->bytes, $a->reporte_uuid]
                <=> [$a->puntaje_efectivo, $b->bytes, $b->reporte_uuid])
            ->take($limite)
            ->values();
    }

    public function puntajeEfectivo(int $puntajeBase, string $recibidoEn, CarbonImmutable $ahora): int
    {
        $minutosEnCola = max(0, Tiempo::desdeIso($recibidoEn)->diffInSeconds($ahora)) / 60;
        $envejecimiento = (int) floor($minutosEnCola / max(1, (int) config('sard.a4_envej_min')));

        return $puntajeBase + min((int) config('sard.a4_envej_tope'), $envejecimiento);
    }

    /** Puntaje base con las reglas compartidas sobre el payload decodificado. */
    public function puntajeBase(string $payload): int
    {
        try {
            $reporte = PayloadReporte::decode($payload);
        } catch (PayloadInvalidoException $excepcion) {
            return 0;
        }

        $personas = $reporte['personas'] === 0 ? null : $reporte['personas'];

        return Puntaje::calcular($reporte['banderas'], $personas, $reporte['texto'])['puntaje'];
    }

    /** Backoff 1, 2, 4, 8, 16, 30 s (tope 30) con jitter ±20 %, sin límite de intentos. */
    public function esperaMs(int $intentos): int
    {
        $serie = config('sard.backoff_serie');
        $base = $serie[min(max(0, $intentos - 1), count($serie) - 1)];
        $jitter = (float) config('sard.backoff_jitter');
        $factor = 1 + $jitter * (mt_rand() / mt_getrandmax() * 2 - 1);

        return (int) round($base * 1000 * $factor);
    }
}
