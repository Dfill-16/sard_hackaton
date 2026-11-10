/**
 * Pruebas de accesibilidad de la PWA: dictado, lectura en voz alta y vibración.
 * El navegador se reemplaza por dobles mínimos (sin micrófono ni voces reales).
 *
 * Ejecutar: node --test tests/js/
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { EN } from '../../resources/js/i18n/en.js';
import { traducir } from '../../resources/js/i18n/index.js';
import {
    VIBRACION, dictar, hablar, idiomaDeVoz, mensajeErrorDictado, reconocedorDisponible, resumenHablado, unirDictado, vibrar, vozDisponible,
} from '../../resources/js/pwa/accesibilidad.js';

/** Reconocedor falso: guarda la instancia para disparar sus eventos desde la prueba. */
function reconocedorFalso() {
    const instancias = [];
    class Reconocedor {
        constructor() {
            this.iniciado = false;
            this.detenido = false;
            instancias.push(this);
        }

        start() {
            this.iniciado = true;
        }

        stop() {
            this.detenido = true;
            this.onend?.();
        }
    }

    return { Reconocedor, instancias };
}

const resultado = (transcript, isFinal) => Object.assign([{ transcript }], { isFinal });

test('el dictado usa el idioma de la interfaz y entrega el texto parcial y el final', () => {
    const { Reconocedor, instancias } = reconocedorFalso();
    const textos = [];
    let fin;

    dictar({ lang: idiomaDeVoz('es'), alTexto: (texto, final) => textos.push([texto, final]), alTerminar: (error) => (fin = error) }, Reconocedor);
    const r = instancias[0];

    assert.equal(r.lang, 'es-CO');
    assert.equal(r.interimResults, true);
    assert.ok(r.iniciado);

    r.onresult({ resultIndex: 0, results: [resultado('hay gente', false)] });
    r.onresult({ resultIndex: 0, results: [resultado('hay gente atrapada', true)] });
    r.onend();

    assert.deepEqual(textos, [['hay gente', false], ['hay gente atrapada', true]]);
    assert.equal(fin, null);
    assert.equal(idiomaDeVoz('en'), 'en-US');
});

test('el dictado informa el error del reconocedor y se puede detener', () => {
    const { Reconocedor, instancias } = reconocedorFalso();
    let fin = 'sin terminar';

    const detener = dictar({ lang: 'es-CO', alTexto: () => {}, alTerminar: (error) => (fin = error) }, Reconocedor);
    instancias[0].onerror({ error: 'network' });
    detener();

    assert.ok(instancias[0].detenido);
    assert.equal(fin, 'network');
});

test('sin reconocedor en el navegador, el dictado termina con «no-soportado»', () => {
    let fin;
    dictar({ lang: 'es-CO', alTexto: () => {}, alTerminar: (error) => (fin = error) }, null);

    assert.equal(fin, 'no-soportado');
    assert.equal(reconocedorDisponible({}), null);
    assert.ok(reconocedorDisponible({ webkitSpeechRecognition: class {} }));
});

test('lo dictado se suma a la nota con un solo espacio', () => {
    assert.equal(unirDictado('', '  hay  humo '), 'hay humo');
    assert.equal(unirDictado('casa azul ', 'hay humo'), 'casa azul hay humo');
    assert.equal(unirDictado('casa azul', '   '), 'casa azul');
});

test('cada error del dictado tiene un mensaje claro y traducido; «aborted» no muestra nada', () => {
    for (const codigo of ['network', 'sin-red', 'not-allowed', 'service-not-allowed', 'no-speech', 'audio-capture', 'no-soportado', 'otro']) {
        const mensaje = mensajeErrorDictado(codigo);
        assert.ok(mensaje, codigo);
        assert.ok(EN[mensaje], `sin traducción: ${mensaje}`);
    }
    assert.equal(mensajeErrorDictado('aborted'), null);
    assert.match(mensajeErrorDictado('network'), /necesita señal/);
});

test('hablar usa las voces del teléfono, corta lo anterior y no falla sin voces', () => {
    const dichas = [];
    let cancelaciones = 0;
    const ventana = {
        SpeechSynthesisUtterance: class {
            constructor(texto) {
                this.texto = texto;
            }
        },
        speechSynthesis: {
            cancel: () => cancelaciones++,
            speak: (frase) => dichas.push([frase.texto, frase.lang]),
        },
    };

    assert.ok(vozDisponible(ventana));
    assert.equal(hablar('Reporte guardado', 'es-CO', ventana), true);
    assert.deepEqual(dichas, [['Reporte guardado', 'es-CO']]);
    assert.equal(cancelaciones, 1);

    assert.equal(hablar('hola', 'es-CO', {}), false);
    assert.equal(hablar('', 'es-CO', ventana), false);
});

test('el resumen hablado del último reporte dice tipo, banderas, personas, nota, estado y hora', () => {
    const registro = { tipo: 1, banderas: 3, personas: 2, texto: 'casa azul.', sin_ubicacion: true };
    const nombres = { tipo: 'Derrumbe o colapso', banderas: ['Persona atrapada', 'Dificultad respiratoria'], estado: 'Enviando…', hora: '04:50 p. m.' };

    assert.equal(
        resumenHablado(registro, nombres, (frase, params) => traducir(frase, params, 'es')),
        'Tu último reporte: Derrumbe o colapso. Marcaste: Persona atrapada, Dificultad respiratoria. 2 personas afectadas. '
            + 'Tu nota dice: casa azul. Se envió sin ubicación. Estado: Enviando. Hora: 04:50 p. m.',
    );

    const minimo = resumenHablado({ tipo: 0, banderas: 0, personas: 0, texto: '', sin_ubicacion: false },
        { tipo: 'Other emergency', banderas: [], estado: 'Received by dispatch', hora: '4:50 PM' },
        (frase, params) => traducir(frase, params, 'en'));
    assert.equal(minimo, 'Your last report: Other emergency. People affected: you did not know. Status: Received by dispatch. Time: 4:50 PM.');
    assert.match(resumenHablado({ ...registro, personas: 1, texto: '' }, nombres, (f, p) => traducir(f, p, 'es')), /1 persona afectada\./);
});

test('vibra un pulso al guardar y dos al confirmar; sin vibrador no falla', () => {
    const patrones = [];
    const navegador = { vibrate: (patron) => patrones.push(patron) > 0 };

    vibrar(VIBRACION.guardado, navegador);
    vibrar(VIBRACION.confirmado, navegador);

    assert.deepEqual(patrones, [[120], [120, 80, 120]]);
    assert.equal(vibrar([100], {}), false);
    assert.equal(vibrar([100], { vibrate: () => { throw new Error('bloqueado'); } }), false);
});
