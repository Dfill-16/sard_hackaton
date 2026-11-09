<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Proyecciones de A6 y del tablero (T-010): recomendaciones, decisiones humanas y alertas.
     */
    public function up(): void
    {
        Schema::create('recomendaciones', function (Blueprint $tabla): void {
            $tabla->string('uuid')->primary();
            $tabla->string('evento_uuid');
            $tabla->string('incidente_uuid');
            $tabla->unsignedInteger('version');
            $tabla->string('unidad_id')->nullable();
            $tabla->string('accion');
            $tabla->text('justificacion');
            $tabla->text('pendientes');
            $tabla->double('distancia_m')->nullable();
            $tabla->string('modo');
            $tabla->string('prioridad_al_proponer', 2);
            $tabla->boolean('masivo_al_proponer');
            $tabla->string('creado_en');

            $tabla->unique(['incidente_uuid', 'version'], 'recomendaciones_incidente_version_unique');
        });

        Schema::create('decisiones', function (Blueprint $tabla): void {
            $tabla->string('uuid')->primary();
            $tabla->string('evento_uuid');
            $tabla->string('recomendacion_uuid');
            $tabla->string('incidente_uuid');
            $tabla->string('accion');
            $tabla->text('motivo')->nullable();
            $tabla->string('operador');
            $tabla->string('nodo');
            $tabla->string('creado_en');

            $tabla->index('recomendacion_uuid', 'decisiones_recomendacion_index');
            $tabla->index('incidente_uuid', 'decisiones_incidente_index');
        });

        Schema::create('alertas', function (Blueprint $tabla): void {
            $tabla->string('uuid')->primary();
            $tabla->string('evento_uuid');
            $tabla->string('decision_uuid');
            $tabla->string('incidente_uuid');
            $tabla->string('unidad_id');
            $tabla->string('texto');
            $tabla->string('enviada_en');
            $tabla->string('ack_en')->nullable();

            $tabla->index('incidente_uuid', 'alertas_incidente_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alertas');
        Schema::dropIfExists('decisiones');
        Schema::dropIfExists('recomendaciones');
    }
};
