<?php

namespace App\Sard\Nodo;

use Illuminate\Support\Facades\DB;

/**
 * Estado del nodo persistido en la tabla `nodo_estado`.
 *
 * Almacena rol, líder actual, época y último latido. Son las únicas claves
 * que se escriben en esta tabla; los latidos no van al log de eventos
 * para no saturar la cadena de hash.
 */
final class EstadoNodo
{
    public function rol(): string
    {
        return (string) (DB::table('nodo_estado')->where('clave', 'rol')->value('valor') ?? 'seguidor');
    }

    public function esLider(): bool
    {
        return $this->rol() === 'lider';
    }

    public function lider(): string
    {
        return (string) (DB::table('nodo_estado')->where('clave', 'lider')->value('valor')
            ?? config('sard.lider_inicial', 'B'));
    }

    public function epoca(): int
    {
        return (int) (DB::table('nodo_estado')->where('clave', 'epoca')->value('valor') ?? 1);
    }

    public function ultimoLatido(): ?string
    {
        return DB::table('nodo_estado')->where('clave', 'ultimo_latido')->value('valor');
    }

    /**
     * Marca al nodo como líder en una época dada.
     */
    public function asumir(int $epoca): void
    {
        $this->guardarVarias([
            'rol' => 'lider',
            'epoca' => (string) $epoca,
        ]);
    }

    /**
     * Indica que el nodo sigue a un líder en una época dada.
     */
    public function seguir(string $lider, int $epoca): void
    {
        $this->guardarVarias([
            'rol' => 'seguidor',
            'lider' => $lider,
            'epoca' => (string) $epoca,
        ]);
    }

    /** @param  array<string, string>  $valores */
    private function guardarVarias(array $valores): void
    {
        $filas = [];
        foreach ($valores as $clave => $valor) {
            $filas[] = ['clave' => $clave, 'valor' => $valor];
        }

        foreach ($filas as $fila) {
            DB::table('nodo_estado')->updateOrInsert(
                ['clave' => $fila['clave']],
                ['valor' => $fila['valor']]
            );
        }
    }
}
