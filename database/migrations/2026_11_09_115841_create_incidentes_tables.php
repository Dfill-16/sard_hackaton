<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Proyecciones de A3 (T-008): incidentes y su relación con los reportes originales.
     * Un incidente nunca fusiona ni borra originales (ADR-002).
     */
    public function up(): void
    {
        Schema::create('incidentes', function (Blueprint $tabla): void {
            $tabla->string('uuid')->primary();
            $tabla->unsignedTinyInteger('tipo');
            $tabla->double('lat')->nullable();
            $tabla->double('lon')->nullable();
            $tabla->boolean('sin_ubicacion');
            $tabla->unsignedInteger('reportes_n')->default(0);
            $tabla->unsignedInteger('dispositivos_n')->default(0);
            $tabla->boolean('masivo')->default(false);
            $tabla->string('prioridad_base', 2);
            $tabla->double('confianza')->default(0);
            $tabla->text('contradicciones');
            $tabla->string('estado')->default('abierto');
            $tabla->string('creado_en');
            $tabla->string('actualizado_en');
        });

        Schema::create('incidente_reporte', function (Blueprint $tabla): void {
            $tabla->string('reporte_uuid')->primary();
            $tabla->string('incidente_uuid');
            $tabla->string('evento_uuid');

            $tabla->index('incidente_uuid', 'incidente_reporte_incidente_uuid_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incidente_reporte');
        Schema::dropIfExists('incidentes');
    }
};
