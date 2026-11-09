<?php

namespace App\Agents\A6;

use App\Sard\Llm\Cadena;

/**
 * A6 + LLM (T-016): el LLM solo redacta la justificación (≤ 300 caracteres) a partir de los
 * datos de la plantilla. La unidad, la acción y los pendientes NO cambian. Si falla, queda la
 * plantilla (modo "plantilla").
 */
final class RedaccionLlm
{
    public const LARGO_JUSTIFICACION = 300;

    public const SISTEMA = <<<'TXT'
Redactas en español, para un coordinador humano de emergencias en Manizales, la justificación
de una recomendación de despacho que ya fue calculada por reglas.
No cambies la unidad, la acción ni los pendientes; no inventes datos que no estén en el mensaje.
Tono directo y claro, máximo 300 caracteres, sin saludos.
Responde SOLO un objeto JSON, sin texto adicional: {"justificacion": "..."}
TXT;

    public const ESQUEMA = [
        'justificacion' => ['tipo' => 'string', 'max' => self::LARGO_JUSTIFICACION],
    ];

    public function __construct(private Cadena $cadena) {}

    /**
     * @param  array{unidad_id: string|null, accion: string, justificacion: string, pendientes: array<int, string>, distancia_m: float|int|null}  $propuesta
     * @return array{justificacion: string, modo: string}
     */
    public function redactar(string $incidenteUuid, int $version, array $propuesta): array
    {
        $plantilla = ['justificacion' => $propuesta['justificacion'], 'modo' => Coordinacion::MODO];
        if (! Cadena::habilitado()) {
            return $plantilla;
        }

        $resultado = $this->cadena->completarUnaVez(
            "a6:{$incidenteUuid}:{$version}",
            self::SISTEMA,
            (string) json_encode([
                'accion' => $propuesta['accion'],
                'datos_de_la_plantilla' => $propuesta['justificacion'],
                'pendientes' => $propuesta['pendientes'],
                'distancia_m' => $propuesta['distancia_m'],
            ], \JSON_UNESCAPED_UNICODE),
            self::ESQUEMA,
        );

        $texto = trim((string) ($resultado['datos']['justificacion'] ?? ''));
        if ($texto === '') {
            return $plantilla;
        }

        return ['justificacion' => $texto, 'modo' => Coordinacion::MODO_LLM];
    }
}
