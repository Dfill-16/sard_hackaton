<?php

namespace App\Node\D;

use App\Agents\A6\EnvioAlertas;
use App\Channel\ClienteCanal;
use App\Sard\Nodo\EstadoNodo;
use App\Sard\Support\Tiempo;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Nodo D · vista de campo (T-015): guarda las alertas que llegan por el canal y devuelve el acuse
 * («Recibido») al líder conocido, reintentando hasta que el líder lo confirme.
 */
final class Acuses
{
    public const RUTA_ACK_CAMPO = '/api/entrega/ack-campo';

    public function __construct(
        private ClienteCanal $canal,
        private EstadoNodo $estado,
    ) {}

    /**
     * Alerta entregada por el canal. Idempotente por uuid; si ya tenía acuse sin confirmar,
     * el reenvío del líder indica que no lo recibió: se reintenta el acuse de inmediato.
     *
     * @param  array{alerta_uuid: string, incidente_uuid: string, unidad_id: string, texto: string, enviada_en: string}  $alerta
     */
    public function recibir(array $alerta): void
    {
        $existente = DB::table('alertas_campo')->where('uuid', $alerta['alerta_uuid'])->first();
        if ($existente === null) {
            DB::table('alertas_campo')->insert([
                'uuid' => $alerta['alerta_uuid'],
                'incidente_uuid' => $alerta['incidente_uuid'],
                'unidad_id' => $alerta['unidad_id'],
                'texto' => $alerta['texto'],
                'enviada_en' => $alerta['enviada_en'],
                'recibida_en' => Tiempo::iso(),
            ]);

            return;
        }

        if ($existente->ack_en !== null && $existente->confirmado_en === null) {
            DB::table('alertas_campo')->where('uuid', $existente->uuid)->update(['ack_proximo_intento' => Tiempo::iso()]);
        }
    }

    /** El equipo tocó «Recibido». Idempotente: un segundo toque no cambia la hora del acuse. */
    public function marcarRecibido(string $alertaUuid): bool
    {
        $actualizadas = DB::table('alertas_campo')->where('uuid', $alertaUuid)->whereNull('ack_en')->update([
            'ack_en' => Tiempo::iso(),
            'ack_proximo_intento' => Tiempo::iso(),
        ]);

        if ($actualizadas > 0) {
            $this->ciclo();
        }

        return DB::table('alertas_campo')->where('uuid', $alertaUuid)->exists();
    }

    /** El líder confirmó el acuse: D deja de reintentar. */
    public function confirmar(string $alertaUuid): void
    {
        DB::table('alertas_campo')->where('uuid', $alertaUuid)->whereNull('confirmado_en')->update(['confirmado_en' => Tiempo::iso()]);
    }

    /**
     * Reenvía al líder conocido los acuses aún sin confirmar, con backoff.
     *
     * @return int acuses enviados
     */
    public function ciclo(?CarbonImmutable $ahora = null): int
    {
        $ahora ??= Tiempo::ahora();
        $pendientes = DB::table('alertas_campo')
            ->whereNotNull('ack_en')
            ->whereNull('confirmado_en')
            ->where('ack_proximo_intento', '<=', Tiempo::iso($ahora))
            ->get();

        $lider = $this->estado->lider();
        foreach ($pendientes as $alerta) {
            $this->canal->enviarJson($lider, self::RUTA_ACK_CAMPO, [
                'alerta_uuid' => $alerta->uuid,
                'recibido_en_campo' => $alerta->ack_en,
            ]);

            $intentos = (int) $alerta->ack_intentos + 1;
            DB::table('alertas_campo')->where('uuid', $alerta->uuid)->update([
                'ack_intentos' => $intentos,
                'ack_proximo_intento' => Tiempo::iso($ahora->addMilliseconds(EnvioAlertas::esperaMs($intentos))),
            ]);
        }

        return $pendientes->count();
    }

    /** Nuevo líder anunciado por A5 (fencing por época). Los acuses pendientes salen ya hacia él. */
    public function adoptarLider(string $nodo, int $epoca): bool
    {
        if ($epoca <= $this->estado->epoca()) {
            return false;
        }

        $this->estado->seguir($nodo, $epoca);
        DB::table('alertas_campo')->whereNotNull('ack_en')->whereNull('confirmado_en')->update([
            'ack_intentos' => 0,
            'ack_proximo_intento' => Tiempo::iso(),
        ]);

        return true;
    }
}
