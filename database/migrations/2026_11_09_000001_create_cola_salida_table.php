<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cola_salida', function (Blueprint $tabla): void {
            $tabla->string('reporte_uuid')->primary();
            $tabla->longText('payload');
            $tabla->unsignedInteger('bytes');
            $tabla->unsignedInteger('device_id');
            $tabla->string('recibido_en');
            $tabla->unsignedInteger('puntaje_base')->nullable();
            $tabla->unsignedInteger('intentos')->default(0);
            $tabla->string('proximo_intento');
            $tabla->string('enviado_a')->nullable();
            $tabla->string('epoca_envio')->nullable();
            $tabla->string('confirmado_en')->nullable();
            $tabla->string('confirmado_por')->nullable();

            $tabla->index('proximo_intento', 'cola_salida_proximo_intento_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cola_salida');
    }
};
