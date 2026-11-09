<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eventos', function (Blueprint $tabla): void {
            $tabla->string('uuid')->primary();
            $tabla->string('nodo_origen');
            $tabla->unsignedInteger('seq');
            $tabla->unsignedInteger('epoca')->default(1);
            $tabla->string('tipo');
            $tabla->string('ref_uuid')->nullable();
            $tabla->string('creado_en');
            $tabla->longText('payload');
            $tabla->string('hash_prev', 64);
            $tabla->string('hash', 64);

            $tabla->unique(['nodo_origen', 'seq'], 'eventos_nodo_seq_unique');
            $tabla->index(['tipo', 'ref_uuid'], 'eventos_tipo_ref_uuid_index');
        });

        Schema::create('nodo_estado', function (Blueprint $tabla): void {
            $tabla->string('clave')->primary();
            $tabla->string('valor')->nullable();
        });

        // Triggers append-only: ninguna modificación ni borrado de eventos.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER eventos_before_update BEFORE UPDATE ON eventos
            BEGIN
                SELECT RAISE(ABORT, 'append-only: eventos es solo-lectura tras insert');
            END;
SQL
        );
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER eventos_before_delete BEFORE DELETE ON eventos
            BEGIN
                SELECT RAISE(ABORT, 'append-only: eventos es solo-lectura tras insert');
            END;
SQL
        );
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS eventos_before_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS eventos_before_update');
        Schema::dropIfExists('nodo_estado');
        Schema::dropIfExists('eventos');
    }
};
