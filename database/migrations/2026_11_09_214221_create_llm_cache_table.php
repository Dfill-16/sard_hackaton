<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * LLM opcional (T-016).
     *
     * - `llm_cache`: una sola llamada por reporte (A2) o por incidente+versión (A6), aunque falle.
     * - `triajes`: la prioridad de reglas se conserva; la efectiva es el máximo entre reglas y la
     *   elevación sugerida por el LLM, con su origen («reglas» o «IA») y explicación.
     */
    public function up(): void
    {
        Schema::create('llm_cache', function (Blueprint $tabla): void {
            $tabla->string('clave')->primary();
            $tabla->string('proveedor')->nullable();
            $tabla->text('respuesta')->nullable();
            $tabla->string('creado_en');
        });

        Schema::table('triajes', function (Blueprint $tabla): void {
            $tabla->string('prioridad_reglas', 2)->nullable();
            $tabla->string('origen')->default('reglas');
            $tabla->string('elevacion_a', 2)->nullable();
            $tabla->text('elevacion_explicacion')->nullable();
            $tabla->string('elevacion_proveedor')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('triajes', function (Blueprint $tabla): void {
            $tabla->dropColumn(['prioridad_reglas', 'origen', 'elevacion_a', 'elevacion_explicacion', 'elevacion_proveedor']);
        });

        Schema::dropIfExists('llm_cache');
    }
};
