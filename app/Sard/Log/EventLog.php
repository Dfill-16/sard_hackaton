<?php

namespace App\Sard\Log;

use App\Sard\Nodo\EstadoNodo;
use App\Sard\Proyeccion\Proyector;
use App\Sard\Support\Tiempo;
use App\Sard\Support\Uuid7;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

/**
 * Log append-only de eventos con cadena de hash.
 *
 * Cada evento está encadenado criptográficamente al anterior del mismo nodo.
 * `append` escribe eventos del nodo local; `ingerir` replica eventos remotos
 * validando esquema y hash antes de insertar.
 */
final class EventLog
{
    private const HASH_GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    private const NODOS_VALIDOS = ['A', 'B', 'C', 'CANAL', 'D'];

    private Proyector $proyector;

    private EstadoNodo $estado;

    /** Nodo fijado al construir (pruebas); si es null se lee NODE_ROLE al escribir cada evento. */
    private ?string $nodoFijo;

    public function __construct(Proyector $proyector, EstadoNodo $estado, ?string $nodo = null)
    {
        $this->proyector = $proyector;
        $this->estado = $estado;
        $this->nodoFijo = $nodo;
    }

    /** Nodo de origen de los eventos locales. */
    public function nodo(): string
    {
        return $this->nodoFijo ?? (string) config('sard.nodo_role', 'A');
    }

