<?php

namespace App\Agents\A2;

use App\Sard\Llm\Cadena;
use App\Sard\Reglas\Banderas;
use App\Sard\Reglas\PrioridadIncidente;

/**
 * A2 + LLM (T-016 · REQ-012): después de las reglas, el LLM puede sugerir SUBIR la prioridad.
 *
 * Nunca baja, nunca descarta, nunca decide: una sugerencia igual o menos urgente se ignora
 * (AC-012a). Solo corre si LLM_HABILITADO y el triaje por reglas no es ya P1.
 */
final class ElevacionLlm
{
    public const LARGO_EXPLICACION = 200;

    public const SISTEMA = <<<'TXT'
Eres un asistente de apoyo al triaje de emergencias de una central en Manizales (Colombia).
No diagnosticas ni decides: un coordinador humano revisa todo.
Recibes un reporte ciudadano que las reglas ya clasificaron de P1 (más urgente) a P4 (menos urgente).
Solo puedes sugerir SUBIR la prioridad cuando el texto describe un riesgo para la vida o la salud
que las reglas no captaron. Si no hay motivo claro, no elevas. Nunca sugieras bajar.
Responde SOLO un objeto JSON, sin texto adicional:
{"elevar": true|false, "prioridad": "P1"|"P2"|"P3"|"P4", "explicacion": "máximo 200 caracteres, en español"}
TXT;

    public const ESQUEMA = [
        'elevar' => ['tipo' => 'boolean'],
        'prioridad' => ['tipo' => 'string', 'enum' => ['P1', 'P2', 'P3', 'P4']],
        'explicacion' => ['tipo' => 'string', 'max' => self::LARGO_EXPLICACION],
    ];

    public function __construct(private Cadena $cadena) {}

    /**
     * @param  array<string, mixed>  $normalizado  payload de ReporteNormalizado
     * @param  array<string, mixed>  $triaje  payload de TriajeAsignado (reglas)
     * @return array{reporte_uuid: string, de: string, a: string, explicacion: string, proveedor: string, etiqueta: string}|null
     *                                                                                                                           payload de PrioridadElevadaLLM, o null si no hay elevación
     */
    public function evaluar(array $normalizado, array $triaje): ?array
    {
        if (! Cadena::habilitado() || $triaje['prioridad'] === 'P1') {
            return null;
        }

        $resultado = $this->cadena->completarUnaVez(
            'a2:'.$normalizado['reporte_uuid'],
            self::SISTEMA,
            $this->mensaje($normalizado, $triaje),
            self::ESQUEMA,
        );

        $sugerencia = $resultado['datos'];
        if ($sugerencia === null || $sugerencia['elevar'] !== true) {
            return null;
        }

        // Solo sube: una prioridad igual o menos urgente que la de reglas se ignora.
        if (PrioridadIncidente::nivel($sugerencia['prioridad']) >= PrioridadIncidente::nivel($triaje['prioridad'])) {
            return null;
        }

        return [
            'reporte_uuid' => $normalizado['reporte_uuid'],
            'de' => $triaje['prioridad'],
            'a' => $sugerencia['prioridad'],
            'explicacion' => $sugerencia['explicacion'],
            'proveedor' => (string) $resultado['proveedor'],
            'etiqueta' => 'sugerencia',
        ];
    }

    /**
     * Solo datos sintéticos del reporte: sin device_id ni nada que identifique al emisor.
     *
     * @param  array<string, mixed>  $normalizado
     * @param  array<string, mixed>  $triaje
     */
    public function mensaje(array $normalizado, array $triaje): string
    {
        $banderas = array_map(
            fn (string $clave): string => Banderas::CATALOGO[Banderas::bitDeClave($clave)]['etiqueta'],
            Banderas::claves((int) $normalizado['banderas']),
        );

        return (string) json_encode([
            'tipo' => Banderas::nombreTipo((int) $normalizado['tipo']),
            'banderas_marcadas' => $banderas,
            'personas' => $normalizado['personas'] ?? 'desconocido',
            'texto' => (string) $normalizado['texto'],
            'sin_ubicacion' => (bool) $normalizado['sin_ubicacion'],
            'prioridad_por_reglas' => $triaje['prioridad'],
            'senales_de_reglas' => array_map(fn (array $s): string => "{$s['origen']}:{$s['clave']}", $triaje['senales']),
        ], \JSON_UNESCAPED_UNICODE);
    }
}
