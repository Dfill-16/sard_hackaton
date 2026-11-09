<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Datos de demostración de semilla-recursos.md (T-010): recursos y unidades ficticias.
     * `unidades_estado` es una proyección: la unidad pasa a «asignada» con AlertaCampoEnviada.
     */
    public function up(): void
    {
        Schema::create('recursos', function (Blueprint $tabla): void {
            $tabla->string('id')->primary();
            $tabla->string('tipo');
            $tabla->string('nombre');
            $tabla->string('zona');
            $tabla->double('lat');
            $tabla->double('lon');
        });

        Schema::create('unidades', function (Blueprint $tabla): void {
            $tabla->string('id')->primary();
            $tabla->string('tipo');
            $tabla->string('nombre');
            $tabla->string('base');
            $tabla->double('lat');
            $tabla->double('lon');
            $tabla->string('estado_inicial');
        });

        Schema::create('unidades_estado', function (Blueprint $tabla): void {
            $tabla->string('unidad_id')->primary();
            $tabla->string('estado');
            $tabla->string('alerta_uuid')->nullable();
            $tabla->string('incidente_uuid')->nullable();
            $tabla->string('evento_uuid');
            $tabla->string('actualizado_en');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unidades_estado');
        Schema::dropIfExists('unidades');
        Schema::dropIfExists('recursos');
    }
};
