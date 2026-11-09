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

    /** Canal multisalto (T-018): de 1 a 3 saltos, por CANAL_SALTOS o POST /api/canal/config. */
    public const SALTOS_MAXIMOS = 3;

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
            $saltos = max(1, min(self::SALTOS_MAXIMOS, (int) $parametros['saltos']));
            $tramo = $this->tramo($parametros, $seq, 1);

            $estado = $tramo['perdido'] ? self::ESTADO_PERDIDO : self::ESTADO_EN_COLA;

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
                'deliver_at' => Tiempo::iso($ahora->addMilliseconds($tramo['retraso_ms'])),
                'reordenado' => $tramo['reordenado'],
                'creado_en' => Tiempo::iso($ahora),
                'saltos' => $saltos,
                'salto_actual' => 1,
                'perdido_en_salto' => $tramo['perdido'] ? 1 : null,
            ]);

            return $estado;
        });

        return ['mensaje_id' => $id, 'estado' => $estado];
    }

    /**
     * Sorteo reproducible de un tramo (salto) del canal: pérdida, latencia y desorden.
     *
     * El salto s usa u(k + 4·(s − 1)); así un canal de 1 salto es idéntico al de T-006.
     *
     * @param  array<string, mixed>  $parametros
     * @return array{perdido: bool, retraso_ms: int, reordenado: bool}
     */
    public function tramo(array $parametros, int $seq, int $salto): array
    {
        $semilla = (int) $parametros['semilla'];
        $desplazamiento = 4 * ($salto - 1);
        $u = fn (int $k): float => Aleatorio::u($semilla, $seq, $k + $desplazamiento);

        $latMin = (int) $parametros['lat_min_ms'];
        $latMax = (int) $parametros['lat_max_ms'];
        $retrasoMs = $latMin + $u(Aleatorio::LATENCIA) * ($latMax - $latMin);

        $reordenado = $u(Aleatorio::DESORDEN) < (float) $parametros['desorden'];
        if ($reordenado) {
            $retrasoMs += $latMax + $u(Aleatorio::RETRASO_DESORDEN) * $latMax;
        }

        return [
            'perdido' => $u(Aleatorio::PERDIDA) < (float) $parametros['perdida'],
            'retraso_ms' => (int) round($retrasoMs),
            'reordenado' => $reordenado,
        ];
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

        $entregados = 0;
        foreach ($vencidos as $mensaje) {
            // Multisalto (T-018): solo se entrega al destino tras el último salto.
            if ((int) $mensaje->salto_actual < (int) $mensaje->saltos) {
                $this->avanzarSalto($mensaje, $parametros, $ahora);

                continue;
            }

            $estado = $this->entregar($mensaje) ? self::ESTADO_ENTREGADO : self::ESTADO_FALLIDO;
            DB::table('mensajes')->where('id', $mensaje->id)->update(['estado' => $estado]);
            $entregados++;
        }

        return $entregados;
    }

    /**
     * El mensaje llegó al repetidor intermedio: sortea el siguiente tramo y lo reprograma
     * (o lo marca perdido en ese salto).
     *
     * @param  array<string, mixed>  $parametros
     */
    private function avanzarSalto(object $mensaje, array $parametros, CarbonImmutable $ahora): void
    {
        $salto = (int) $mensaje->salto_actual + 1;
        $tramo = $this->tramo($parametros, (int) $mensaje->seq, $salto);

        DB::table('mensajes')->where('id', $mensaje->id)->update([
            'salto_actual' => $salto,
            'estado' => $tramo['perdido'] ? self::ESTADO_PERDIDO : self::ESTADO_EN_COLA,
            'perdido_en_salto' => $tramo['perdido'] ? $salto : null,
            'deliver_at' => Tiempo::iso($ahora->addMilliseconds($tramo['retraso_ms'])),
            'reordenado' => (bool) $mensaje->reordenado || $tramo['reordenado'],
        ]);
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
     * @return array{recibidos: int, perdidos: int, entregados: int, reordenados: int, fallidos: int, bytes: int, en_cola: int, saltos: int, perdidos_por_salto: array<int, int>}
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

        $porSalto = DB::table('mensajes')
            ->whereNotNull('perdido_en_salto')
            ->groupBy('perdido_en_salto')
            ->pluck(DB::raw('COUNT(*)'), 'perdido_en_salto');

        $saltosVistos = max(1, (int) DB::table('mensajes')->max('saltos'), (int) $this->parametros()['saltos']);
        $perdidosPorSalto = [];
        for ($salto = 1; $salto <= min(self::SALTOS_MAXIMOS, $saltosVistos); $salto++) {
            $perdidosPorSalto[] = (int) ($porSalto[$salto] ?? 0);
        }

        return [
            'recibidos' => (int) $fila->recibidos,
            'perdidos' => (int) $fila->perdidos,
            'entregados' => (int) $fila->entregados,
            'reordenados' => (int) $fila->reordenados,
            'fallidos' => (int) $fila->fallidos,
            'bytes' => (int) $fila->bytes,
            'en_cola' => (int) $fila->en_cola,
            'saltos' => (int) $this->parametros()['saltos'],
            'perdidos_por_salto' => $perdidosPorSalto,
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
