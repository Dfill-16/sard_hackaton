<?php

namespace App\Sard\Llm;

use App\Sard\Nodo\EstadoNodo;
use App\Sard\Support\Tiempo;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cadena de LLM gratuitos compatibles con OpenAI (T-016 · ADR-001): proveedor 1 → 2 → 3 → reglas.
 *
 * - Cada proveedor tiene LLM_TIMEOUT_S; ante error, respuesta inválida o JSON que no cumple el
 *   esquema, pasa al siguiente. Si todos fallan devuelve null y el sistema sigue por reglas.
 * - La clave solo viaja en la cabecera Authorization: jamás se escribe en logs ni en la caché.
 * - Solo se le envían datos sintéticos del reporte (nunca el device_id).
 *
 * Esquema: ['campo' => ['tipo' => 'boolean'|'string', 'enum' => [...]?, 'max' => int?]].
 * Un texto más largo que `max` se recorta; un tipo o valor fuera del enum invalida la respuesta.
 */
final class Cadena
{
    public const MAXIMO_TOKENS = 300;

    /** Proveedor que respondió en la última llamada exitosa ("OpenRouter · modelo"). */
    private ?string $proveedorUsado = null;

    public function __construct(private EstadoNodo $estado) {}

    public static function habilitado(): bool
    {
        return filter_var(config('sard.llm_habilitado'), FILTER_VALIDATE_BOOL);
    }

    /**
     * @param  array<string, array{tipo: string, enum?: array<int, string>, max?: int}>  $esquema
     * @return array<string, mixed>|null respuesta validada, o null si ningún proveedor sirvió
     */
    public function completar(string $sistema, string $usuario, array $esquema): ?array
    {
        $this->proveedorUsado = null;

        foreach ($this->proveedores() as $proveedor) {
            $datos = $this->intentar($proveedor, $sistema, $usuario, $esquema);
            if ($datos !== null) {
                $this->proveedorUsado = $proveedor['nombre'];
                $this->registrarEstado('ok', $proveedor['nombre']);
                Log::info('LLM: respuesta válida', ['proveedor' => $proveedor['nombre']]);

                return $datos;
            }
        }

        $this->registrarEstado('fallo', null);
        Log::warning('LLM: ningún proveedor respondió; se sigue en modo reglas.');

        return null;
    }

    /**
     * Igual que completar(), pero una sola vez por clave (reporte o incidente+versión), aunque falle.
     *
     * @param  array<string, array{tipo: string, enum?: array<int, string>, max?: int}>  $esquema
     * @return array{datos: array<string, mixed>|null, proveedor: string|null}
     */
    public function completarUnaVez(string $clave, string $sistema, string $usuario, array $esquema): array
    {
        $guardada = DB::table('llm_cache')->where('clave', $clave)->first();
        if ($guardada !== null) {
            return [
                'datos' => $guardada->respuesta === null ? null : json_decode($guardada->respuesta, true),
                'proveedor' => $guardada->proveedor,
            ];
        }

        $datos = $this->completar($sistema, $usuario, $esquema);
        DB::table('llm_cache')->insertOrIgnore([
            'clave' => $clave,
            'proveedor' => $this->proveedorUsado,
            'respuesta' => $datos === null ? null : json_encode($datos, \JSON_UNESCAPED_UNICODE),
            'creado_en' => Tiempo::iso(),
        ]);

        return ['datos' => $datos, 'proveedor' => $this->proveedorUsado];
    }

    public function proveedorUsado(): ?string
    {
        return $this->proveedorUsado;
    }

    /**
     * Proveedores configurados (con URL, clave y modelo), en orden.
     *
     * @return array<int, array{nombre: string, url: string, key: string, modelo: string}>
     */
    public function proveedores(): array
    {
        $proveedores = [];
        foreach (config('sard.llm.proveedores', []) as $p) {
            if (empty($p['url']) || empty($p['key']) || empty($p['modelo'])) {
                continue;
            }
            $proveedores[] = [
                'nombre' => self::nombreDe((string) $p['url']).' · '.$p['modelo'],
                'url' => rtrim((string) $p['url'], '/'),
                'key' => (string) $p['key'],
                'modelo' => (string) $p['modelo'],
            ];
        }

        return $proveedores;
    }

