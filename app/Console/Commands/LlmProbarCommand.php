<?php

namespace App\Console\Commands;

use App\Agents\A2\ElevacionLlm;
use App\Sard\Llm\Cadena;
use Illuminate\Console\Command;

/**
 * Prueba cada proveedor LLM configurado por separado con un reporte sintético (T-016).
 * Muestra si respondió, cuánto tardó y si el JSON cumple el esquema. Nunca muestra las claves.
 */
final class LlmProbarCommand extends Command
{
    protected $signature = 'sard:llm-probar';

    protected $description = 'Prueba los 3 proveedores LLM configurados con un caso sintético (sin mostrar las claves)';

    public function handle(Cadena $cadena, ElevacionLlm $elevacion): int
    {
        $this->line('LLM_HABILITADO = '.(Cadena::habilitado() ? 'true' : 'false').' · timeout '.config('sard.llm.timeout_s').' s');

        foreach (config('sard.llm.proveedores') as $indice => $p) {
            $faltan = array_keys(array_filter(['URL' => $p['url'] ?? null, 'KEY' => $p['key'] ?? null, 'MODELO' => $p['modelo'] ?? null], fn ($v): bool => empty($v)));
            if ($faltan !== []) {
                $this->warn('LLM_'.($indice + 1).': sin configurar ('.implode(', ', array_map(fn ($c) => 'LLM_'.($indice + 1)."_{$c}", $faltan)).')');
            }
        }

        $normalizado = [
            'reporte_uuid' => 'prueba',
            'tipo' => 0,
            'banderas' => 0,
            'personas' => 3,
            'texto' => 'olor fuerte a químico en el colegio y varios niños tosiendo y mareados',
            'sin_ubicacion' => false,
        ];
        $triaje = ['prioridad' => 'P4', 'senales' => [['origen' => 'personas', 'clave' => 'personas', 'peso' => 2]]];

        $filas = [];
        foreach ($cadena->proveedores() as $proveedor) {
            $inicio = microtime(true);
            $datos = $cadena->intentar($proveedor, ElevacionLlm::SISTEMA, $elevacion->mensaje($normalizado, $triaje), ElevacionLlm::ESQUEMA);
            $filas[] = [
                $proveedor['nombre'],
                $datos === null ? 'falló' : 'ok',
                (int) round((microtime(true) - $inicio) * 1000).' ms',
                $datos === null ? '—' : sprintf('elevar=%s · %s · %s', $datos['elevar'] ? 'sí' : 'no', $datos['prioridad'], mb_substr($datos['explicacion'], 0, 70)),
            ];
        }

        if ($filas === []) {
            $this->error('No hay proveedores configurados: el sistema trabaja en modo reglas.');

            return self::SUCCESS;
        }

        $this->table(['Proveedor · modelo', 'Resultado', 'Tiempo', 'Respuesta (caso sintético)'], $filas);
        $this->line('Si un proveedor falla, revisa storage/logs/laravel.log (se registra el estado HTTP, nunca la clave).');

        return self::SUCCESS;
    }
}
