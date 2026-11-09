<?php

namespace App\Agents\A6;

use App\Channel\ClienteCanal;
use App\Node\D\MensajeAlerta;
use App\Sard\Log\EventLog;
use App\Sard\Nodo\EstadoNodo;
use App\Sard\Support\Tiempo;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Envío de las alertas aprobadas al Nodo D por el canal y registro del acuse de campo (T-015).
 *
 * Solo envía lo que ya está en `alertas`, que solo nace de AlertaCampoEnviada, que a su vez solo
 * nace de una DecisionHumana (AC-016a). Reintenta 1-2-4-8-16-30 s hasta que exista AckCampo.
 */
final class EnvioAlertas
{
    public const RUTA_ALERTA = '/api/entrega/alerta';

    public const RUTA_ACK_RECIBIDO = '/api/entrega/ack-recibido';

    public const LOTE = 10;

    public function __construct(
        private ClienteCanal $canal,
        private EstadoNodo $estado,
        private EventLog $log,
    ) {}

    /**
     * Envía (o reenvía) las alertas sin acuse cuyo próximo intento venció. Solo en el líder.
     *
     * @return int alertas enviadas al canal
     */
    public function ciclo(?CarbonImmutable $ahora = null): int
    {
        if (! $this->estado->esLider()) {
            return 0;
        }

        $ahora ??= Tiempo::ahora();
        $pendientes = DB::table('alertas')
            ->whereNull('ack_en')
            ->where(fn ($q) => $q->whereNull('proximo_intento')->orWhere('proximo_intento', '<=', Tiempo::iso($ahora)))
            ->orderBy('enviada_en')
            ->limit(self::LOTE)
            ->get();

        foreach ($pendientes as $alerta) {
            $this->canal->enviar('D', self::RUTA_ALERTA, 'application/json', MensajeAlerta::codificar([
                'alerta_uuid' => $alerta->uuid,
                'incidente_uuid' => $alerta->incidente_uuid,
                'unidad_id' => $alerta->unidad_id,
                'texto' => $alerta->texto,
                'enviada_en' => $alerta->enviada_en,
            ], (int) config('sard.canal.mtu_b')));

            $intentos = (int) $alerta->intentos + 1;
            DB::table('alertas')->where('uuid', $alerta->uuid)->update([
                'intentos' => $intentos,
                'proximo_intento' => Tiempo::iso($ahora->addMilliseconds(self::esperaMs($intentos))),
            ]);
        }

        return $pendientes->count();
    }

    /**
     * El equipo de campo confirmó la alerta. Emite AckCampo una sola vez y siempre devuelve
     * la confirmación a D (así D deja de reintentar aunque la primera confirmación se pierda).
     *
     * @return bool|null true si emitió AckCampo, false si ya existía, null si la alerta no está en este nodo
     */
    public function registrarAcuse(string $alertaUuid, string $recibidoEnCampo): ?bool
    {
        if (! DB::table('alertas')->where('uuid', $alertaUuid)->exists()) {
            return null;
        }

        $nuevo = ! DB::table('eventos')->where('tipo', 'AckCampo')->where('ref_uuid', $alertaUuid)->exists();
        if ($nuevo) {
            $this->log->append('AckCampo', [
                'alerta_uuid' => $alertaUuid,
                'recibido_en_campo' => $recibidoEnCampo,
            ], $alertaUuid);
        }

        $this->canal->enviarJson('D', self::RUTA_ACK_RECIBIDO, ['a' => str_replace('-', '', $alertaUuid)]);

        return $nuevo;
    }

    /** Backoff 1, 2, 4, 8, 16, 30 s con jitter ±20 % (parametros.md). */
    public static function esperaMs(int $intentos): int
    {
        $serie = config('sard.backoff_serie');
        $base = $serie[min(max(0, $intentos - 1), count($serie) - 1)];
        $jitter = (float) config('sard.backoff_jitter');

        return (int) round($base * 1000 * (1 + $jitter * (mt_rand() / mt_getrandmax() * 2 - 1)));
    }
}
