<?php

namespace App\Protocol;

/**
 * Codec del payload binario de reporte v1 (big-endian).
 *
 * Contrato: specs/contratos/payload-reporte.md (brief v2.1 §8 · REQ-001, REQ-022).
 * Espejo exacto en JS: resources/js/protocol/payload.js — los dos codecs deben
 * aceptar y producir los mismos bytes (prueba cruzada con fixture compartido).
 *
 * | Offset | Bytes | Campo      | Regla                                           |
 * |-------:|------:|------------|-------------------------------------------------|
 * | 0      | 1     | versión    | = 1                                             |
 * | 1      | 16    | uuid       | UUIDv7 canónico                                 |
 * | 17     | 4     | device_id  | anónimo, aleatorio por instalación              |
 * | 21     | 4     | lat        | int32 = grados × 1e7; 0x7FFFFFFF = sin ubicación|
 * | 25     | 4     | lon        | ídem                                            |
 * | 29     | 1     | tipo       | 0..7                                            |
 * | 30     | 2     | banderas   | bits de catalogo-banderas.md                    |
 * | 32     | 1     | personas   | 0..255; 0 = desconocido                         |
 * | 33     | 1     | long_texto | 0..140 (bytes UTF-8, no caracteres)             |
 * | 34     | n     | texto      | UTF-8, ≤140 B                                   |
 * | 34+n   | 2     | crc        | CRC-16/CCITT-FALSE sobre bytes 0..33+n          |
 *
 * Total = 36 + n. CRC inválido o versión ≠ 1 → PayloadInvalidoException
 * (el nodo descarta el paquete sin ACK). El ACK de vuelta (0x41 + 16 B uuid)
 * es parte del canal y NO se implementa aquí.
 */
final class PayloadReporte
{
    public const VERSION = 1;

    /** Centinela exacto de lat/lon para "sin ubicación". Ningún otro valor (p. ej. -1) es especial. */
    public const SIN_UBICACION = 0x7FFFFFFF;

    /** Largo con texto vacío: 36 bytes (AC-004a). */
    public const LARGO_MINIMO = 36;

    public const MAXIMO_BYTES_TEXTO = 140;

    /** Grados × 1e7 → int32 (±180° → ±1.8e9, cabe en int32). */
    private const FACTOR_COORDENADA = 10_000_000;

    private const CRC_POLINOMIO = 0x1021;

    private const CRC_INICIO = 0xFFFF;

    private const UUID_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    /**
     * Codifica un reporte al payload binario v1.
     *
     * Acepta el arreglo canónico que devuelve decode() o un objeto Reporte.
     * Lanza PayloadInvalidoException si algún campo viola el contrato.
     *
     * @param  ReporteArray|Reporte  $reporte
     * @return string payload binario de 36+n bytes
     */
    public static function encode(array|Reporte $reporte): string
    {
        if ($reporte instanceof Reporte) {
            $reporte = $reporte->aArray();
        }

        $version = $reporte['version'] ?? self::VERSION;
        if ($version !== self::VERSION) {
            throw new PayloadInvalidoException('Versión no soportada: se esperaba 1, recibida '.var_export($version, true).'.');
        }

        $uuidHex = self::validaUuid($reporte['uuid'] ?? null);
        $deviceId = self::validaEntero($reporte['device_id'] ?? null, 'device_id', 0, 0xFFFFFFFF);
        $lat = self::validaCoordenada($reporte['lat'] ?? null, 'lat', 90.0);
        $lon = self::validaCoordenada($reporte['lon'] ?? null, 'lon', 180.0);
        $tipo = self::validaEntero($reporte['tipo'] ?? null, 'tipo', 0, 7);
        $banderas = self::validaEntero($reporte['banderas'] ?? null, 'banderas', 0, 0xFFFF);
        $personas = self::validaEntero($reporte['personas'] ?? null, 'personas', 0, 255);
        $texto = self::validaTexto($reporte['texto'] ?? null);

        $cuerpo = pack('C', self::VERSION)
            .pack('H*', $uuidHex)
            .pack('N', $deviceId)
            .self::coordenada($lat)
            .self::coordenada($lon)
            .pack('C', $tipo)
            .pack('n', $banderas)
            .pack('C', $personas)
            .pack('C', strlen($texto))
            .$texto;

        return $cuerpo.self::crc16($cuerpo);
    }

