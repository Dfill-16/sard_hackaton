<?php

namespace App\Channel;

use App\Sard\Support\Tiempo;
use App\Sard\Support\Uuid7;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Canal simulado (proceso CANAL): pérdida, latencia, desorden y ventanas reproducibles.
 *
 * Los mensajes se guardan en la tabla `mensajes` con su `deliver_at`; el worker
 * `sard:canal` los entrega después. El canal nunca reintenta: reintenta el emisor.
 */
final class Canal
{
    public const ESTADO_EN_COLA = 'en_cola';

    public const ESTADO_PERDIDO = 'perdido';

    public const ESTADO_ENTREGADO = 'entregado';

    public const ESTADO_FALLIDO = 'fallido';

    public const TIMEOUT_ENTREGA_S = 2;

    /** Parámetros que se pueden cambiar en vivo con POST /api/canal/config. */
    public const PARAMETROS_EN_VIVO = ['perdida', 'ventana_abierta_s', 'ventana_cerrada_s', 'saltos'];

    /**
     * Encola un mensaje y decide, de forma reproducible, si se pierde y cuándo se entrega.
     *
     * @param  array{origen: string, destino: string, ruta: string, content_type: string}  $datos
     * @return array{mensaje_id: string, estado: string}
     */
    public function encolar(array $datos, string $cuerpo): array
    {
        $parametros = $this->parametros();
        $ahora = Tiempo::ahora();
        $id = Uuid7::generar();

        $estado = DB::transaction(function () use ($datos, $cuerpo, $parametros, $ahora, $id): string {
            $seq = (int) DB::table('mensajes')->max('seq') + 1;
            $semilla = (int) $parametros['semilla'];

            $perdido = Aleatorio::u($semilla, $seq, Aleatorio::PERDIDA) < (float) $parametros['perdida'];

            $latMin = (int) $parametros['lat_min_ms'];
            $latMax = (int) $parametros['lat_max_ms'];
            $retrasoMs = $latMin + Aleatorio::u($semilla, $seq, Aleatorio::LATENCIA) * ($latMax - $latMin);

            $reordenado = Aleatorio::u($semilla, $seq, Aleatorio::DESORDEN) < (float) $parametros['desorden'];
            if ($reordenado) {
                $retrasoMs += $latMax + Aleatorio::u($semilla, $seq, Aleatorio::RETRASO_DESORDEN) * $latMax;
            }

            $estado = $perdido ? self::ESTADO_PERDIDO : self::ESTADO_EN_COLA;

            DB::table('mensajes')->insert([
                'id' => $id,
                'seq' => $seq,
                'origen' => $datos['origen'],
                'destino' => $datos['destino'],
                'ruta' => $datos['ruta'],
                'content_type' => $datos['content_type'],
                'cuerpo' => $cuerpo,
                'bytes' => strlen($cuerpo),
                'estado' => $estado,
                'deliver_at' => Tiempo::iso($ahora->addMilliseconds((int) round($retrasoMs))),
                'reordenado' => $reordenado,
                'creado_en' => Tiempo::iso($ahora),
            ]);

            return $estado;
        });

        return ['mensaje_id' => $id, 'estado' => $estado];
    }

    /**
     * Entrega los mensajes vencidos. Con la ventana cerrada los pospone a la próxima apertura.
     *
     * @return int mensajes procesados (entregados o fallidos)
     */
    public function despacharPendientes(int $arranqueMs, ?CarbonImmutable $ahora = null): int
    {
        $ahora ??= Tiempo::ahora();
        $parametros = $this->parametros();

        $vencidos = DB::table('mensajes')
            ->where('estado', self::ESTADO_EN_COLA)
            ->where('deliver_at', '<=', Tiempo::iso($ahora))
            ->orderBy('deliver_at')
            ->orderBy('seq')
            ->get();

        if ($vencidos->isEmpty()) {
            return 0;
        }

        $proximaApertura = $this->proximaApertura($arranqueMs, $ahora, $parametros);
        if ($proximaApertura !== null) {
            DB::table('mensajes')
                ->whereIn('id', $vencidos->pluck('id'))
                ->update(['deliver_at' => Tiempo::iso($proximaApertura)]);

            return 0;
        }

        foreach ($vencidos as $mensaje) {
            $estado = $this->entregar($mensaje) ? self::ESTADO_ENTREGADO : self::ESTADO_FALLIDO;
            DB::table('mensajes')->where('id', $mensaje->id)->update(['estado' => $estado]);
        }

        return $vencidos->count();
    }

