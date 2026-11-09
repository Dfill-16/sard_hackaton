<?php

namespace App\Protocol;

/**
 * Reporte de emergencia en su forma estructurada (payload v1).
 *
 * DTO de conveniencia para construir reportes con tipos; PayloadReporte::encode()
 * también acepta directamente el arreglo canónico que devuelve decode().
 *
 * @phpstan-type ReporteArray array{
 *     version: int,
 *     uuid: string,
 *     device_id: int,
 *     lat: float|null,
 *     lon: float|null,
 *     tipo: int,
 *     banderas: int,
 *     personas: int,
 *     texto: string,
 * }
 */
final class Reporte
{
    /**
     * @param  int  $version  versión del formato; solo 1
     * @param  string  $uuid  UUIDv7 canónico en minúsculas
     * @param  int  $deviceId  identificador anónimo de 4 bytes (0..0xFFFFFFFF)
     * @param  float|null  $lat  grados decimales; null = sin ubicación
     * @param  float|null  $lon  grados decimales; null = sin ubicación
     * @param  int  $tipo  0..7 según la tabla del contrato
     * @param  int  $banderas  bits de catalogo-banderas.md (0..0xFFFF)
     * @param  int  $personas  0..255; 0 = desconocido
     * @param  string  $texto  UTF-8, ≤140 bytes
     */
    public function __construct(
        public readonly string $uuid,
        public readonly int $deviceId,
        public readonly ?float $lat,
        public readonly ?float $lon,
        public readonly int $tipo,
        public readonly int $banderas,
        public readonly int $personas,
        public readonly string $texto,
        public readonly int $version = 1,
    ) {}

    /**
     * Construye el DTO desde el arreglo canónico que produce PayloadReporte::decode().
     *
     * @param  ReporteArray  $reporte
     */
    public static function desdeArray(array $reporte): self
    {
        return new self(
            uuid: $reporte['uuid'],
            deviceId: $reporte['device_id'],
            lat: $reporte['lat'],
            lon: $reporte['lon'],
            tipo: $reporte['tipo'],
            banderas: $reporte['banderas'],
            personas: $reporte['personas'],
            texto: $reporte['texto'],
            version: $reporte['version'] ?? 1,
        );
    }

    /**
     * Arreglo canónico (claves snake_case) para encode() y para comparaciones.
     *
     * @return ReporteArray
     */
    public function aArray(): array
    {
        return [
            'version' => $this->version,
            'uuid' => $this->uuid,
            'device_id' => $this->deviceId,
            'lat' => $this->lat,
            'lon' => $this->lon,
            'tipo' => $this->tipo,
            'banderas' => $this->banderas,
            'personas' => $this->personas,
            'texto' => $this->texto,
        ];
    }
}
