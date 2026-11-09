/**
 * Codec del payload binario de reporte v1 (big-endian) — espejo exacto del PHP.
 *
 * Contrato: specs/contratos/payload-reporte.md (brief v2.1 §8 · REQ-001, REQ-022).
 * Espejo PHP: app/Protocol/PayloadReporte.php — los dos codecs deben aceptar y
 * producir los mismos bytes (prueba cruzada con fixture compartido).
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
 * Total = 36 + n. CRC inválido o versión ≠ 1 → PayloadInvalidoError.
 * Corre en navegador y en Node: solo DataView/TextEncoder/TextDecoder,
 * sin APIs exclusivas de browser ni de Node. El ACK de vuelta (0x41 + 16 B
 * uuid) es parte del canal y NO se implementa aquí.
 */

const VERSION = 1;

/** Centinela exacto de lat/lon para "sin ubicación". Ningún otro valor (p. ej. -1) es especial. */
const SIN_UBICACION = 0x7fffffff;

/** Largo con texto vacío: 36 bytes (AC-004a). */
const LARGO_MINIMO = 36;

const MAXIMO_BYTES_TEXTO = 140;

/** Grados × 1e7 → int32 (±180° → ±1.8e9, cabe en int32). */
const FACTOR_COORDENADA = 1e7;

const CRC_POLINOMIO = 0x1021;
const CRC_INICIO = 0xffff;

const UUID_REGEX = /^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

const CODIFICADOR_TEXTO = new TextEncoder();
const DECODIFICADOR_TEXTO = new TextDecoder('utf-8', { fatal: true });

/**
 * Excepción específica del protocolo: paquete rechazado (CRC inválido,
 * versión desconocida, estructura corrupta o campo fuera de rango). El nodo
 * que la captura al decodificar descarta el paquete sin ACK.
 */
export class PayloadInvalidoError extends Error {
    constructor(mensaje) {
        super(mensaje);
        this.name = 'PayloadInvalidoError';
    }
}

/**
 * Codifica un reporte al payload binario v1 (36+n bytes).
 *
 * Acepta el objeto canónico que devuelve decode(): camelCase
 * { version, uuid, deviceId, lat, lon, tipo, banderas, personas, texto }.
 * Lanza PayloadInvalidoError si algún campo viola el contrato.
 *
 * @param {object} reporte
 * @returns {Uint8Array} payload binario de 36+n bytes
 */
export function encode(reporte) {
    if (reporte === null || typeof reporte !== 'object') {
        throw new PayloadInvalidoError(`reporte debe ser un objeto, recibido ${tipoDe(reporte)}.`);
    }

    const version = reporte.version ?? VERSION;
    if (version !== VERSION) {
        throw new PayloadInvalidoError(`Versión no soportada: se esperaba 1, recibida ${String(version)}.`);
    }

    const uuidHex = validaUuid(reporte.uuid);
    const deviceId = validaEntero(reporte.deviceId, 'deviceId', 0, 0xffffffff);
    const lat = validaCoordenada(reporte.lat, 'lat', 90);
    const lon = validaCoordenada(reporte.lon, 'lon', 180);
    const tipo = validaEntero(reporte.tipo, 'tipo', 0, 7);
    const banderas = validaEntero(reporte.banderas, 'banderas', 0, 0xffff);
    const personas = validaEntero(reporte.personas, 'personas', 0, 255);
    const textoBytes = validaTexto(reporte.texto);

    const total = LARGO_MINIMO + textoBytes.length;
    const buffer = new ArrayBuffer(total);
    const bytes = new Uint8Array(buffer);
    const vista = new DataView(buffer);

    vista.setUint8(0, VERSION);
    for (let i = 0; i < 16; i++) {
        bytes[1 + i] = parseInt(uuidHex.substr(i * 2, 2), 16);
    }
    vista.setUint32(17, deviceId);
    vista.setInt32(21, lat === null ? SIN_UBICACION : escalaCoordenada(lat));
    vista.setInt32(25, lon === null ? SIN_UBICACION : escalaCoordenada(lon));
    vista.setUint8(29, tipo);
    vista.setUint16(30, banderas);
    vista.setUint8(32, personas);
    vista.setUint8(33, textoBytes.length);
    bytes.set(textoBytes, 34);
    vista.setUint16(34 + textoBytes.length, crc16(bytes.subarray(0, 34 + textoBytes.length)));

    return bytes;
}

/**
 * Decodifica un payload binario v1 al objeto canónico.
 *
 * Estricto: rechaza largo menor a 36, versión ≠ 1, CRC inválido,
 * long_texto inconsistente, bytes sobrantes y campos fuera de rango.
 *
 * @param {Uint8Array|ArrayBuffer} bytes
 * @returns {object} { version, uuid, deviceId, lat, lon, tipo, banderas, personas, texto }
 */
