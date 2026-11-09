<?php

namespace App\Node\D;

use App\Sard\Nodo\EstadoNodo;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Nodo D :8004 (api.md): vista de campo, alertas recibidas por el canal y acuse «Recibido».
 */
final class CampoController
{
    public function __construct(
        private Acuses $acuses,
        private EstadoNodo $estado,
    ) {}

    /** GET /campo */
    public function vista(): View
    {
        return view('campo.index', [
            'pollingMs' => (int) config('sard.polling_tablero_s') * 1000,
        ]);
    }

    /** GET /api/campo/alertas · lo que ve el equipo de campo (más reciente primero). */
    public function alertas(): JsonResponse
    {
        return response()->json([
            'lider' => $this->estado->lider(),
            'epoca' => $this->estado->epoca(),
            'alertas' => DB::table('alertas_campo')->orderByDesc('enviada_en')->get()->map(fn (object $a): array => [
                'uuid' => $a->uuid,
                'unidad_id' => $a->unidad_id,
                'texto' => $a->texto,
                'enviada_en' => $a->enviada_en,
                'recibida_en' => $a->recibida_en,
                'ack_en' => $a->ack_en,
                'confirmado_en' => $a->confirmado_en,
            ])->all(),
        ]);
    }

    /** POST /api/campo/ack {alerta_uuid} · «Recibido»: el acuse sale por el canal hacia el líder. */
    public function ack(Request $request): JsonResponse
    {
        $uuid = $request->validate(['alerta_uuid' => ['required', 'string']])['alerta_uuid'];

        if (! $this->acuses->marcarRecibido($uuid)) {
            return response()->json(['error' => 'no_encontrada', 'detalle' => 'Esta alerta no llegó a este nodo.'], 404);
        }

        return response()->json(['alerta_uuid' => $uuid, 'estado' => 'enviando_acuse'], 202);
    }

    /** POST /api/entrega/alerta (desde el CANAL, cuerpo compacto de MensajeAlerta). */
    public function entregaAlerta(Request $request): Response|JsonResponse
    {
        $alerta = MensajeAlerta::decodificar($request->getContent());
        if ($alerta === null) {
            return response()->json(['error' => 'alerta_invalida', 'detalle' => 'Cuerpo de alerta no reconocido.'], 400);
        }

        $this->acuses->recibir($alerta);

        return response()->noContent();
    }

    /** POST /api/entrega/ack-recibido {a} (desde el CANAL) · el líder confirma que registró el acuse. */
    public function ackRecibido(Request $request): Response|JsonResponse
    {
        $hex = (string) (json_decode($request->getContent(), true)['a'] ?? '');
        if (! preg_match('/^[0-9a-f]{32}$/', $hex)) {
            return response()->json(['error' => 'confirmacion_invalida'], 400);
        }

        $this->acuses->confirmar(substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20, 12));

        return response()->noContent();
    }

    /** POST /api/lider {nodo, epoca} en D · el nuevo líder se anuncia tras el failover. */
    public function lider(Request $request): Response|JsonResponse
    {
        $datos = $request->validate([
            'nodo' => ['required', 'in:B,C'],
            'epoca' => ['required', 'integer', 'min:1'],
        ]);

        if (! $this->acuses->adoptarLider($datos['nodo'], $datos['epoca'])) {
            return response()->json(['error' => 'epoca_vieja', 'detalle' => 'La época anunciada no es mayor que la local.'], 409);
        }

        return response()->noContent();
    }
}