    /**
     * Decodifica un payload binario v1 al arreglo canónico.
     *
     * Estricto: rechaza largo menor a 36, versión ≠ 1, CRC inválido,
     * long_texto inconsistente, bytes sobrantes y campos fuera de rango.
     *
     * @return ReporteArray
     */
    public static function decode(string $bytes): array
    {
        $largo = strlen($bytes);
        if ($largo < self::LARGO_MINIMO) {
            throw new PayloadInvalidoException("Payload demasiado corto: {$largo} bytes, mínimo ".self::LARGO_MINIMO.'.');
        }

        $version = ord($bytes[0]);
        if ($version !== self::VERSION) {
            throw new PayloadInvalidoException("Versión no soportada: {$version}; solo v1.");
        }

        $cuerpo = substr($bytes, 0, -2);
        $crcRecibido = unpack('n', substr($bytes, -2))[1];
        if ($crcRecibido !== self::crc16Valor($cuerpo)) {
            throw new PayloadInvalidoException('CRC inválido: paquete descartado.');
        }

        $longTexto = ord($bytes[33]);
        if ($longTexto > self::MAXIMO_BYTES_TEXTO) {
            throw new PayloadInvalidoException('long_texto fuera de rango (máximo '.self::MAXIMO_BYTES_TEXTO."): {$longTexto}.");
        }
        if ($largo !== self::LARGO_MINIMO + $longTexto) {
            throw new PayloadInvalidoException("Largo inconsistente: {$largo} bytes para long_texto {$longTexto} (esperados ".(self::LARGO_MINIMO + $longTexto).').');
        }

        $tipo = ord($bytes[29]);
        if ($tipo > 7) {
            throw new PayloadInvalidoException("tipo fuera de rango 0..7: {$tipo}.");
        }

        $uuidHex = bin2hex(substr($bytes, 1, 16));
        if (! preg_match(self::UUID_REGEX, self::formateaUuid($uuidHex))) {
            throw new PayloadInvalidoException('uuid no es un UUIDv7: '.self::formateaUuid($uuidHex).'.');
        }

        $texto = substr($bytes, 34, $longTexto);
        if (! preg_match('//u', $texto)) {
            throw new PayloadInvalidoException('texto no es UTF-8 válido.');
        }

        return [
            'version' => self::VERSION,
            'uuid' => self::formateaUuid($uuidHex),
            'device_id' => unpack('N', substr($bytes, 17, 4))[1],
            'lat' => self::coordenadaDesdeInt32(substr($bytes, 21, 4), 'lat'),
            'lon' => self::coordenadaDesdeInt32(substr($bytes, 25, 4), 'lon'),
            'tipo' => $tipo,
            'banderas' => unpack('n', substr($bytes, 30, 2))[1],
            'personas' => ord($bytes[32]),
            'texto' => $texto,
        ];
    }

    /**
     * Normaliza y valida el uuid: canónico con guiones (acepta mayúsculas),
     * 32 hex, versión 7 y variante RFC 4122. Devuelve el hex sin guiones.
     */
    private static function validaUuid(mixed $uuid): string
    {
        if (! is_string($uuid)) {
            throw new PayloadInvalidoException('uuid debe ser string, recibido '.get_debug_type($uuid).'.');
        }

        $hex = strtolower(str_replace('-', '', $uuid));
        if (! preg_match(self::UUID_REGEX, self::formateaUuid($hex))) {
            throw new PayloadInvalidoException('uuid no es un UUIDv7 canónico: '.$uuid.'.');
        }

        return $hex;
    }

