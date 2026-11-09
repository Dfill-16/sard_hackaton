<?php

namespace App\Node\B;

use App\Agents\A5\Resiliencia;
use App\Sard\Log\EventLog;
use App\Sard\Nodo\EstadoNodo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Tráfico de control directo entre B y C (api.md): latidos, líder, réplica, unión,
 * reconciliación con A y aislamiento de demostración. No pasa por el canal simulado.
 */
final class ControlController
{
    public const MAXIMO_FALTANTES = 500;

    public function __construct(
        private EstadoNodo $estado,
        private EventLog $log,
        private Resiliencia $resiliencia,
    ) {}

    /** POST /api/latido {nodo, epoca, seq_max} · el seguidor registra que el líder sigue vivo. */
    public function latido(Request $request): Response|JsonResponse
    {
        if ($this->estado->aislado()) {
            return response()->json(['error' => 'aislado', 'detalle' => 'Nodo aislado (demo).'], 503);
        }

        $datos = $request->validate([
            'nodo' => ['required', 'in:B,C'],
            'epoca' => ['required', 'integer', 'min:1'],
            'seq_max' => ['required', 'integer', 'min:0'],
        ]);

        $epocaLocal = $this->estado->epoca();
        if ($datos['epoca'] < $epocaLocal) {
            return response()->json(['error' => 'epoca_vieja', 'detalle' => "Época local {$epocaLocal}."], 409);
        }

        if ($datos['epoca'] === $epocaLocal && $this->estado->esLider() && $datos['nodo'] !== $this->resiliencia->nodo()) {
            return response()->json(['error' => 'lider_duplicado', 'detalle' => 'Este nodo ya lidera esa época.'], 409);
        }

        if ($this->estado->esLider() || $this->estado->lider() !== $datos['nodo'] || $datos['epoca'] > $epocaLocal) {
            $this->resiliencia->pasarASeguidor($datos['nodo'], $datos['epoca']);
        } else {
            $this->estado->marcarLatido();
        }

        return response()->noContent();
    }

    /** GET /api/lider · quién lidera según este nodo. */
    public function lider(): JsonResponse
    {
        return response()->json(['nodo' => $this->estado->lider(), 'epoca' => $this->estado->epoca()]);
    }

    /** GET /api/eventos?desde={"B":12}&limite=500 · eventos ordenados por (nodo_origen, seq). */
    public function eventos(Request $request): JsonResponse
    {
        if ($this->estado->aislado()) {
            return response()->json(['error' => 'aislado', 'detalle' => 'Nodo aislado (demo).'], 503);
        }

        $desde = json_decode((string) $request->query('desde', '{}'), true);
        if (! is_array($desde)) {
            return response()->json(['error' => 'desde_invalido', 'detalle' => 'desde debe ser un objeto JSON {"B": 12}.'], 422);
        }

        $limite = max(0, min(5000, (int) $request->query('limite', 500)));

        return response()->json([
            'eventos' => $limite === 0 ? [] : $this->log->desde(array_map('intval', $desde), $limite),
            'seq_max_por_origen' => (object) $this->log->seqMaxPorOrigen(),
        ]);
    }

    /** POST /api/eventos/lote {eventos: [...]} · unión: INSERT OR IGNORE por UUID, hash verificado. */
    public function lote(Request $request): JsonResponse
    {
        // Cuerpo JSON crudo: los middlewares TrimStrings y ConvertEmptyStringsToNull alteran
        // los valores ("" → null) y romperían la verificación del hash de cada evento.
        $cuerpo = json_decode($request->getContent(), true);
        $eventos = is_array($cuerpo) ? ($cuerpo['eventos'] ?? null) : null;
        if (! is_array($eventos)) {
            return response()->json(['error' => 'lote_invalido', 'detalle' => 'Se espera {"eventos": [...]}.'], 422);
        }

        $insertados = 0;
        $ignorados = 0;
        $rechazados = [];
        foreach ($eventos as $evento) {
            try {
                $this->log->ingerir($evento) ? $insertados++ : $ignorados++;
            } catch (Throwable $excepcion) {
                $rechazados[] = ['uuid' => $evento['uuid'] ?? null, 'error' => $excepcion->getMessage()];
            }
        }

        return response()->json(['insertados' => $insertados, 'ignorados' => $ignorados, 'rechazados' => $rechazados]);
    }

    /** POST /api/reportes/faltantes {uuids: [...]} · cuáles de esos reportes no están en este nodo (T-012). */
    public function faltantes(Request $request): JsonResponse
    {
        $uuids = $request->validate([
            'uuids' => ['present', 'array', 'max:'.self::MAXIMO_FALTANTES],
            'uuids.*' => ['string'],
        ])['uuids'];

        $presentes = DB::table('reportes')->whereIn('reporte_uuid', $uuids)->pluck('reporte_uuid')->all();

        return response()->json(['faltantes' => array_values(array_diff($uuids, $presentes))]);
    }

    /** POST /api/demo/aislar {on} · solo con SARD_DEMO: deja de enviar y aceptar latidos y réplica. */
    public function aislar(Request $request): Response|JsonResponse
    {
        if (! config('sard.demo')) {
            return response()->json(['error' => 'solo_demo', 'detalle' => 'Requiere SARD_DEMO=true.'], 403);
        }

        $encendido = $request->validate(['on' => ['required', 'boolean']])['on'];
        $this->estado->guardar('aislado', $encendido ? '1' : null);

        return response()->noContent();
    }
}
