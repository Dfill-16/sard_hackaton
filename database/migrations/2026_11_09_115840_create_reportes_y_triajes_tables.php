<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Proyecciones del líder (T-007): reportes normalizados por A1 y triajes de A2.
     * Se reconstruyen desde `eventos` con `sard:reconstruir`.
     */
    public function up(): void
    {
        Schema::create('reportes', function (Blueprint $tabla): void {
            $tabla->string('reporte_uuid')->primary();
            $tabla->string('evento_uuid');
            $tabla->string('device_id', 8);
            $tabla->unsignedTinyInteger('tipo');
            $tabla->unsignedInteger('banderas');
            $tabla->unsignedInteger('personas')->nullable();
            $tabla->text('texto');
            $tabla->double('lat')->nullable();
            $tabla->double('lon')->nullable();
            $tabla->boolean('sin_ubicacion');
            $tabla->string('creado_en');
            $tabla->boolean('truncado');
            $tabla->text('campos_desconocidos');
            $tabla->string('recibido_en');

            $tabla->index('creado_en', 'reportes_creado_en_index');
        });

        Schema::create('triajes', function (Blueprint $tabla): void {
            $tabla->string('reporte_uuid')->primary();
            $tabla->string('evento_uuid');
            $tabla->string('prioridad', 2);
            $tabla->unsignedInteger('puntaje');
            $tabla->text('senales');
            $tabla->double('incertidumbre');
            $tabla->text('motivos');
            $tabla->string('modo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('triajes');
        Schema::dropIfExists('reportes');
    }
};
