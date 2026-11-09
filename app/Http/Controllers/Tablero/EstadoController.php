<?php

namespace App\Http\Controllers\Tablero;

use App\Sard\Llm\Cadena;
use App\Sard\Nodo\EstadoNodo;
use App\Sard\Reglas\Brechas;
use App\Sard\Support\Tiempo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/tablero/estado?todos=0|1 · estado de la cola para el tablero (polling).
 *
 * Nada se oculta: la cola corta muestra el top 10 de P1-P2, pero los P3-P4 y los
 * reportes «sin ubicación» siempre vienen completos y con conteo.
 */
final class EstadoController
{
    public const TOPE_COLA = 10;

    public function __construct(
        private EstadoNodo $estado,
        private Presentador $presentador,
        private Brechas $brechas,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $ahora = Tiempo::ahora();
        $incidentes = $this->presentador->incidentes($ahora);

        $conUbicacion = $incidentes->where('sin_ubicacion', false);
        $urgentes = $conUbicacion->whereIn('prioridad', ['P1', 'P2'])->values();
        $todos = $request->boolean('todos');

        $contadores = ['P1' => 0, 'P2' => 0, 'P3' => 0, 'P4' => 0];
        foreach ($incidentes as $incidente) {
            $contadores[$incidente['prioridad']]++;
        }

        return response()->json([
            'nodo' => config('sard.nodo_role'),
            'lider' => $this->estado->lider(),
            'epoca' => $this->estado->epoca(),
            'rol' => $this->estado->rol(),
            // T-016: «modo LLM» solo si está habilitado y la última llamada no cayó a reglas.
            'modo' => Cadena::habilitado() && $this->estado->valor('llm_estado') !== 'fallo' ? 'llm' : 'reglas',
            'llm_proveedor' => Cadena::habilitado() ? $this->estado->valor('llm_proveedor') : null,
            'ultimo_latido_hace_s' => $this->estado->ultimoLatidoHaceS(),
            'aislado' => $this->estado->aislado(),
            'generado_en' => Tiempo::iso($ahora),
            'contadores' => [
                ...$contadores,
                'total' => $incidentes->count(),
                'sin_ubicacion' => $incidentes->where('sin_ubicacion', true)->count(),
            ],
            'cola_total' => $urgentes->count(),
            'cola' => $todos ? $urgentes : $urgentes->take(self::TOPE_COLA)->values(),
            'baja_prioridad' => $conUbicacion->whereIn('prioridad', ['P3', 'P4'])->values(),
            'sin_ubicacion' => $incidentes->where('sin_ubicacion', true)->values(),
            // T-017: capas de demostración y brechas de cobertura.
            'recursos' => $this->brechas->recursos(),
            'unidades' => $this->brechas->unidades(),
            'brechas' => $this->brechas->calcular($urgentes),
        ]);
    }
}
