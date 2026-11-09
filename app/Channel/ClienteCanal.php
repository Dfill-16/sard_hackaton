<?php

namespace App\Channel;

use App\Sard\Nodo\EstadoNodo;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente para enviar mensajes por el canal simulado (POST /api/canal/enviar).
 *
 * Lo usan A4 (reportes de A al líder) y el líder (ACK de vuelta a A).
 */
final class ClienteCanal
{
    public const TIMEOUT_S = 2;

    public function __construct(private EstadoNodo $estado) {}

    /**
     * @return array{mensaje_id: string, estado: string}|null null si el canal no respondió
     */
    public function enviar(string $destino, string $ruta, string $contentType, string $cuerpo): ?array
    {
        $origen = (string) config('sard.nodo_role', 'A');

        try {
            $respuesta = Http::withHeaders([
                'X-Sard-Nodo' => $origen,
                'X-Sard-Epoca' => (string) $this->estado->epoca(),
            ])
                ->acceptJson()
                ->timeout(self::TIMEOUT_S)
                ->post(rtrim((string) config('sard.urls.CANAL'), '/').'/api/canal/enviar', [
                    'origen' => $origen,
                    'destino' => $destino,
                    'ruta' => $ruta,
                    'content_type' => $contentType,
                    'cuerpo_b64' => base64_encode($cuerpo),
                ]);
        } catch (ConnectionException $excepcion) {
            Log::info('Canal no disponible', ['destino' => $destino, 'ruta' => $ruta]);

            return null;
        }

        if ($respuesta->status() !== 202) {
            Log::warning('Canal rechazó el mensaje', ['status' => $respuesta->status(), 'ruta' => $ruta]);

            return null;
        }

        return [
            'mensaje_id' => (string) $respuesta->json('mensaje_id'),
            'estado' => (string) $respuesta->json('estado'),
        ];
    }

    /** @param  array<string, mixed>  $datos */
    public function enviarJson(string $destino, string $ruta, array $datos): ?array
    {
        return $this->enviar($destino, $ruta, 'application/json', (string) json_encode($datos, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));
    }
}