    /**
     * Añade un evento del nodo local.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed> evento insertado
     */
    public function append(string $tipo, array $payload, ?string $refUuid = null): array
    {
        // seq y hash_prev se leen dentro de la transacción (BEGIN IMMEDIATE en SQLite): así dos
        // procesos del mismo nodo (servidor web y worker de cola) nunca toman el mismo seq.
        return DB::transaction(fn (): array => $this->appendBloqueado($tipo, $payload, $refUuid));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function appendBloqueado(string $tipo, array $payload, ?string $refUuid): array
    {
        $nodo = $this->nodo();
        $uuid = Uuid7::generar();
        $creadoEn = $this->fechaUtcAhora();
        $epoca = $this->estado->epoca();

        $seq = $this->ultimoSeq($nodo) + 1;
        $hashPrev = $this->ultimoHash($nodo) ?? self::HASH_GENESIS;

        $contenido = [
            'uuid' => $uuid,
            'nodo_origen' => $nodo,
            'seq' => $seq,
            'epoca' => $epoca,
            'tipo' => $tipo,
            'ref_uuid' => $refUuid,
            'creado_en' => $creadoEn,
            'payload' => $payload,
        ];
        $hash = $this->hash($hashPrev, $contenido);

        $fila = [
            'uuid' => $uuid,
            'nodo_origen' => $nodo,
            'seq' => $seq,
            'epoca' => $epoca,
            'tipo' => $tipo,
            'ref_uuid' => $refUuid,
            'creado_en' => $creadoEn,
            'payload' => json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
            'hash_prev' => $hashPrev,
            'hash' => $hash,
        ];

        DB::table('eventos')->insert($fila);
        $this->proyector->aplicar($contenido);

        return $this->eventoArray($fila);
    }

    /**
     * Ingiere un evento remoto. Valida esquema y hash antes de insertar.
     * Devuelve false si el evento ya existía (duplicado).
     *
     * @param  array<string, mixed>  $evento
     *
     * @throws RuntimeException si el hash no cuadra con el contenido canónico
     */
    public function ingerir(array $evento): bool
    {
        $this->validarEsquema($evento);

        // El payload viaja como objeto JSON o como el texto guardado en `eventos`; el hash
        // canónico se calcula siempre sobre el arreglo, igual que en append().
        $payload = is_string($evento['payload']) ? json_decode($evento['payload'], true) : $evento['payload'];
        if (! is_array($payload)) {
            throw new InvalidArgumentException('Campo payload inválido: no es un objeto JSON.');
        }

        $contenido = [
            'uuid' => $evento['uuid'],
            'nodo_origen' => $evento['nodo_origen'],
            'seq' => $evento['seq'],
            'epoca' => $evento['epoca'],
            'tipo' => $evento['tipo'],
            'ref_uuid' => $evento['ref_uuid'] ?? null,
            'creado_en' => $evento['creado_en'],
            'payload' => $payload,
        ];

        $hashCalculado = $this->hash($evento['hash_prev'], $contenido);
        if ($hashCalculado !== $evento['hash']) {
            Log::error('Evento remoto rechazado: hash inválido', [
                'uuid' => $evento['uuid'],
                'nodo_origen' => $evento['nodo_origen'],
                'seq' => $evento['seq'],
            ]);
            throw new RuntimeException('Hash inválido para el evento '.$evento['uuid']);
        }

        return DB::transaction(function () use ($evento, $contenido, $payload): bool {
            $insertado = DB::table('eventos')->insertOrIgnore([
                'uuid' => $evento['uuid'],
                'nodo_origen' => $evento['nodo_origen'],
                'seq' => $evento['seq'],
                'epoca' => $evento['epoca'],
                'tipo' => $evento['tipo'],
                'ref_uuid' => $contenido['ref_uuid'],
                'creado_en' => $evento['creado_en'],
                'payload' => json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
                'hash_prev' => $evento['hash_prev'],
                'hash' => $evento['hash'],
            ]);

            if (! $insertado) {
                return false;
            }

            $this->proyector->aplicar($contenido);

            return true;
        });
    }

    /**
     * Último seq por nodo de origen (punto de sincronía para la réplica y la unión).
     *
     * @return array<string, int>
     */
    public function seqMaxPorOrigen(): array
    {
        return DB::table('eventos')
            ->selectRaw('nodo_origen, MAX(seq) AS seq_max')
            ->groupBy('nodo_origen')
            ->pluck('seq_max', 'nodo_origen')
            ->map(fn ($seq): int => (int) $seq)
            ->all();
    }

    /**
     * Devuelve eventos a partir de un punto de sincronía por origen.
     *
     * @param  array<string, int>  $seqPorOrigen  último seq conocido por nodo_origen
     * @return array<int, array<string, mixed>>
     */
    public function desde(array $seqPorOrigen, int $limite = 500): array
    {
        $consulta = DB::table('eventos')->where(function ($filtro) use ($seqPorOrigen) {
            // Orígenes que el solicitante aún no conoce: se envían completos.
            $filtro->whereNotIn('nodo_origen', array_keys($seqPorOrigen));

            foreach ($seqPorOrigen as $nodo => $seq) {
                $filtro->orWhere(function ($q) use ($nodo, $seq) {
                    $q->where('nodo_origen', $nodo)->where('seq', '>', (int) $seq);
                });
            }
        });

        // Contrato api.md: orden (nodo_origen, seq), así cada cadena llega completa y en orden.
        $filas = $consulta
            ->orderBy('nodo_origen')
            ->orderBy('seq')
            ->limit($limite)
            ->get();

        return $filas->map(function ($fila): array {
            $evento = $this->eventoArray((array) $fila);
            $evento['payload'] = json_decode($evento['payload'], true);

            return $evento;
        })->all();
    }

    /**
     * Verifica la integridad de la cadena de hash de un nodo.
     *
     * @return array{ok: bool, roto_en: int|null}
     */
    public function verificarCadena(string $nodoOrigen): array
    {
        $filas = DB::table('eventos')
            ->where('nodo_origen', $nodoOrigen)
            ->orderBy('seq')
            ->get();

        $hashPrevEsperado = self::HASH_GENESIS;

        foreach ($filas as $fila) {
            $contenido = [
                'uuid' => $fila->uuid,
                'nodo_origen' => $fila->nodo_origen,
                'seq' => $fila->seq,
                'epoca' => $fila->epoca,
                'tipo' => $fila->tipo,
                'ref_uuid' => $fila->ref_uuid,
                'creado_en' => $fila->creado_en,
                'payload' => json_decode($fila->payload, true),
            ];

            if ($fila->hash_prev !== $hashPrevEsperado) {
                return ['ok' => false, 'roto_en' => (int) $fila->seq];
            }

            $hashCalculado = $this->hash($fila->hash_prev, $contenido);
            if ($hashCalculado !== $fila->hash) {
                return ['ok' => false, 'roto_en' => (int) $fila->seq];
            }

            $hashPrevEsperado = $fila->hash;
        }

        return ['ok' => true, 'roto_en' => null];
    }

    /** Hash canónico: sha256(hash_prev . "|" . json_canonico). */
    public function hash(string $hashPrev, array $contenido): string
    {
        $canonico = $this->jsonCanonico([
            'uuid' => $contenido['uuid'],
            'nodo_origen' => $contenido['nodo_origen'],
            'seq' => $contenido['seq'],
            'epoca' => $contenido['epoca'],
            'tipo' => $contenido['tipo'],
            'ref_uuid' => $contenido['ref_uuid'],
            'creado_en' => $contenido['creado_en'],
            'payload' => $contenido['payload'],
        ]);

        return hash('sha256', $hashPrev.'|'.$canonico);
    }

    /** @param  array<string, mixed>  $contenido */
    private function jsonCanonico(array $contenido): string
    {
        return json_encode(
            $this->ordenarRecursivamente($contenido),
            \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES
        );
    }

    /** @param  array<string, mixed>  $arreglo */
    private function ordenarRecursivamente(array $arreglo): array
    {
        $resultado = [];
        ksort($arreglo);
        foreach ($arreglo as $clave => $valor) {
            if (is_array($valor)) {
                $resultado[$clave] = $this->ordenarRecursivamente($valor);
            } else {
                $resultado[$clave] = $valor;
            }
        }

        return $resultado;
    }

    private function ultimoSeq(string $nodo): int
    {
        return (int) (DB::table('eventos')
            ->where('nodo_origen', $nodo)
            ->max('seq') ?? 0);
    }

    private function ultimoHash(string $nodo): ?string
    {
        return DB::table('eventos')
            ->where('nodo_origen', $nodo)
            ->orderByDesc('seq')
            ->value('hash');
    }

    private function fechaUtcAhora(): string
    {
        return Tiempo::iso();
    }

    /** @param  array<string, mixed>  $fila */
    private function eventoArray(array $fila): array
    {
        return [
            'uuid' => $fila['uuid'],
            'nodo_origen' => $fila['nodo_origen'],
            'seq' => $fila['seq'],
            'epoca' => $fila['epoca'],
            'tipo' => $fila['tipo'],
            'ref_uuid' => $fila['ref_uuid'],
            'creado_en' => $fila['creado_en'],
            'payload' => $fila['payload'],
            'hash_prev' => $fila['hash_prev'],
            'hash' => $fila['hash'],
        ];
    }

    private function validarEsquema(array $evento): void
    {
        $requeridos = ['uuid', 'nodo_origen', 'seq', 'epoca', 'tipo', 'creado_en', 'payload', 'hash_prev', 'hash'];
        foreach ($requeridos as $campo) {
            if (! array_key_exists($campo, $evento)) {
                throw new InvalidArgumentException('Evento incompleto: falta el campo '.$campo);
            }
        }

        if (! is_string($evento['uuid']) || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $evento['uuid'])) {
            throw new InvalidArgumentException('Campo uuid inválido: '.$evento['uuid']);
        }

        if (! is_string($evento['nodo_origen']) || ! in_array($evento['nodo_origen'], self::NODOS_VALIDOS, true)) {
            throw new InvalidArgumentException('nodo_origen no permitido: '.$evento['nodo_origen']);
        }

        if (! is_int($evento['seq']) || $evento['seq'] < 1) {
            throw new InvalidArgumentException('Campo seq inválido.');
        }

        if (! is_int($evento['epoca']) || $evento['epoca'] < 0) {
            throw new InvalidArgumentException('Campo epoca inválido.');
        }

        if (! is_string($evento['tipo'])) {
            throw new InvalidArgumentException('Campo tipo inválido.');
        }

        if (! is_string($evento['hash_prev']) || ! preg_match('/^[0-9a-f]{64}$/', $evento['hash_prev'])) {
            throw new InvalidArgumentException('Campo hash_prev inválido.');
        }

        if (! is_string($evento['hash']) || ! preg_match('/^[0-9a-f]{64}$/', $evento['hash'])) {
            throw new InvalidArgumentException('Campo hash inválido.');
        }

        if (! is_array($evento['payload']) && ! is_string($evento['payload'])) {
            throw new InvalidArgumentException('Campo payload inválido.');
        }
    }
}