    /**
     * Momento de la próxima apertura si la ventana está cerrada; null si está abierta.
     *
     * Cerrada cuando (ahora − arranque) mod (abierta + cerrada) ≥ abierta.
     *
     * @param  array<string, mixed>  $parametros
     */
    public function proximaApertura(int $arranqueMs, CarbonImmutable $ahora, array $parametros): ?CarbonImmutable
    {
        $abiertaMs = (int) round((float) $parametros['ventana_abierta_s'] * 1000);
        $cerradaMs = (int) round((float) $parametros['ventana_cerrada_s'] * 1000);
        $cicloMs = $abiertaMs + $cerradaMs;

        if ($cerradaMs <= 0 || $cicloMs <= 0) {
            return null;
        }

        $transcurridoMs = max(0, Tiempo::milisegundos($ahora) - $arranqueMs);
        if ($transcurridoMs % $cicloMs < $abiertaMs) {
            return null;
        }

        $ciclosCompletos = intdiv($transcurridoMs, $cicloMs) + 1;

        return Tiempo::desdeMilisegundos($arranqueMs + $ciclosCompletos * $cicloMs);
    }

    /**
     * @return array{recibidos: int, perdidos: int, entregados: int, reordenados: int, fallidos: int, bytes: int, en_cola: int}
     */
    public function estadisticas(): array
    {
        $fila = DB::table('mensajes')->selectRaw(
            'COUNT(*) AS recibidos,
            SUM(CASE WHEN estado = ? THEN 1 ELSE 0 END) AS perdidos,
            SUM(CASE WHEN estado = ? THEN 1 ELSE 0 END) AS entregados,
            SUM(CASE WHEN reordenado = 1 THEN 1 ELSE 0 END) AS reordenados,
            SUM(CASE WHEN estado = ? THEN 1 ELSE 0 END) AS fallidos,
            SUM(CASE WHEN estado = ? THEN 1 ELSE 0 END) AS en_cola,
            COALESCE(SUM(bytes), 0) AS bytes',
            [self::ESTADO_PERDIDO, self::ESTADO_ENTREGADO, self::ESTADO_FALLIDO, self::ESTADO_EN_COLA]
        )->first();

        return [
            'recibidos' => (int) $fila->recibidos,
            'perdidos' => (int) $fila->perdidos,
            'entregados' => (int) $fila->entregados,
            'reordenados' => (int) $fila->reordenados,
            'fallidos' => (int) $fila->fallidos,
            'bytes' => (int) $fila->bytes,
            'en_cola' => (int) $fila->en_cola,
        ];
    }

    /**
     * Parámetros vigentes: config/sard.php con los cambios en vivo de `canal_parametros` encima.
     *
     * @return array<string, mixed>
     */
    public function parametros(): array
    {
        $parametros = config('sard.canal');

        $enVivo = DB::table('canal_parametros')
            ->whereIn('clave', self::PARAMETROS_EN_VIVO)
            ->pluck('valor', 'clave');

        foreach ($enVivo as $clave => $valor) {
            $parametros[$clave] = $valor;
        }

        return $parametros;
    }

    /** @param  array<string, int|float>  $cambios */
    public function actualizarParametros(array $cambios): void
    {
        foreach ($cambios as $clave => $valor) {
            if (in_array($clave, self::PARAMETROS_EN_VIVO, true)) {
                DB::table('canal_parametros')->updateOrInsert(['clave' => $clave], ['valor' => (string) $valor]);
            }
        }
    }

    /** Registra el arranque del worker: las ventanas se cuentan desde aquí. */
    public function registrarArranque(): int
    {
        $arranqueMs = Tiempo::milisegundos();
        DB::table('canal_parametros')->updateOrInsert(['clave' => 'arranque_ms'], ['valor' => (string) $arranqueMs]);

        return $arranqueMs;
    }

    private function entregar(object $mensaje): bool
    {
        $base = config('sard.urls.'.$mensaje->destino);
        if (! is_string($base) || $base === '') {
            return false;
        }

        try {
            $respuesta = Http::withHeaders(['X-Sard-Origen' => $mensaje->origen])
                ->withBody($mensaje->cuerpo, $mensaje->content_type)
                ->timeout(self::TIMEOUT_ENTREGA_S)
                ->post(rtrim($base, '/').$mensaje->ruta);

            return $respuesta->successful();
        } catch (ConnectionException $excepcion) {
            Log::info('Canal: destino sin respuesta', ['destino' => $mensaje->destino, 'seq' => $mensaje->seq]);

            return false;
        }
    }
}
