<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class NodoEstadoSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('nodo_estado')->insertOrIgnore([
            ['clave' => 'lider', 'valor' => config('sard.lider_inicial', 'B')],
            ['clave' => 'epoca', 'valor' => '1'],
            ['clave' => 'rol', 'valor' => config('sard.nodo_role', 'A')],
            ['clave' => 'ultimo_latido', 'valor' => null],
        ]);
    }
}
