<?php

namespace Tests\Feature;

use App\Sard\Reglas\Banderas;
use App\Sard\Reglas\Normalizador;
use App\Sard\Reglas\Puntaje;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Reglas de catalogo-banderas.md: normalización, diccionario, puntaje y bandas.
 */
final class PuntajeReglasTest extends TestCase
{
    private function mascara(string ...$claves): int
    {
        $mascara = 0;
        foreach ($claves as $clave) {
            $mascara |= 1 << Banderas::bitDeClave($clave);
        }

        return $mascara;
    }

    public function test_normalizador_quita_tildes_mayusculas_y_signos(): void
    {
        $this->assertSame('explosion en la via hay humo', Normalizador::texto('¡EXPLOSIÓN en la vía!! Hay humo...'));
        $this->assertSame('', Normalizador::texto('  ¿?  '));
    }

    public function test_banderas_marcadas_suman_su_peso(): void
    {
        $resultado = Puntaje::calcular($this->mascara('incendio', 'via'), null, '');

        $this->assertSame(5, $resultado['puntaje']);
        $this->assertSame([
            ['origen' => 'bandera', 'clave' => 'incendio', 'peso' => 4],
            ['origen' => 'bandera', 'clave' => 'via', 'peso' => 1],
        ], $resultado['senales']);
        $this->assertSame([], $resultado['criticas']);
    }

    public function test_palabra_no_suma_si_la_bandera_ya_esta_marcada(): void
    {
        $resultado = Puntaje::calcular($this->mascara('incendio'), null, 'hay fuego y mucho humo');

        $this->assertSame(4, $resultado['puntaje']);
        $this->assertCount(1, $resultado['senales']);
    }

    public function test_dos_palabras_de_la_misma_bandera_suman_una_sola_vez(): void
    {
        $resultado = Puntaje::calcular(0, null, 'herido y sangrando');

        $this->assertSame(4, $resultado['puntaje']);
        $this->assertSame([['origen' => 'palabra', 'clave' => 'herido', 'peso' => 4]], $resultado['senales']);
    }

    public function test_texto_estamos_atrapados_infiere_bandera_critica(): void
    {
        $resultado = Puntaje::calcular(0, null, 'Estamos ATRAPADOS en el sótano');

        $this->assertSame(5, $resultado['puntaje']);
        $this->assertSame(['atrapada'], $resultado['criticas']);
        $this->assertSame('P1', Puntaje::prioridad($resultado));
    }

    public function test_creci_solo_cuenta_si_menciona_rio_o_quebrada(): void
    {
        $this->assertSame(0, Puntaje::calcular(0, null, 'el precio crecio mucho')['puntaje']);
        $this->assertSame(3, Puntaje::calcular(0, null, 'la quebrada crecio')['puntaje']);
        $this->assertSame(3, Puntaje::calcular(0, null, 'creciente del río')['puntaje']);
    }

    public function test_bomba_es_explosivo_y_es_critica(): void
    {
        $resultado = Puntaje::calcular(0, null, 'dejaron una bomba');

        $this->assertSame(['explosion'], $resultado['criticas']);
        $this->assertSame('P1', Puntaje::prioridad($resultado));
    }

    public function test_prefijo_solo_coincide_al_inicio_de_la_palabra(): void
    {
        $this->assertSame([], Puntaje::banderasPorPalabras('desatrapado no aplica'));
        $this->assertSame(['respiratoria'], Puntaje::banderasPorPalabras('el abuelo no respira'));
        $this->assertSame([], Puntaje::banderasPorPalabras('gasolina'));
    }

    /** @return array<string, array{0: int|null, 1: int}> */
    public static function bonosPersonas(): array
    {
        return [
            'desconocido' => [null, 0],
            'una persona' => [1, 0],
            'dos personas' => [2, 1],
            'cuatro personas' => [4, 3],
            'tope' => [200, 3],
        ];
    }

    #[DataProvider('bonosPersonas')]
    public function test_bono_personas(?int $personas, int $bono): void
    {
        $this->assertSame($bono, Puntaje::bonoPersonas($personas));
    }

    /** @return array<string, array{0: int, 1: string}> */
    public static function bandas(): array
    {
        return [
            'P1 desde 8' => [8, 'P1'],
            'P2 tope' => [7, 'P2'],
            'P2 piso' => [5, 'P2'],
            'P3 tope' => [4, 'P3'],
            'P3 piso' => [2, 'P3'],
            'P4' => [1, 'P4'],
            'P4 cero' => [0, 'P4'],
        ];
    }

    #[DataProvider('bandas')]
    public function test_bandas_de_prioridad_sin_criticas(int $puntaje, string $prioridad): void
    {
        $this->assertSame($prioridad, Puntaje::prioridad(['puntaje' => $puntaje, 'criticas' => []]));
    }

    public function test_regla_critica_gana_sobre_puntaje_bajo(): void
    {
        $resultado = Puntaje::calcular($this->mascara('respiratoria'), null, '');

        $this->assertSame(5, $resultado['puntaje']);
        $this->assertSame('P1', Puntaje::prioridad($resultado));
    }

    public function test_combinacion_con_personas(): void
    {
        $resultado = Puntaje::calcular($this->mascara('derrumbe', 'vulnerable'), 3, 'hay heridos');

        $this->assertSame(4 + 2 + 4 + 2, $resultado['puntaje']);
        $this->assertSame('P1', Puntaje::prioridad($resultado));
        $this->assertSame(['origen' => 'personas', 'clave' => 'personas', 'peso' => 2], end($resultado['senales']));
    }
}
