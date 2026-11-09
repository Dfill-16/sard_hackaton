<?php

namespace App\Sard\Nodo;

use App\Sard\Support\Tiempo;
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
    /**
     * Rol actual: `lider` o `seguidor`.
     *
     * Si aún no se ha guardado un rol válido, se deriva del líder registrado
     * (o de SARD_LIDER_INICIAL): el nodo que coincide con el líder es `lider`.
     */
    public function rol(): string
    {
        $rol = DB::table('nodo_estado')->where('clave', 'rol')->value('valor');
        if ($rol === 'lider' || $rol === 'seguidor') {
            return $rol;
        }

        return $this->lider() === config('sard.nodo_role') ? 'lider' : 'seguidor';
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

    /** Segundos desde el último latido registrado; null si nunca hubo uno. */
    public function ultimoLatidoHaceS(): ?int
    {
        $ultimo = $this->ultimoLatido();

        return $ultimo === null ? null : (int) floor(max(0, Tiempo::desdeIso($ultimo)->diffInMilliseconds(Tiempo::ahora())) / 1000);
    }

    /** Registra ahora como último latido (recibido en el seguidor, confirmado en el líder). */
    public function marcarLatido(): void
    {
        $this->guardar('ultimo_latido', Tiempo::iso());
    }

    /** Demo de aislamiento (T-013): el nodo deja de enviar y aceptar latidos y réplica. */
    public function aislado(): bool
    {
        return $this->valor('aislado') === '1';
    }

    /**
     * Marca al nodo como líder en una época dada.
     */
    public function asumir(int $epoca): void
    {
        $this->guardarVarias([
            'rol' => 'lider',
            'lider' => (string) config('sard.nodo_role'),
            'epoca' => (string) $epoca,
        ]);
    }

    public function valor(string $clave): ?string
    {
        return DB::table('nodo_estado')->where('clave', $clave)->value('valor');
    }

    public function guardar(string $clave, ?string $valor): void
    {
        DB::table('nodo_estado')->updateOrInsert(['clave' => $clave], ['valor' => $valor]);
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
