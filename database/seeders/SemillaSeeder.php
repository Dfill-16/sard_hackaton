<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Recursos y unidades de respuesta de semilla-recursos.md: datos de demostración ficticios,
 * con ubicación aproximada. No usar para operación real.
 */
class SemillaSeeder extends Seeder
{
    /** @var array<int, array{0: string, 1: string, 2: string, 3: string, 4: float, 5: float}> */
    public const RECURSOS = [
        ['R-01', 'Albergue', 'Coliseo Mayor', 'La Leonora', 5.0612, -75.4958],
        ['R-02', 'Albergue', 'Coliseo Menor', 'La Leonora', 5.0606, -75.4969],
        ['R-03', 'Albergue', 'CIC Aranjuez', 'San Cancio', 5.0565, -75.4890],
        ['R-04', 'Albergue', 'Coliseo Universidad de Caldas', 'Palogrande', 5.0558, -75.4930],
        ['R-05', 'Albergue', 'Salón Comunal La Sultana', 'Milán', 5.0480, -75.4820],
        ['R-06', 'Acopio', 'Centro de Acopio Cruz Roja de Caldas', 'Delicias', 5.0720, -75.5090],
        ['R-07', 'Acopio', 'Acopio Comunitario de Chipre', 'Chipre', 5.0745, -75.5230],
        ['R-08', 'Acopio', 'Acopio La Enea', 'La Enea', 5.0390, -75.4660],
        ['R-09', 'Acopio', 'Acopio Vecinal La Linda', 'Ciudadela La Linda', 5.0460, -75.4740],
        ['R-10', 'Acopio', 'Acopio Parroquial de Villamaría', 'Villamaría', 5.0450, -75.5150],
        ['R-11', 'Sangre', 'Canchas Auxiliares Palogrande', 'Palogrande', 5.0570, -75.4915],
        ['R-12', 'Sangre', 'Hemocentro', 'Versalles (ficticio)', 5.0635, -75.4990],
    ];

    /** @var array<int, array{0: string, 1: string, 2: string, 3: string, 4: float, 5: float, 6: string}> */
    public const UNIDADES = [
        ['U-01', 'ambulancia', 'Ambulancia Alfa', 'Centro', 5.0688, -75.5174, 'disponible'],
        ['U-02', 'ambulancia', 'Ambulancia Bravo', 'Palogrande', 5.0560, -75.4925, 'disponible'],
        ['U-03', 'ambulancia', 'Ambulancia Charlie', 'La Enea', 5.0395, -75.4670, 'ocupada'],
        ['U-04', 'ambulancia', 'Ambulancia Delta', 'Villamaría', 5.0455, -75.5160, 'disponible'],
        ['U-05', 'bomberos', 'Bomberos Estación 1', 'Centro', 5.0695, -75.5160, 'disponible'],
        ['U-06', 'bomberos', 'Bomberos Estación 2', 'Milán', 5.0485, -75.4830, 'disponible'],
        ['U-07', 'bomberos', 'Bomberos Villamaría', 'Villamaría', 5.0440, -75.5140, 'disponible'],
    ];

    public function run(): void
    {
        DB::table('recursos')->upsert(array_map(fn (array $r): array => [
            'id' => $r[0], 'tipo' => $r[1], 'nombre' => $r[2], 'zona' => $r[3], 'lat' => $r[4], 'lon' => $r[5],
        ], self::RECURSOS), ['id']);

        DB::table('unidades')->upsert(array_map(fn (array $u): array => [
            'id' => $u[0], 'tipo' => $u[1], 'nombre' => $u[2], 'base' => $u[3], 'lat' => $u[4], 'lon' => $u[5], 'estado_inicial' => $u[6],
        ], self::UNIDADES), ['id']);
    }
}
