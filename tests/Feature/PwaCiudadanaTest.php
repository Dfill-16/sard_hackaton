<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * PWA ciudadana (T-005): la vista se sirve solo desde el Nodo A con el catálogo de tipos y banderas.
 */
final class PwaCiudadanaTest extends TestCase
{
    public function test_pwa_se_sirve_en_el_nodo_a_con_tipos_banderas_y_manifest(): void
    {
        $this->withoutVite();

        $respuesta = $this->get('/pwa')->assertOk();

        $respuesta->assertSee('¿Qué está pasando?')
            ->assertSee('Derrumbe o colapso')
            ->assertSee('Otra emergencia')
            ->assertSee('Persona atrapada')
            ->assertSee('Personas afectadas')
            ->assertSee('Mis reportes')
            ->assertSee('Simular sin red')
            ->assertSee('/manifest.webmanifest', false)
            ->assertSee('ventanaRepetidoMin', false)
            ->assertDontSee('teléfono de contacto');
    }

    public function test_accesibilidad_bandera_vulnerable_explicita_dictado_y_voz(): void
    {
        $this->withoutVite();

        $this->get('/pwa')->assertOk()
            ->assertSee('Persona vulnerable (discapacidad, adulto mayor, niño)')
            ->assertSee('alternarDictado()', false)
            ->assertSee('aria-describedby="ayuda-nota"', false)
            ->assertSee('Leer avisos en voz alta')
            ->assertSee('class="sr-only" role="status" aria-live="polite"', false);
    }

    public function test_al_guardar_se_muestra_una_confirmacion_accesible_que_no_cancela_el_reporte(): void
    {
        $this->withoutVite();

        $this->get('/pwa')->assertOk()
            ->assertSee('x-ref="dialogoConfirmacion"', false)
            ->assertSee('aria-labelledby="titulo-confirmacion" aria-describedby="texto-confirmacion"', false)
            ->assertSee('Reporte guardado')
            ->assertSee('Si puedes llamar, marca 123.')
            ->assertSee('Entendido');
    }

    public function test_banderas_criticas_aparecen_primero(): void
    {
        $this->withoutVite();

        $html = $this->get('/pwa')->getContent();

        $this->assertLessThan(strpos($html, 'Herido/urgencia médica'), strpos($html, 'Explosión'));
        $this->assertLessThan(strpos($html, 'Fuga de gas'), strpos($html, 'Dificultad respiratoria'));
    }

    public function test_pwa_no_existe_fuera_del_nodo_a(): void
    {
        config(['sard.nodo_role' => 'B']);

        $this->get('/pwa')->assertNotFound();
    }

    public function test_manifest_y_service_worker_estan_publicados(): void
    {
        $manifest = json_decode((string) file_get_contents(public_path('manifest.webmanifest')), true);

        $this->assertSame('/pwa', $manifest['start_url']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertFileExists(public_path('sw.js'));
        $this->assertStringContainsString("url.pathname.startsWith('/api/')", (string) file_get_contents(public_path('sw.js')), 'el SW no intercepta la API');
    }
}
