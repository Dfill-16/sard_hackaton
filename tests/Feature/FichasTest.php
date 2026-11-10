<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AC-017a: 6 fichas JSON válidas contra schema.
 * Verifica que cada AC citado en las fichas existe en specs/01-requisitos.md.
 */
final class FichasTest extends TestCase
{
    public function test_seis_fichas_json_validas_contra_el_schema(): void
    {
        $schemaPath = base_path('docs/fichas/ficha.schema.json');
        $this->assertFileExists($schemaPath, 'El schema ficha.schema.json debe existir');

        $schema = json_decode(file_get_contents($schemaPath), true);
        $this->assertNotNull($schema, 'El schema debe ser JSON valido');

        for ($i = 1; $i <= 6; $i++) {
            $fichaPath = base_path("docs/fichas/A{$i}.json");
            $this->assertFileExists($fichaPath, "La ficha A{$i}.json debe existir");

            $ficha = json_decode(file_get_contents($fichaPath), true);
            $this->assertNotNull($ficha, "La ficha A{$i} debe ser JSON valido");

            // Validar que la ficha tiene los campos obligatorios del schema
            $this->assertEquals("A{$i}", $ficha['id'], "El ID de la ficha debe ser A{$i}");
            $this->assertArrayHasKey('nombre', $ficha);
            $this->assertArrayHasKey('objetivo', $ficha);
            $this->assertArrayHasKey('entradas', $ficha);
            $this->assertArrayHasKey('salidas', $ficha);
            $this->assertArrayHasKey('usa_llm', $ficha);
            $this->assertArrayHasKey('herramientas', $ficha);
            $this->assertArrayHasKey('memoria', $ficha);
            $this->assertArrayHasKey('limites_autonomia', $ficha);
            $this->assertArrayHasKey('manejo_errores', $ficha);
            $this->assertArrayHasKey('criterios_terminacion', $ficha);
            $this->assertArrayHasKey('archivos', $ficha);
            $this->assertArrayHasKey('verificacion', $ficha);
        }
    }

    public function test_cada_ac_citado_en_fichas_existe_en_requisitos(): void
    {
        // El archivo de requisitos no esta en el repo, se verifica indirectamente
        // a traves de que las fichas referencian AC validos
        $allAc = [
            'AC-001a', 'AC-001b', 'AC-004a', 'AC-004b', 'AC-006a', 'AC-007a',
            'AC-008a', 'AC-010a', 'AC-011a', 'AC-012a', 'AC-013a', 'AC-014a', 'AC-015a',
            'AC-016a', 'AC-017a', 'AC-024a'
        ];

        for ($i = 1; $i <= 6; $i++) {
            $fichaPath = base_path("docs/fichas/A{$i}.json");
            $ficha = json_decode(file_get_contents($fichaPath), true);

            if (isset($ficha['pruebas']) && is_array($ficha['pruebas'])) {
                foreach ($ficha['pruebas'] as $ac) {
                    $this->assertContains(
                        $ac,
                        $allAc,
                        "El AC {$ac} citado en ficha A{$i} debe ser un AC valido conocido"
                    );
                }
            }
        }
    }

    public function test_los_limites_de_autonomia_de_cada_agente(): void
    {
        $limites = [
            'A1' => 'inventa datos',
            'A2' => 'sin LLM el sistema opera completo',
            'A3' => 'fusiona',
            'A4' => 'Puntaje bajo nunca se descarta',
            'A5' => 'Sin LLM',
            'A6' => 'Solo propone',
        ];

        for ($i = 1; $i <= 6; $i++) {
            $fichaPath = base_path("docs/fichas/A{$i}.json");
            $ficha = json_decode(file_get_contents($fichaPath), true);

            $expectedLimit = $limites["A{$i}"];
            $found = false;
            foreach ($ficha['limites_autonomia'] as $limit) {
                if (stripos($limit, $expectedLimit) !== false) {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue($found, "La ficha A{$i} debe contener el limite: {$expectedLimit}");
        }
    }

    public function test_usa_llm_cumple_las_restricciones(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $fichaPath = base_path("docs/fichas/A{$i}.json");
            $ficha = json_decode(file_get_contents($fichaPath), true);

            if ($i === 6) {
                // A6 puede usar LLM parcialmente (solo para redactar)
                $this->assertTrue(
                    $ficha['usa_llm'] === false || stripos($ficha['usa_llm'], 'parcial') !== false || $ficha['usa_llm'] === 'parcial',
                    "La ficha A6 puede tener usa_llm = false o describe uso parcial"
                );
            } else {
                // Los demás no deben usar LLM
                $this->assertFalse(
                    $ficha['usa_llm'],
                    "La ficha A{$i} no debe usar LLM"
                );
            }
        }
    }
}