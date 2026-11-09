/**
 * Lógica pura de la cola de salida de la PWA (T-005), sin DOM ni IndexedDB, para probarla en Node.
 *
 * - UUIDv7 y device_id anónimo (4 B) con crypto.getRandomValues.
 * - Backoff 1-2-4-8-16-30 s con jitter ±20 % (parametros.md).
 * - Repetido: mismo tipo y banderas, aún pendiente, dentro de PWA_VENTANA_REPETIDO_MIN → mismo UUID.
 * - ACK válido: 17 B = 0x41 + los 16 B del UUID enviado.
 */

export const SERIE_BACKOFF_S = [1, 2, 4, 8, 16, 30];
export const JITTER = 0.2;
export const ACK_TIPO = 0x41;
export const ACK_LARGO = 17;

export const ESTADO = Object.freeze({
    PENDIENTE: 'pendiente',
    ENVIADO: 'enviado',
    CONFIRMADO: 'confirmado',
});

function bytesAleatorios(cantidad) {
    const bytes = new Uint8Array(cantidad);
    globalThis.crypto.getRandomValues(bytes);

    return bytes;
}

/** UUIDv7 canónico: 48 bits de milisegundos, versión 7, variante RFC 4122 y 74 bits aleatorios. */
export function uuidV7(milisegundos = Date.now()) {
    const bytes = bytesAleatorios(16);
    let restante = milisegundos;
    for (let i = 5; i >= 0; i--) {
        bytes[i] = restante % 256;
        restante = Math.floor(restante / 256);
    }
    bytes[6] = (bytes[6] & 0x0f) | 0x70;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;

    const hex = [...bytes].map((b) => b.toString(16).padStart(2, '0')).join('');

    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

/** device_id anónimo de 4 B (entero sin signo); nunca 0 para distinguirlo de «sin dato». */
export function nuevoDeviceId() {
    const vista = new DataView(bytesAleatorios(4).buffer);

    return vista.getUint32(0) || 1;
}

/** Milisegundos hasta el próximo intento tras `intentos` fallos (1 → ~1 s, 6+ → ~30 s). */
export function esperaMs(intentos, aleatorio = Math.random) {
    const base = SERIE_BACKOFF_S[Math.min(Math.max(0, intentos - 1), SERIE_BACKOFF_S.length - 1)];
    const factor = 1 + JITTER * (aleatorio() * 2 - 1);

    return Math.round(base * 1000 * factor);
}

/** Verifica que la respuesta del Nodo A sea el ACK de este UUID. */
export function esAckDe(respuesta, uuid) {
    const bytes = respuesta instanceof Uint8Array ? respuesta : new Uint8Array(respuesta);
    if (bytes.length !== ACK_LARGO || bytes[0] !== ACK_TIPO) {
        return false;
    }

    const hex = [...bytes.slice(1)].map((b) => b.toString(16).padStart(2, '0')).join('');

    return hex === uuid.replaceAll('-', '').toLowerCase();
}

/**
 * Busca un reporte pendiente que deba actualizarse en lugar de crear uno nuevo.
 *
 * @param {Array<object>} registros  registros de la cola
 * @param {{tipo: number, banderas: number}} nuevo
 * @param {number} ventanaMin  PWA_VENTANA_REPETIDO_MIN
 */
export function buscarRepetido(registros, nuevo, ventanaMin, ahora = Date.now()) {
    const limite = ahora - ventanaMin * 60_000;

    return registros
        .filter((r) => r.estado !== ESTADO.CONFIRMADO
            && r.tipo === nuevo.tipo
            && r.banderas === nuevo.banderas
            && r.creado_en >= limite)
        .sort((a, b) => b.creado_en - a.creado_en)[0] ?? null;
}

/** Registros listos para enviar: pendientes con proximo_intento vencido, del más antiguo al más nuevo. */
export function vencidos(registros, ahora = Date.now()) {
    return registros
        .filter((r) => r.estado === ESTADO.PENDIENTE && r.proximo_intento <= ahora)
        .sort((a, b) => a.creado_en - b.creado_en);
}

/** Tras recargar la página, lo que quedó «enviado» sin respuesta vuelve a «pendiente». */
export function recuperarTrasRecarga(registro) {
    return registro.estado === ESTADO.ENVIADO ? { ...registro, estado: ESTADO.PENDIENTE } : registro;
}

/** Tamaño del mismo reporte en JSON con claves completas (línea base de v2 §8). */
export function bytesJsonEquivalente(reporte) {
    const json = JSON.stringify({
        version: 1,
        uuid: reporte.uuid,
        device_id: reporte.deviceId,
        lat: reporte.lat,
        lon: reporte.lon,
        tipo: reporte.tipo,
        banderas: reporte.banderas,
        personas: reporte.personas,
        texto: reporte.texto,
    });

    return new TextEncoder().encode(json).length;
}

/** Largo del texto en bytes UTF-8 (el límite del payload es 140 B, no 140 caracteres). */
export function bytesTexto(texto) {
    return new TextEncoder().encode(texto ?? '').length;
}

/** Recorta el texto al máximo de bytes sin partir un carácter. */
export function recortarTexto(texto, maximo = 140) {
    let resultado = '';
    for (const caracter of texto ?? '') {
        if (bytesTexto(resultado + caracter) > maximo) {
            break;
        }
        resultado += caracter;
    }

    return resultado;
}
