<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * T-015 · ALERTA y ACK de campo.
     *
     * - En el líder, `alertas` (proyección de AlertaCampoEnviada) gana el estado de envío por el
     *   canal (intentos, próximo intento) y el momento del acuse.
     * - En D, `alertas_campo` guarda lo recibido y el acuse que el equipo marcó con «Recibido».
     */
    public function up(): void
    {
        Schema::table('alertas', function (Blueprint $tabla): void {
            $tabla->unsignedInteger('intentos')->default(0);
            $tabla->string('proximo_intento')->nullable();
            $tabla->string('recibido_en_campo')->nullable();
        });

        Schema::create('alertas_campo', function (Blueprint $tabla): void {
            $tabla->string('uuid')->primary();
            $tabla->string('incidente_uuid');
            $tabla->string('unidad_id');
            $tabla->string('texto');
            $tabla->string('enviada_en');
            $tabla->string('recibida_en');
            $tabla->string('ack_en')->nullable();
            $tabla->unsignedInteger('ack_intentos')->default(0);
            $tabla->string('ack_proximo_intento')->nullable();
            $tabla->string('confirmado_en')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alertas_campo');

        Schema::table('alertas', function (Blueprint $tabla): void {
            $tabla->dropColumn(['intentos', 'proximo_intento', 'recibido_en_campo']);
        });
    }
};
