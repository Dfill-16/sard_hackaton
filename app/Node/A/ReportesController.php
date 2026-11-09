<?php

namespace App\Node\A;

use App\Protocol\PayloadInvalidoException;
use App\Protocol\PayloadReporte;
use App\Sard\Log\EventLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ingesta de reportes binarios para el Nodo A.
 *
 * Recibe un payload v1, lo valida con el codec de T-002, lo guarda de forma
 * durable en `eventos` y `cola_salida`, y responde un ACK de 17 bytes
 * (0x41 + 16 bytes del UUID) sin duplicar.
 */
final class ReportesController
{
    public const ACK_TIPO = 0x41;

    public const ACK_LONGITUD = 17;

    public const MAXIMO_BYTES_PAYLOAD = 176;

    /** @var EventLog */
    private $eventLog;

    public function __construct(EventLog $eventLog)
    {
        $this->eventLog = $eventLog;
    }

    /**
     * Almacena un reporte binario entrante.
     *
     * @return Response
     */
    public function almacenar(Request $request)
    {
        $cuerpo = $request->getContent();

        if (strlen($cuerpo) > self::MAXIMO_BYTES_PAYLOAD) {
            return response()->json([
                'error' => 'payload_demasiado_grande',
                'detalle' => 'El payload excede los 176 bytes permitidos.',
            ], 413);
        }

        try {
            $decodificado = PayloadReporte::decode($cuerpo);
        } catch (PayloadInvalidoException $e) {
            Log::warning('Reporte binario rechazado: '.$e->getMessage());

            return response()->json([
                'error' => 'payload_invalido',
                'detalle' => $e->getMessage(),
            ], 400);
        }

        $uuid = $decodificado['uuid'];
        $bytes = strlen($cuerpo);

        // Idempotencia: si el uuid ya está en cola_salida, devolver el mismo ACK sin insertar.
        $existente = DB::table('cola_salida')->where('reporte_uuid', $uuid)->first();
        if ($existente) {
            return $this->ackRespuesta($uuid);
        }

        $payloadB64 = base64_encode($cuerpo);
        $creadoEn = $this->fechaUtcAhora();

        DB::transaction(function () use ($cuerpo, $decodificado, $uuid, $bytes, $payloadB64, $creadoEn) {
            $this->eventLog->append(
                'ReporteRecibido',
                [
                    'reporte_uuid' => $uuid,
                    'device_id' => dechex($decodificado['device_id']),
                    'bytes' => $bytes,
                    'payload_b64' => $payloadB64,
                ],
                $uuid
            );

            DB::table('cola_salida')->insert([
                'reporte_uuid' => $uuid,
                'payload' => $cuerpo,
                'bytes' => $bytes,
                'device_id' => $decodificado['device_id'],
                'recibido_en' => $creadoEn,
                'puntaje_base' => null,
                'intentos' => 0,
                'proximo_intento' => $creadoEn,
                'enviado_a' => null,
                'epoca_envio' => null,
                'confirmado_en' => null,
                'confirmado_por' => null,
            ]);
        });

        return $this->ackRespuesta($uuid);
    }

    /** Devuelve el ACK binario de 17 bytes: 0x41 + 16 bytes del uuid. */
    private function ackRespuesta(string $uuid): Response
    {
        $hexUuid = str_replace('-', '', $uuid);
        $ack = chr(self::ACK_TIPO).hex2bin($hexUuid);

        return response($ack, 200)
            ->header('Content-Type', 'application/octet-stream');
    }

    private function fechaUtcAhora(): string
    {
        $ahora = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $milis = str_pad((string) ((int) ($ahora->format('u') / 1000)), 3, '0', STR_PAD_LEFT);

        return $ahora->format('Y-m-d\TH:i:s.').$milis.'Z';
    }
}
