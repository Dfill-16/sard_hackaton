<?php

namespace App\Node\A;

use App\Protocol\PayloadReporte;
use App\Sard\Reglas\Banderas;
use App\Sard\Support\Uuid7;

/**
 * Generador de reportes sintéticos para demo y pruebas (T-006, `sard:sembrar`).
 *
 * Reproducible con la semilla (ubicaciones, tipos, banderas y dispositivos); solo los
 * UUIDv7 cambian entre corridas porque llevan la hora. Ubicaciones alrededor de los
 * barrios de semilla-recursos.md (datos de demostración, ficticios).
 */
final class GeneradorSintetico
{
    /** Centros de barrio (semilla-recursos.md, ubicación aproximada). */
    public const BARRIOS = [
        'La Leonora' => [5.0612, -75.4958],
        'San Cancio' => [5.0565, -75.4890],
        'Palogrande' => [5.0558, -75.4930],
        'Milán' => [5.0480, -75.4820],
        'Delicias' => [5.0720, -75.5090],
        'Chipre' => [5.0745, -75.5230],
        'La Enea' => [5.0390, -75.4660],
        'Ciudadela La Linda' => [5.0460, -75.4740],
        'Villamaría' => [5.0450, -75.5150],
        'Versalles' => [5.0635, -75.4990],
    ];

    /** Plantillas por tipo: [banderas (claves), textos]. */
    private const PLANTILLAS = [
        0 => [['otro'], ['ruido fuerte en la zona', 'necesitamos informacion', '']],
        1 => [['derrumbe'], ['se cayo una pared de la casa', 'colapso parcial de un edificio', 'grieta grande en la vivienda']],
        2 => [['incendio'], ['hay humo saliendo de una casa', 'incendio en un local', 'fuego en el segundo piso']],
        3 => [['herido'], ['una persona herida en la calle', 'senora mayor desmayada', 'nino con sangrado']],
        4 => [['deslizamiento'], ['deslizamiento de tierra en la ladera', 'cayo barro sobre la via', 'la montana se esta moviendo']],
        5 => [['inundacion'], ['la quebrada se crecio', 'agua entrando a las casas', 'calle inundada']],
        6 => [['via'], ['via bloqueada por escombros', 'arbol caido sobre la carretera', 'no se puede pasar']],
        7 => [['gas'], ['huele mucho a gas', 'cables caidos en la acera', 'fuga de gas en el edificio']],
    ];

    private const METROS_POR_GRADO = 111_320;

    public function __construct(private int $semilla = 42)
    {
        mt_srand($semilla);
    }

    /**
     * Reportes dispersos alrededor de los barrios.
     *
     * @return array<int, string> payloads binarios v1
     */
    public function dispersos(int $cantidad, ?int $dispositivos = null, float $fraccionSinUbicacion = 0.1): array
    {
        $dispositivos = max(1, $dispositivos ?? $cantidad);
        $ids = $this->dispositivos($dispositivos);
        $centros = array_values(self::BARRIOS);
        $payloads = [];

        for ($i = 0; $i < $cantidad; $i++) {
            $tipo = mt_rand(0, 7);
            [$lat, $lon] = $centros[mt_rand(0, count($centros) - 1)];
            [$lat, $lon] = $this->desplazar($lat, $lon, mt_rand(150, 1500));

            if ($this->azar() < $fraccionSinUbicacion) {
                [$lat, $lon] = [null, null];
            }

            $payloads[] = $this->reporte($ids[$i % $dispositivos], $tipo, $lat, $lon, mt_rand(0, 6));
        }

        return $payloads;
    }

    /**
     * Incidente masivo: ≥ 6 dispositivos distintos a ≤ 200 m entre sí, mismo tipo (inundación).
     *
     * @return array<int, string>
     */
    public function masivo(int $dispositivos = 6): array
    {
        [$lat, $lon] = self::BARRIOS['Palogrande'];
        $payloads = [];

        foreach ($this->dispositivos($dispositivos) as $deviceId) {
            [$latR, $lonR] = $this->desplazar($lat, $lon, mt_rand(0, 90));
            $payloads[] = $this->reporte($deviceId, 5, $latR, $lonR, 2, 'la quebrada se crecio y entra agua');
        }

        return $payloads;
    }

    /**
     * Sitio del escenario de rumor: ladera al occidente de Chipre, a más de 2 km de todo
     * centro de barrio, para que ningún reporte disperso se agrupe con él.
     */
    public const SITIO_RUMOR = [5.0745, -75.5450];