    /** Empaqueta una coordenada validada: null → centinela; grados → int32 big-endian. */
    private static function coordenada(?float $grados): string
    {
        if ($grados === null) {
            return pack('N', self::SIN_UBICACION);
        }

        $entero = self::escalaCoordenada($grados);

        return pack('N', $entero & 0xFFFFFFFF);
    }

    /** Convierte los 4 bytes big-endian a grados; centinela → null. */
    private static function coordenadaDesdeInt32(string $bytes, string $campo): ?float
    {
        $crudo = unpack('N', $bytes)[1];
        if ($crudo === self::SIN_UBICACION) {
            return null;
        }

        $entero = $crudo >= 0x80000000 ? $crudo - 4_294_967_296 : $crudo;

        // Cast explícito a float: PHP "/" devuelve int cuando la división es exacta.
        return (float) ($entero / self::FACTOR_COORDENADA);
    }

    private static function validaCoordenada(mixed $grados, string $campo, float $limite): ?float
    {
        if ($grados === null) {
            return null;
        }

        if (is_int($grados)) {
            $grados = (float) $grados;
        }

        if (! is_float($grados) || ! is_finite($grados)) {
            throw new PayloadInvalidoException("{$campo} debe ser float o null, recibido ".get_debug_type($grados).'.');
        }

        if (abs($grados) > $limite) {
            throw new PayloadInvalidoException(sprintf('%s fuera de rango (máximo ±%.0f): %f.', $campo, $limite, $grados));
        }

        return $grados;
    }

    private static function validaEntero(mixed $valor, string $campo, int $minimo, int $maximo): int
    {
        if (! is_int($valor)) {
            throw new PayloadInvalidoException("{$campo} debe ser entero, recibido ".get_debug_type($valor).'.');
        }

        if ($valor < $minimo || $valor > $maximo) {
            throw new PayloadInvalidoException("{$campo} fuera de rango {$minimo}..{$maximo}: {$valor}.");
        }

        return $valor;
    }

    /** El largo se cuenta en BYTES UTF-8, no en caracteres. */
    private static function validaTexto(mixed $texto): string
    {
        if (! is_string($texto)) {
            throw new PayloadInvalidoException('texto debe ser string, recibido '.get_debug_type($texto).'.');
        }

        if (! preg_match('//u', $texto)) {
            throw new PayloadInvalidoException('texto no es UTF-8 válido.');
        }

        $largoBytes = strlen($texto);
        if ($largoBytes > self::MAXIMO_BYTES_TEXTO) {
            throw new PayloadInvalidoException('texto excede '.self::MAXIMO_BYTES_TEXTO.' bytes UTF-8: '.$largoBytes.'.');
        }

        return $texto;
    }

    /** grados × 1e7, redondeo half-away-from-zero (mismo criterio que el espejo JS). */
    private static function escalaCoordenada(float $grados): int
    {
        return (int) round($grados * self::FACTOR_COORDENADA);
    }

    /** Hex sin guiones → canónico "8-4-4-4-12" en minúsculas. */
    private static function formateaUuid(string $hex): string
    {
        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20, 12);
    }

    /** CRC-16/CCITT-FALSE: poly 0x1021, init 0xFFFF, sin reflexión, sin XOR final. */
    private static function crc16(string $bytes): string
    {
        return pack('n', self::crc16Valor($bytes));
    }

    private static function crc16Valor(string $bytes): int
    {
        $crc = self::CRC_INICIO;
        $largo = strlen($bytes);

        for ($i = 0; $i < $largo; $i++) {
            $crc ^= ord($bytes[$i]) << 8;

            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x8000) !== 0
                    ? (($crc << 1) ^ self::CRC_POLINOMIO) & 0xFFFF
                    : ($crc << 1) & 0xFFFF;
            }
        }

        return $crc;
    }
}
