<?php

namespace App\Sard\Proyeccion;

/**
 * Registro de manejadores de proyección por tipo de evento.
 *
 * Los manejadores se invocan dentro de la misma transacción que el evento
 * que los dispara, tanto en `append` (evento local) como en `ingerir`
 * (evento remoto replicado).
 */
final class Proyector
{
    /** @var array<string, callable> */
    private array $manejadores = [];

    /**
     * Registra un manejador para un tipo de evento.
     */
    public function registrar(string $tipo, callable $manejador): void
    {
        $this->manejadores[$tipo] = $manejador;
    }

    /**
     * Aplica un evento a su manejador correspondiente, si existe uno.
     *
     * @param  array<string, mixed>  $evento
     */
    public function aplicar(array $evento): void
    {
        $tipo = $evento['tipo'] ?? null;
        if (! is_string($tipo) || ! isset($this->manejadores[$tipo])) {
            return;
        }

        ($this->manejadores[$tipo])($evento);
    }
}
