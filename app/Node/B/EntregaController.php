<?php

namespace App\Node\B;

use App\Agents\A1\Recepcion;
use App\Channel\ClienteCanal;
use App\Jobs\ProcesarReporte;
use App\Protocol\PayloadInvalidoException;
use App\Protocol\PayloadReporte;
use App\Sard\Log\EventLog;
use App\Sard\Nodo\EstadoNodo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * POST /api/entrega/reporte en B y C (mismo código): el canal entrega aquí los payloads de A.
 *
 * - Seguidor → 409 no_lider, sin ACK.
 * - Reporte ya conocido → solo reenvía el ACK (idempotente por UUID).
 * - Nuevo → A1 normaliza, ReporteNormalizado al log y ProcesarReporte (A2 + A3) a la cola.
 * Al final envía el ACK a A por el canal: {reporte_uuid, nodo, epoca} en /api/acks.
 */
final class EntregaController
{
    public const RUTA_ACK = '/api/acks';

    public function __construct(
        private EstadoNodo $estado,
        private EventLog $log,
        private Recepcion $recepcion,
        private ClienteCanal $canal,
    ) {}

    public function reporte(Request $request): JsonResponse
    {
        if (! $this->estado->esLider()) {
            return response()->json(['error' => 'no_lider', 'detalle' => 'Este nodo es seguidor; el líder es '.$this->estado->lider().'.'], 409);
        }

        try {
            $reporte = PayloadReporte::decode($request->getContent());
        } catch (PayloadInvalidoException $excepcion) {
            Log::warning('Entrega rechazada: '.$excepcion->getMessage());

            return response()->json(['error' => 'payload_invalido', 'detalle' => $excepcion->getMessage()], 400);
        }

        $uuid = $reporte['uuid'];
        $duplicado = DB::table('reportes')->where('reporte_uuid', $uuid)->exists();

        if (! $duplicado) {
            $this->log->append('ReporteNormalizado', $this->recepcion->normalizar($reporte), $uuid);
            ProcesarReporte::dispatch($uuid);
        }

        $nodo = (string) config('sard.nodo_role');
        $this->canal->enviarJson('A', self::RUTA_ACK, [
            'reporte_uuid' => $uuid,
            'nodo' => $nodo,
            'epoca' => $this->estado->epoca(),
        ]);

        return response()->json(['reporte_uuid' => $uuid, 'duplicado' => $duplicado, 'nodo' => $nodo]);
    }
}