export function decode(bytes) {
    if (bytes instanceof ArrayBuffer) {
        bytes = new Uint8Array(bytes);
    }
    if (!(bytes instanceof Uint8Array)) {
        throw new PayloadInvalidoError(`bytes debe ser Uint8Array o ArrayBuffer, recibido ${tipoDe(bytes)}.`);
    }

    const largo = bytes.length;
    if (largo < LARGO_MINIMO) {
        throw new PayloadInvalidoError(`Payload demasiado corto: ${largo} bytes, mínimo ${LARGO_MINIMO}.`);
    }

    const version = bytes[0];
    if (version !== VERSION) {
        throw new PayloadInvalidoError(`Versión no soportada: ${version}; solo v1.`);
    }

    const longTexto = bytes[33];
    if (longTexto > MAXIMO_BYTES_TEXTO) {
        throw new PayloadInvalidoError(`long_texto fuera de rango (máximo ${MAXIMO_BYTES_TEXTO}): ${longTexto}.`);
    }
    if (largo !== LARGO_MINIMO + longTexto) {
        throw new PayloadInvalidoError(`Largo inconsistente: ${largo} bytes para long_texto ${longTexto} (esperados ${LARGO_MINIMO + longTexto}).`);
    }

    const crcRecibido = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength).getUint16(34 + longTexto);
    if (crcRecibido !== crc16(bytes.subarray(0, 34 + longTexto))) {
        throw new PayloadInvalidoError('CRC inválido: paquete descartado.');
    }

    const tipo = bytes[29];
    if (tipo > 7) {
        throw new PayloadInvalidoError(`tipo fuera de rango 0..7: ${tipo}.`);
    }

    const uuid = uuidDesdeBytes(bytes.subarray(1, 17));
    if (!UUID_REGEX.test(uuid)) {
        throw new PayloadInvalidoError(`uuid no es un UUIDv7: ${uuid}.`);
    }

    let texto;
    try {
        texto = DECODIFICADOR_TEXTO.decode(bytes.subarray(34, 34 + longTexto));
    } catch {
        throw new PayloadInvalidoError('texto no es UTF-8 válido.');
    }

    return {
        version: VERSION,
        uuid,
        deviceId: new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength).getUint32(17),
        lat: coordenadaDesdeInt32(bytes, 21),
        lon: coordenadaDesdeInt32(bytes, 25),
        tipo,
        banderas: new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength).getUint16(30),
        personas: bytes[32],
        texto,
    };
}

/** Normaliza y valida el uuid: canónico con guiones (acepta mayúsculas), 32 hex, versión 7 y variante RFC 4122. */
function validaUuid(uuid) {
    if (typeof uuid !== 'string') {
        throw new PayloadInvalidoError(`uuid debe ser string, recibido ${tipoDe(uuid)}.`);
    }

    const hex = uuid.replaceAll('-', '').toLowerCase();
    if (!UUID_REGEX.test(formateaUuid(hex))) {
        throw new PayloadInvalidoError(`uuid no es un UUIDv7 canónico: ${uuid}.`);
    }

    return hex;
}

/** Convierte los 4 bytes big-endian a grados; centinela → null. */
function coordenadaDesdeInt32(bytes, offset) {
    const crudo = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength).getUint32(offset);
    if (crudo === SIN_UBICACION) {
        return null;
    }

    return new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength).getInt32(offset) / FACTOR_COORDENADA;
}

function validaCoordenada(grados, campo, limite) {
    if (grados === null || grados === undefined) {
        return null;
    }

    if (typeof grados !== 'number' || !Number.isFinite(grados)) {
        throw new PayloadInvalidoError(`${campo} debe ser number o null, recibido ${tipoDe(grados)}.`);
    }

    if (Math.abs(grados) > limite) {
        throw new PayloadInvalidoError(`${campo} fuera de rango (máximo ±${limite}): ${grados}.`);
    }

    return grados;
}

function validaEntero(valor, campo, minimo, maximo) {
    if (!Number.isInteger(valor)) {
        throw new PayloadInvalidoError(`${campo} debe ser entero, recibido ${tipoDe(valor)}.`);
    }

    if (valor < minimo || valor > maximo) {
        throw new PayloadInvalidoError(`${campo} fuera de rango ${minimo}..${maximo}: ${valor}.`);
    }

    return valor;
}

/** El largo se cuenta en BYTES UTF-8, no en caracteres. */
function validaTexto(texto) {
    if (typeof texto !== 'string') {
        throw new PayloadInvalidoError(`texto debe ser string, recibido ${tipoDe(texto)}.`);
    }

    const bytes = CODIFICADOR_TEXTO.encode(texto);
    if (bytes.length > MAXIMO_BYTES_TEXTO) {
        throw new PayloadInvalidoError(`texto excede ${MAXIMO_BYTES_TEXTO} bytes UTF-8: ${bytes.length}.`);
    }

    return bytes;
}

/** grados × 1e7, redondeo half-away-from-zero (mismo criterio que el espejo PHP round()). */
function escalaCoordenada(grados) {
    const escalado = grados * FACTOR_COORDENADA;

    return grados >= 0 ? Math.round(escalado) : -Math.round(-escalado);
}

/** Hex sin guiones → canónico "8-4-4-4-12" en minúsculas. */
function formateaUuid(hex) {
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20, 32)}`;
}

/** 16 bytes → UUIDv7 canónico en minúsculas. */
function uuidDesdeBytes(bytes) {
    let hex = '';
    for (let i = 0; i < bytes.length; i++) {
        hex += bytes[i].toString(16).padStart(2, '0');
    }

    return formateaUuid(hex);
}

/** CRC-16/CCITT-FALSE: poly 0x1021, init 0xFFFF, sin reflexión, sin XOR final. */
function crc16(bytes) {
    let crc = CRC_INICIO;
    for (let i = 0; i < bytes.length; i++) {
        crc ^= bytes[i] << 8;
        for (let bit = 0; bit < 8; bit++) {
            crc = (crc & 0x8000) !== 0 ? ((crc << 1) ^ CRC_POLINOMIO) & 0xffff : (crc << 1) & 0xffff;
        }
    }

    return crc;
}

function tipoDe(valor) {
    if (valor === null) {
        return 'null';
    }

    return typeof valor === 'object' ? (valor instanceof Uint8Array ? 'Uint8Array' : 'object') : typeof valor;
}
