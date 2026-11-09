/**
 * Pruebas de la cola de la PWA (T-005): backoff, ACK, repetidos, recarga y UUIDv7.
 *
 * Ejecutar: node --test tests/js/
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { decode, encode } from '../../resources/js/protocol/payload.js';
import {
    ESTADO, buscarRepetido, bytesJsonEquivalente, bytesTexto, esAckDe, esperaMs,
    nuevoDeviceId, recortarTexto, recuperarTrasRecarga, uuidV7, vencidos,
} from '../../resources/js/pwa/cola.js';

const UUID_V7 = /^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

test('uuidV7 es válido y lleva el timestamp en los primeros 48 bits', () => {
    const momento = Date.UTC(2026, 10, 9, 12, 0, 0, 123);
    const uuid = uuidV7(momento);

    assert.match(uuid, UUID_V7);
    assert.equal(parseInt(uuid.replaceAll('-', '').slice(0, 12), 16), momento);
    assert.notEqual(uuidV7(momento), uuid);
});

test('device_id anónimo es un entero de 4 B distinto de 0', () => {
    for (let i = 0; i < 50; i++) {
        const id = nuevoDeviceId();
        assert.ok(Number.isInteger(id) && id > 0 && id <= 0xffffffff);
    }
});

test('backoff sigue 1-2-4-8-16-30 s con jitter de ±20 % y tope 30 s', () => {
    const esperados = [1, 2, 4, 8, 16, 30, 30, 30];
    esperados.forEach((segundos, indice) => {
        const intentos = indice + 1;
        assert.equal(esperaMs(intentos, () => 0.5), segundos * 1000);
        assert.equal(esperaMs(intentos, () => 0), Math.round(segundos * 800));
        assert.equal(esperaMs(intentos, () => 1), Math.round(segundos * 1200));
    });
});

test('esAckDe acepta solo 0x41 + los 16 B del mismo uuid', () => {
    const uuid = uuidV7();
    const ack = new Uint8Array(17);
    ack[0] = 0x41;
    uuid.replaceAll('-', '').match(/../g).forEach((par, i) => (ack[i + 1] = parseInt(par, 16)));

    assert.equal(esAckDe(ack, uuid), true);
    assert.equal(esAckDe(ack.buffer, uuid), true);
    assert.equal(esAckDe(ack, uuidV7()), false, 'ACK de otro reporte');
    assert.equal(esAckDe(ack.slice(0, 16), uuid), false, 'largo distinto de 17');
    const otroTipo = ack.slice();
    otroTipo[0] = 0x42;
    assert.equal(esAckDe(otroTipo, uuid), false);
});

test('AC-001b: mismo tipo y banderas, pendiente y dentro de 10 min → se reutiliza el uuid', () => {
    const ahora = Date.now();
    const registros = [
        { uuid: 'a', tipo: 5, banderas: 256, estado: ESTADO.PENDIENTE, creado_en: ahora - 4 * 60_000 },
        { uuid: 'b', tipo: 5, banderas: 256, estado: ESTADO.CONFIRMADO, creado_en: ahora - 60_000 },
        { uuid: 'c', tipo: 5, banderas: 256, estado: ESTADO.PENDIENTE, creado_en: ahora - 11 * 60_000 },
        { uuid: 'd', tipo: 2, banderas: 8, estado: ESTADO.PENDIENTE, creado_en: ahora },
    ];

    assert.equal(buscarRepetido(registros, { tipo: 5, banderas: 256 }, 10, ahora)?.uuid, 'a');
    assert.equal(buscarRepetido(registros, { tipo: 5, banderas: 0 }, 10, ahora), null, 'otras banderas → reporte nuevo');
    assert.equal(buscarRepetido(registros, { tipo: 1, banderas: 256 }, 10, ahora), null, 'otro tipo → reporte nuevo');
});

test('solo salen los pendientes vencidos, del más antiguo al más nuevo', () => {
    const ahora = 10_000;
    const registros = [
        { uuid: 'nuevo', estado: ESTADO.PENDIENTE, proximo_intento: 0, creado_en: 3 },
        { uuid: 'viejo', estado: ESTADO.PENDIENTE, proximo_intento: 9_000, creado_en: 1 },
        { uuid: 'espera', estado: ESTADO.PENDIENTE, proximo_intento: 20_000, creado_en: 2 },
        { uuid: 'listo', estado: ESTADO.CONFIRMADO, proximo_intento: 0, creado_en: 0 },
    ];

    assert.deepEqual(vencidos(registros, ahora).map((r) => r.uuid), ['viejo', 'nuevo']);
});

test('AC-001a: un reporte «enviado» sin respuesta vuelve a «pendiente» al recargar', () => {
    assert.equal(recuperarTrasRecarga({ estado: ESTADO.ENVIADO }).estado, ESTADO.PENDIENTE);
    const confirmado = { estado: ESTADO.CONFIRMADO };
    assert.equal(recuperarTrasRecarga(confirmado), confirmado);
});

test('AC-028a y AC-011a: el payload no lleva datos personales y sin GPS va con el centinela', () => {
    const reporte = {
        version: 1, uuid: uuidV7(), deviceId: nuevoDeviceId(), lat: null, lon: null,
        tipo: 1, banderas: 1, personas: 0, texto: '',
    };
    const bytes = encode(reporte);
    const decodificado = decode(bytes);

    assert.equal(bytes.length, 36, 'sin texto = 36 B');
    assert.equal(decodificado.lat, null);
    assert.deepEqual(Object.keys(decodificado).sort(),
        ['banderas', 'deviceId', 'lat', 'lon', 'personas', 'texto', 'tipo', 'uuid', 'version']);
    assert.ok(bytesJsonEquivalente(reporte) > bytes.length, 'el binario pesa menos que el JSON');
});

test('el texto se limita a 140 B UTF-8 sin partir caracteres', () => {
    assert.equal(bytesTexto('ñ'), 2);
    const largo = 'atrapados en el sótano '.repeat(10);
    const recortado = recortarTexto(largo, 140);

    assert.ok(bytesTexto(recortado) <= 140);
    assert.ok(largo.startsWith(recortado));
    assert.equal(recortarTexto('ññ', 3), 'ñ');
});
