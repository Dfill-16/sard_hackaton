/**
 * Pruebas del codec binario de reporte v1 (T-002) — espejo de tests/Unit/PayloadReporteTest.php.
 *
 * El fixture tests/fixtures/payload-v1.hex está CONGELADO: es el oráculo compartido
 * con PHP. Estas pruebas lo leen y lo comparan; nunca lo recalculan ni regeneran.
 *
 * Ejecutar: node --test resources/js/protocol/
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

import { decode, encode, PayloadInvalidoError } from './payload.js';

const CASO_A = '010194e8c25a3e7d4f9b2c1e5f6a7b8c9d1234567802ced770d3d97e98030000021468657269646f7320656e20656c207069736f20322d97';
const CASO_B = '010194e8c25a3e7d4f9b2c1e5f6a7b8c9e123456797fffffff7fffffff00000000003a21';

/** CRC-16/CCITT-FALSE del vector estándar "123456789" (oráculo independiente del codec). */
const CRC_123456789 = 0x29b1;

function hexABytes(hex) {
    return Uint8Array.from(hex.match(/.{2}/g), (par) => parseInt(par, 16));
}

function bytesAHex(bytes) {
    return Buffer.from(bytes).toString('hex');
}

/** Lee el primer payload de un fixture congelado de la prueba cruzada. */
function fixtureCruzado(nombre) {
    const raizFixtures = join(dirname(fileURLToPath(import.meta.url)), '..', '..', '..', 'tests', 'fixtures');
    const lineas = readFileSync(join(raizFixtures, nombre), 'utf8')
        .split(/\r?\n/)
        .filter((linea) => linea !== '' && linea[0] !== '#');

    return hexABytes(lineas[0]);
}

/** CRC-16/CCITT-FALSE (poly 0x1021, init 0xFFFF, sin reflexión, sin XOR final), reimplementado. */
function crc16Oracula(bytes) {
    let crc = 0xffff;
    for (let i = 0; i < bytes.length; i++) {
        crc ^= bytes[i] << 8;
        for (let bit = 0; bit < 8; bit++) {
            crc = ((crc << 1) ^ (crc & 0x8000 ? 0x1021 : 0)) & 0xffff;
        }
    }
    return crc;
}

test('decodifica fixture caso A con ubicación', () => {
    assert.deepEqual(decode(hexABytes(CASO_A)), {
        version: 1,
        uuid: '0194e8c2-5a3e-7d4f-9b2c-1e5f6a7b8c9d',
        deviceId: 305419896,
        lat: 4.711,
        lon: -74.0721,
        tipo: 3,
        banderas: 0,
        personas: 2,
        texto: 'heridos en el piso 2',
    });
});

test('decodifica fixture caso B sin ubicación', () => {
    const reporte = decode(hexABytes(CASO_B));

    assert.equal(reporte.version, 1);
    assert.equal(reporte.uuid, '0194e8c2-5a3e-7d4f-9b2c-1e5f6a7b8c9e');
    assert.equal(reporte.deviceId, 305419897);
    assert.equal(reporte.lat, null);
    assert.equal(reporte.lon, null);
    assert.equal(reporte.tipo, 0);
    assert.equal(reporte.banderas, 0);
    assert.equal(reporte.personas, 0);
    assert.equal(reporte.texto, '');
});

test('AC-004a: payload sin texto ocupa 36 bytes', () => {
    assert.equal(hexABytes(CASO_B).length, 36);
    assert.equal(encode({
        uuid: '0194e8c2-5a3e-7d4f-9b2c-1e5f6a7b8c9e',
        deviceId: 1,
        lat: null,
        lon: null,
        tipo: 0,
        banderas: 0,
        personas: 0,
        texto: '',
    }).length, 36);
});

test('encode es determinista frente al fixture congelado', () => {
    for (const caso of [CASO_A, CASO_B]) {
        assert.equal(bytesAHex(encode(decode(hexABytes(caso)))), caso);
    }
});

test('decode(encode(x)) = x', () => {
    const casos = [
        { uuid: '0194e8c2-5a3e-7d4f-9b2c-1e5f6a7b8c9d', deviceId: 42, lat: 4.711, lon: -74.0721, tipo: 3, banderas: 0x0400, personas: 2, texto: 'heridos en el piso 2' },
        { uuid: '0194e8d5-2c7a-7f3e-8a1d-4b6c9e0f2a3b', deviceId: 0xffffffff, lat: null, lon: null, tipo: 6, banderas: 0x0008, personas: 0, texto: '' },
        // 70 caracteres "ñ" = 140 BYTES UTF-8: al límite del contrato.
        { uuid: '0194e8e1-9b4c-7d2f-b3e5-6a8d1c4f7e9b', deviceId: 7, lat: -0.0000001, lon: 179.9999999, tipo: 7, banderas: 0xffff, personas: 255, texto: 'ñ'.repeat(70) },
        { uuid: '0194e8e1-9b4c-7d2f-b3e5-6a8d1c4f7e9c', deviceId: 0, lat: 90, lon: -180, tipo: 0, banderas: 0x8000, personas: 1, texto: 'tildes: áéíóú ü ñ' },
    ];

    for (const caso of casos) {
        assert.deepEqual(decode(encode(caso)), { version: 1, ...caso }, `ida y vuelta: ${caso.texto}`);
    }
});

