<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Canal multisalto (T-018): cada mensaje recorre `saltos` tramos; cada tramo decide su
     * propia pérdida, latencia y desorden. `perdido_en_salto` alimenta las estadísticas por salto.
     */
    public function up(): void
    {
        Schema::table('mensajes', function (Blueprint $tabla): void {
            $tabla->unsignedTinyInteger('saltos')->default(1);
            $tabla->unsignedTinyInteger('salto_actual')->default(1);
            $tabla->unsignedTinyInteger('perdido_en_salto')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('mensajes', function (Blueprint $tabla): void {
            $tabla->dropColumn(['saltos', 'salto_actual', 'perdido_en_salto']);
        });
    }
};
