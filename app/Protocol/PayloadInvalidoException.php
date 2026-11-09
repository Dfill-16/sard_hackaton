<?php

namespace App\Protocol;

/**
 * Excepción específica del protocolo binario de reportes: paquete rechazado.
 *
 * Se lanza cuando el payload viola el contrato (specs/contratos/payload-reporte.md):
 * CRC inválido, versión desconocida, estructura corrupta o campo fuera de rango.
 * El nodo que la captura al decodificar descarta el paquete sin ACK (el cliente reintenta).
 */
final class PayloadInvalidoException extends \RuntimeException {}