    /**
     * Un intento contra un proveedor. Devuelve null ante cualquier error (nunca lanza).
     *
     * @param  array{nombre: string, url: string, key: string, modelo: string}  $proveedor
     * @param  array<string, array{tipo: string, enum?: array<int, string>, max?: int}>  $esquema
     * @return array<string, mixed>|null
     */
    public function intentar(array $proveedor, string $sistema, string $usuario, array $esquema): ?array
    {
        $cuerpo = [
            'model' => $proveedor['modelo'],
            'messages' => [
                ['role' => 'system', 'content' => $sistema],
                ['role' => 'user', 'content' => $usuario],
            ],
            'temperature' => 0,
            'max_tokens' => self::MAXIMO_TOKENS,
            'response_format' => ['type' => 'json_object'],
        ];

        // Los Nemotron de NVIDIA razonan por defecto y agotan los tokens antes del JSON:
        // se les pide responder sin razonamiento (respuesta en ~1 s en vez de cortarse).
        if (str_contains($proveedor['url'], 'nvidia.com')) {
            $cuerpo['chat_template_kwargs'] = ['enable_thinking' => false];
        }
        // OpenRouter: mismo problema con los modelos que razonan; los demás ignoran el parámetro.
        if (str_contains($proveedor['url'], 'openrouter.ai')) {
            $cuerpo['reasoning'] = ['enabled' => false];
        }

        try {
            $respuesta = $this->llamar($proveedor, $cuerpo);
            // Algunos modelos no aceptan estos parámetros opcionales: se reintenta una vez sin ellos.
            if ($respuesta->status() === 400) {
                unset($cuerpo['response_format'], $cuerpo['chat_template_kwargs'], $cuerpo['reasoning']);
                $respuesta = $this->llamar($proveedor, $cuerpo);
            }
        } catch (ConnectionException $excepcion) {
            Log::warning('LLM: proveedor sin respuesta (timeout o red)', ['proveedor' => $proveedor['nombre']]);

            return null;
        } catch (Throwable $excepcion) {
            Log::warning('LLM: error al llamar al proveedor', ['proveedor' => $proveedor['nombre'], 'error' => class_basename($excepcion)]);

            return null;
        }

        if (! $respuesta->successful()) {
            Log::warning('LLM: el proveedor respondió con error', ['proveedor' => $proveedor['nombre'], 'status' => $respuesta->status()]);

            return null;
        }

        $datos = $this->extraerJson((string) $respuesta->json('choices.0.message.content', ''));
        $validos = $datos === null ? null : $this->validar($datos, $esquema);
        if ($validos === null) {
            Log::warning('LLM: respuesta que no cumple el esquema', ['proveedor' => $proveedor['nombre']]);
        }

        return $validos;
    }

    /**
     * @param  array{nombre: string, url: string, key: string, modelo: string}  $proveedor
     * @param  array<string, mixed>  $cuerpo
     */
    private function llamar(array $proveedor, array $cuerpo): Response
    {
        $timeout = max(1, (int) config('sard.llm.timeout_s', 5));

        return Http::withToken($proveedor['key'])
            ->acceptJson()
            ->connectTimeout($timeout)
            ->timeout($timeout)
            ->post($proveedor['url'].'/chat/completions', $cuerpo);
    }

    /**
     * Saca el objeto JSON del contenido (acepta bloques ```json ... ``` o texto alrededor).
     *
     * @return array<string, mixed>|null
     */
    public function extraerJson(string $contenido): ?array
    {
        $inicio = strpos($contenido, '{');
        $fin = strrpos($contenido, '}');
        if ($inicio === false || $fin === false || $fin < $inicio) {
            return null;
        }

        $datos = json_decode(substr($contenido, $inicio, $fin - $inicio + 1), true);

        return is_array($datos) ? $datos : null;
    }

    /**
     * @param  array<string, mixed>  $datos
     * @param  array<string, array{tipo: string, enum?: array<int, string>, max?: int}>  $esquema
     * @return array<string, mixed>|null
     */
    public function validar(array $datos, array $esquema): ?array
    {
        $validos = [];
        foreach ($esquema as $campo => $regla) {
            if (! array_key_exists($campo, $datos)) {
                return null;
            }
            $valor = $datos[$campo];

            if ($regla['tipo'] === 'boolean') {
                if (! is_bool($valor)) {
                    return null;
                }
            } elseif ($regla['tipo'] === 'string') {
                if (! is_string($valor)) {
                    return null;
                }
                $valor = trim($valor);
                if (isset($regla['enum']) && ! in_array($valor, $regla['enum'], true)) {
                    return null;
                }
                if (isset($regla['max']) && mb_strlen($valor) > $regla['max']) {
                    $valor = rtrim(mb_substr($valor, 0, $regla['max'] - 1)).'…';
                }
            }

            $validos[$campo] = $valor;
        }

        return $validos;
    }

    /** «modo LLM / modo reglas» del tablero: lo decide el resultado de la última llamada. */
    private function registrarEstado(string $estado, ?string $proveedor): void
    {
        $this->estado->guardar('llm_estado', $estado);
        $this->estado->guardar('llm_proveedor', $proveedor);
    }

    private static function nombreDe(string $url): string
    {
        return match (true) {
            str_contains($url, 'googleapis.com') => 'Google AI Studio',
            str_contains($url, 'openrouter.ai') => 'OpenRouter',
            str_contains($url, 'nvidia.com') => 'NVIDIA NIM',
            default => (string) parse_url($url, PHP_URL_HOST),
        };
    }
}
