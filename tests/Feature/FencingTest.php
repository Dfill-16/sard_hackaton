<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FencingTest extends TestCase
{
    use RefreshDatabase;

    public function test_epoca_vieja_devuelve_409(): void
    {
        // Época local es 1 (por defecto). Enviar época 0 → debe rechazar.
        $respuesta = $this->post('/sard/eventos', [], ['X-Sard-Epoca' => 0]);

        $respuesta->assertStatus(409);
        $respuesta->assertJson(['error' => 'epoca_vieja']);
    }

    public function test_epoca_igual_pasa(): void
    {
        $respuesta = $this->post('/sard/eventos', [], ['X-Sard-Epoca' => 1]);

        $respuesta->assertStatus(200);
    }

    public function test_epoca_mayor_pasa(): void
    {
        $respuesta = $this->post('/sard/eventos', [], ['X-Sard-Epoca' => 5]);

        $respuesta->assertStatus(200);
    }

    public function test_sin_cabecera_epoca_pasa(): void
    {
        $respuesta = $this->post('/sard/eventos', []);

        $respuesta->assertStatus(200);
    }
}
