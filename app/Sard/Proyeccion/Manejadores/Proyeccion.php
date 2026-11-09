<?php

namespace App\Sard\Proyeccion\Manejadores;

use Illuminate\Support\Facades\DB;

/**
 * Utilidades comunes de los manejadores de proyección.
 */
final class Proyeccion
{
    /**
     * Payload del evento como arreglo (llega como arreglo en append/ingerir y como
     * JSON en `sard:reconstruir`).
     *
     * @param  array<string, mixed>  $evento
     * @return array<string, mixed>
     */
    public static function payload(array $evento): array
    {
        $payload = $evento['payload'] ?? [];
        if (is_string($payload)) {
            $payload = json_decode($payload, true) ?? [];
        }

        return $payload;
    }

    /**
     * Inserta la fila o, si ya existe, la reemplaza solo cuando el evento nuevo tiene un
     * UUIDv7 menor (eventos.md: ante duplicados tras la unión gana el más antiguo).
     *
     * @param  array<string, mixed>  $fila  debe incluir `evento_uuid`
     */
    public static function guardarGanaMenor(string $tabla, string $clave, array $fila): void
    {
        $actual = DB::table($tabla)->where($clave, $fila[$clave])->value('evento_uuid');

        if ($actual === null) {
            DB::table($tabla)->insert($fila);

            return;
        }

        if (strcmp($fila['evento_uuid'], $actual) < 0) {
            DB::table($tabla)->where($clave, $fila[$clave])->update($fila);
        }
    }

    /** @param  array<int, mixed>|array<string, mixed>  $valor */
    public static function json(array $valor): string
    {
        return (string) json_encode($valor, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
    }
}
