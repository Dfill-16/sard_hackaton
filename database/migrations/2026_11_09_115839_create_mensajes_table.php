<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tablas del proceso CANAL (T-006): mensajes en tránsito y parámetros en vivo.
     */
    public function up(): void
    {
        Schema::create('mensajes', function (Blueprint $tabla): void {
            $tabla->string('id')->primary();
            $tabla->unsignedInteger('seq')->unique();
            $tabla->string('origen');
            $tabla->string('destino');
            $tabla->string('ruta');
            $tabla->string('content_type');
            $tabla->binary('cuerpo');
            $tabla->unsignedInteger('bytes');
            $tabla->string('estado');
            $tabla->string('deliver_at');
            $tabla->boolean('reordenado')->default(false);
            $tabla->string('creado_en');

            $tabla->index(['estado', 'deliver_at'], 'mensajes_estado_deliver_at_index');
        });

        Schema::create('canal_parametros', function (Blueprint $tabla): void {
            $tabla->string('clave')->primary();
            $tabla->string('valor')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('canal_parametros');
        Schema::dropIfExists('mensajes');
    }
};
