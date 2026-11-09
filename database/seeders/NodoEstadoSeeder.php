<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class NodoEstadoSeeder extends Seeder
{
    public function run(): void
    {
        $liderInicial = config('sard.lider_inicial', 'B');

        DB::table('nodo_estado')->insertOrIgnore([
            ['clave' => 'lider', 'valor' => $liderInicial],
            ['clave' => 'epoca', 'valor' => '1'],
            ['clave' => 'rol', 'valor' => config('sard.nodo_role', 'A') === $liderInicial ? 'lider' : 'seguidor'],
            ['clave' => 'ultimo_latido', 'valor' => null],
        ]);
    }
}