test('CRC alterado lanza PayloadInvalidoError', () => {
    const bytes = hexABytes(CASO_A);
    bytes[bytes.length - 1] ^= 0x01;

    assert.throws(() => decode(bytes), PayloadInvalidoError);
});

test('byte de cuerpo alterado lanza error por CRC', () => {
    const bytes = hexABytes(CASO_A);
    bytes[10] ^= 0xff;

    assert.throws(() => decode(bytes), PayloadInvalidoError);
});

test('versión distinta de 1 lanza error', () => {
    const bytes = hexABytes(CASO_B);
    bytes[0] = 0x02;
    const cuerpo = bytes.subarray(0, bytes.length - 2);
    const reempaquetado = new Uint8Array(bytes);
    new DataView(reempaquetado.buffer).setUint16(bytes.length - 2, crc16Oracula(cuerpo));

    assert.throws(() => decode(reempaquetado), PayloadInvalidoError);
});

test('tipo 8 lanza error', () => {
    const bytes = hexABytes(CASO_B);
    bytes[29] = 0x08;
    const cuerpo = bytes.subarray(0, bytes.length - 2);
    const reempaquetado = new Uint8Array(bytes);
    new DataView(reempaquetado.buffer).setUint16(bytes.length - 2, crc16Oracula(cuerpo));

    assert.throws(() => decode(reempaquetado), PayloadInvalidoError);
});

test('payload truncado lanza error', () => {
    assert.throws(() => decode(hexABytes(CASO_B).subarray(0, 35)), PayloadInvalidoError);
});

test('bytes sobrantes lanzan error', () => {
    const conSobra = new Uint8Array(37);
    conSobra.set(hexABytes(CASO_B));

    assert.throws(() => decode(conSobra), PayloadInvalidoError);
});

test('texto de 141+ bytes UTF-8 lanza error en encode', () => {
    assert.throws(() => encode({
        uuid: '0194e8c2-5a3e-7d4f-9b2c-1e5f6a7b8c9d',
        deviceId: 1,
        lat: null,
        lon: null,
        tipo: 0,
        banderas: 0,
        personas: 0,
        texto: 'ñ'.repeat(71), // 71 caracteres × 2 B = 142 B
    }), PayloadInvalidoError);
});

test('-1 no es centinela: solo 0x7FFFFFFF', () => {
    const bytes = encode({
        uuid: '0194e8c2-5a3e-7d4f-9b2c-1e5f6a7b8c9d',
        deviceId: 1,
        lat: -0.1,
        lon: -0.1,
        tipo: 0,
        banderas: 0,
        personas: 0,
        texto: '',
    });

    // -0.1° × 1e7 = -1 000 000 → 0xFFF0BDC0 por campo.
    assert.equal(bytesAHex(bytes.subarray(21, 29)), 'fff0bdc0fff0bdc0');
    assert.equal(decode(bytes).lat, -0.1);
});

test('campos fuera de rango lanzan error en encode', () => {
    const base = {
        uuid: '0194e8c2-5a3e-7d4f-9b2c-1e5f6a7b8c9d',
        deviceId: 1,
        lat: null,
        lon: null,
        tipo: 0,
        banderas: 0,
        personas: 0,
        texto: '',
    };

    const invalidos = [
        ['tipo', 8],
        ['tipo', -1],
        ['banderas', 0x10000],
        ['personas', 256],
        ['deviceId', -1],
        ['deviceId', 4294967296],
        ['lat', 90.1],
        ['lon', -180.1],
    ];
    for (const [campo, valor] of invalidos) {
        assert.throws(() => encode({ ...base, [campo]: valor }), PayloadInvalidoError, `debía rechazar ${campo} = ${valor}`);
    }

    assert.throws(() => encode({ ...base, uuid: 'not-a-uuid' }), PayloadInvalidoError);
});

test('CRC-16/CCITT-FALSE es el de referencia', () => {
    // Segunda implementación (oráculo independiente) para no validar el codec con sí mismo.
    assert.equal(crc16Oracula(new TextEncoder().encode('123456789')), CRC_123456789);
});

test('prueba cruzada: JS decodifica lo que codifica PHP (fixture congelado)', () => {
    assert.deepEqual(decode(fixtureCruzado('payload-v1-desde-php.hex')), {
        version: 1,
        uuid: '0194e8e1-9b4c-7d2f-b3e5-6a8d1c4f7e9b',
        deviceId: 0x5a4c3b2d,
        lat: 6.2442,
        lon: -75.5812,
        tipo: 2,
        banderas: 0x0008,
        personas: 0,
        texto: 'fuego en bodega',
    });
});

test('prueba cruzada: JS decodifica fixture JS', () => {
    // Espejo del test PHP: el mismo reporte codificado por JS decodifica limpio.
    const reporte = decode(fixtureCruzado('payload-v1-desde-js.hex'));

    assert.equal(reporte.uuid, '0194e8d5-2c7a-7f3e-8a1d-4b6c9e0f2a3b');
    assert.equal(reporte.deviceId, 0x7f3e2d1c);
    assert.equal(reporte.lat, 4.65);
    assert.equal(reporte.lon, -74.1);
    assert.equal(reporte.tipo, 6);
    assert.equal(reporte.banderas, 0x0400);
    assert.equal(reporte.texto, 'vía bloqueada por derrumbe');
});
