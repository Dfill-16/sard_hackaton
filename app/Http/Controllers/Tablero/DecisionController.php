<?php

namespace App\Http\Controllers\Tablero;

use App\Agents\A6\Coordinacion;
use App\Sard\Nodo\EstadoNodo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/decisiones · el coordinador aprueba o descarta una recomendación (T-010, REQ-016).
 *
 * Única puerta hacia AlertaCampoEnviada: sin DecisionHumana no hay alerta a campo.
 */
final class DecisionController
{
    public function __construct(
        private EstadoNodo $estado,
        private Coordinacion $coordinacion,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        if (! $this->estado->esLider()) {
            return response()->json([
                'error' => 'no_lider',
                'detalle' => 'Este nodo es seguidor; las decisiones se toman en el líder ('.$this->estado->lider().').',
            ], 409);
        }

        $datos = $request->validate([
            'recomendacion_uuid' => ['required', 'string'],
            'accion' => ['required', 'in:aprobar,descartar'],
            'motivo' => ['nullable', 'string', 'max:500', 'required_if:accion,descartar'],
            'operador' => ['required', 'string', 'max:60'],
        ], [
            'motivo.required_if' => 'Para descartar hay que escribir el motivo.',
            'operador.required' => 'Escribe tu nombre de operador antes de decidir.',
        ]);

        $recomendacion = DB::table('recomendaciones')->where('uuid', $datos['recomendacion_uuid'])->first();
        if ($recomendacion === null) {
            return response()->json(['error' => 'no_encontrada', 'detalle' => 'La recomendación no existe en este nodo.'], 404);
        }

        // La recomendación se propuso antes de que otra aprobación asignara su unidad: no se despacha
        // dos veces la misma unidad. A6 propone una versión nueva con una unidad libre.
        $yaAprobada = DB::table('decisiones')->where('recomendacion_uuid', $recomendacion->uuid)->where('accion', 'aprobar')->exists();
        if ($datos['accion'] === 'aprobar' && $recomendacion->unidad_id !== null && ! $yaAprobada
            && ! $this->coordinacion->unidadDisponible($recomendacion->unidad_id)) {
            $nueva = $this->coordinacion->evaluar($recomendacion->incidente_uuid, redactarConLlm: false);

            return response()->json([
                'error' => 'unidad_no_disponible',
                'detalle' => 'Esa unidad ya fue asignada a otro incidente. A6 propuso una nueva recomendación: revísala antes de aprobar.',
                'nueva_recomendacion_uuid' => $nueva,
            ], 409);
        }

        $resultado = $this->coordinacion->decidir(
            $recomendacion,
            $datos['accion'],
            isset($datos['motivo']) ? trim($datos['motivo']) : null,
            trim($datos['operador']),
        );

        return response()->json($resultado, 201);
    }
}