    /**
     * Posible rumor: 30 reportes de un solo dispositivo en el mismo sitio (deslizamiento, P3).
     *
     * @return array<int, string>
     */
    public function rumor(int $cantidad = 30): array
    {
        [$lat, $lon] = self::SITIO_RUMOR;
        $deviceId = $this->dispositivos(1)[0];
        $payloads = [];

        for ($i = 0; $i < $cantidad; $i++) {
            [$latR, $lonR] = $this->desplazar($lat, $lon, mt_rand(0, 100));
            $payloads[] = $this->reporte($deviceId, 4, $latR, $lonR, 0, 'dicen que la montana se va a venir');
        }

        return $payloads;
    }

    /**
     * Escenario de demostración del tablero: un caso por cada cosa que el coordinador debe ver.
     *
     * - P1 corroborado (2 dispositivos): personas atrapadas en Chipre → bomberos, ambulancia pendiente.
     * - P1 médico en Versalles (dificultad respiratoria) → ambulancia.
     * - P1 incendio con explosión en Delicias.
     * - Brecha: heridos en La Enea; su ambulancia está ocupada y la disponible más cercana a > 3 km.
     * - Contradicción: dos dispositivos en Milán dicen 2 y 8 personas.
     * - Sin ubicación: persona atrapada sin GPS.
     *
     * @return array<int, string>
     */
    public function demostracion(): array
    {
        [$a, $b, $c, $d, $e, $f, $g, $h] = $this->dispositivos(8);
        $atrapadas = ['atrapada', 'herido', 'derrumbe'];

        return [
            $this->enBarrio($a, 1, 'Chipre', 40, 3, 'dos personas atrapadas bajo una pared y un herido', $atrapadas),
            $this->enBarrio($b, 1, 'Chipre', 70, 3, 'se cayo la pared, hay gente atrapada', $atrapadas),
            $this->enBarrio($c, 3, 'Versalles', 60, 1, 'senor mayor no puede respirar', ['respiratoria']),
            $this->enBarrio($d, 2, 'Delicias', 80, 5, 'exploto un cilindro y hay fuego en la casa', ['incendio', 'explosion']),
            $this->enBarrio($e, 3, 'La Enea', 50, 2, 'accidente de moto, dos heridos en la via', ['herido']),
            $this->enBarrio($f, 4, 'Milán', 30, 2, 'deslizamiento tapo una casa', ['deslizamiento']),
            $this->enBarrio($g, 4, 'Milán', 60, 8, 'la tierra cayo sobre varias casas', ['deslizamiento']),
            $this->reporte($h, 1, null, null, 2, 'estamos atrapados en el sotano, no se la direccion exacta', ['atrapada']),
        ];
    }

    /** @param  array<int, string>  $claves */
    private function enBarrio(int $deviceId, int $tipo, string $barrio, int $metros, int $personas, string $texto, array $claves): string
    {
        [$lat, $lon] = self::BARRIOS[$barrio];
        [$lat, $lon] = $this->desplazar($lat, $lon, $metros);

        return $this->reporte($deviceId, $tipo, $lat, $lon, $personas, $texto, $claves);
    }

    /**
     * @param  array<int, string>|null  $claves  banderas; por defecto las de la plantilla del tipo
     */
    private function reporte(int $deviceId, int $tipo, ?float $lat, ?float $lon, int $personas, ?string $texto = null, ?array $claves = null): string
    {
        [$clavesPlantilla, $textos] = self::PLANTILLAS[$tipo];
        $claves ??= $clavesPlantilla;
        $banderas = 0;
        foreach ($claves as $clave) {
            $banderas |= 1 << Banderas::bitDeClave($clave);
        }

        return PayloadReporte::encode([
            'version' => PayloadReporte::VERSION,
            'uuid' => Uuid7::generar(),
            'device_id' => $deviceId,
            'lat' => $lat,
            'lon' => $lon,
            'tipo' => $tipo,
            'banderas' => $banderas,
            'personas' => $personas,
            'texto' => $texto ?? $textos[mt_rand(0, count($textos) - 1)],
        ]);
    }

    /** @return array<int, int> */
    private function dispositivos(int $cantidad): array
    {
        $ids = [];
        while (count($ids) < $cantidad) {
            $ids[mt_rand(1, 0x7FFFFFFF)] = true;
        }

        return array_keys($ids);
    }

    /** @return array{0: float, 1: float} punto a `metros` del centro en una dirección al azar */
    private function desplazar(float $lat, float $lon, int $metros): array
    {
        $angulo = $this->azar() * 2 * M_PI;
        $dLat = $metros * cos($angulo) / self::METROS_POR_GRADO;
        $dLon = $metros * sin($angulo) / (self::METROS_POR_GRADO * cos(deg2rad($lat)));

        return [round($lat + $dLat, 7), round($lon + $dLon, 7)];
    }

    private function azar(): float
    {
        return mt_rand() / mt_getrandmax();
    }
}
