/**
 * Pruebas del selector de idioma ES / EN de las interfaces.
 *
 * - Toda frase marcada para traducir en las vistas (x-t, x-t:atributo, $t('…'), t('…')) tiene su
 *   traducción en inglés, con los mismos parámetros «:nombre».
 * - Los catálogos que llegan del servidor (tipos, banderas, acciones de A6…) también.
 *
 * Ejecutar: node --test tests/js/
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { EN, PATRONES_EN } from '../../resources/js/i18n/en.js';
import { localeDe, normalizar, reemplazar, traducir, traducirDato } from '../../resources/js/i18n/index.js';

const raiz = new URL('../../', import.meta.url);
const leer = (ruta) => readFileSync(new URL(ruta, raiz), 'utf8');

const VISTAS = [
    'resources/views/tablero/index.blade.php',
    'resources/views/tablero/partials/tarjeta.blade.php',
    'resources/views/pwa/index.blade.php',
    'resources/views/campo/index.blade.php',
];
const SCRIPTS = ['resources/js/tablero/app.js', 'resources/js/pwa/app.js', 'resources/js/pwa/accesibilidad.js', 'resources/js/campo/app.js'];

/** Frases marcadas en el código; omite las que Blade rellena en el servidor ({{ … }}). */
function frasesMarcadas() {
    const frases = new Set();
    const agregar = (texto) => {
        const limpio = normalizar(texto);
        if (limpio && !limpio.includes('{{')) {
            frases.add(limpio);
        }
    };

    for (const vista of VISTAS) {
        const fuente = leer(vista);
        // El «=>» de las funciones flecha de Alpine no cierra la etiqueta.
        for (const [, , texto] of fuente.matchAll(/<(\w+)\b(?:=>|[^>])*?\sx-t(?=[\s>])(?:=>|[^>])*>([^<]*)<\/\1>/g)) {
            agregar(texto);
        }
        for (const [etiqueta] of fuente.matchAll(/<(?:=>|[^>])+>/g)) {
            for (const [, atributo] of etiqueta.matchAll(/x-t:([\w-]+)/g)) {
                const valor = etiqueta.match(new RegExp(`\\s${atributo}="([^"]*)"`));
                assert.ok(valor, `x-t:${atributo} sin atributo ${atributo} en ${vista}`);
                agregar(valor[1]);
            }
        }
        for (const [, texto] of fuente.matchAll(/\$t\('((?:[^'\\]|\\.)*)'/g)) {
            agregar(texto);
        }
    }

    for (const script of SCRIPTS) {
        const fuente = leer(script);
        for (const [, texto] of fuente.matchAll(/(?<![\w$.])t\('((?:[^'\\]|\\.)*)'/g)) {
            agregar(texto);
        }
        for (const [, texto] of fuente.matchAll(/titulo: '([^']*)'/g)) {
            agregar(texto);
        }
    }

    return [...frases];
}

const parametros = (texto) => [...texto.matchAll(/:([A-Za-z_]\w*)/g)].map((m) => m[1]).sort();

test('toda frase marcada en las vistas tiene traducción al inglés', () => {
    const frases = frasesMarcadas();
    assert.ok(frases.length > 150, `se esperaban más de 150 frases y hay ${frases.length}`);

    const faltan = frases.filter((frase) => EN[frase] === undefined);
    assert.deepEqual(faltan, [], `frases sin traducir:\n${faltan.join('\n')}`);
});

test('cada traducción conserva los mismos parámetros que el español', () => {
    for (const [espanol, ingles] of Object.entries(EN)) {
        assert.deepEqual(parametros(ingles), parametros(espanol), `parámetros distintos en «${espanol}»`);
        assert.ok(ingles.trim().length > 0, `traducción vacía en «${espanol}»`);
    }
});

test('el diccionario no repite claves', () => {
    const claves = [...leer('resources/js/i18n/en.js').matchAll(/^ {4}'((?:[^'\\]|\\.)*)':/gm)].map((m) => m[1]);
    const repetidas = claves.filter((clave, i) => claves.indexOf(clave) !== i);
    assert.deepEqual(repetidas, []);
});

test('los catálogos del servidor están traducidos (tipos, banderas, acciones y pendientes)', () => {
    const banderas = leer('app/Sard/Reglas/Banderas.php');
    const etiquetas = [...banderas.matchAll(/'etiqueta' => '([^']+)'/g)].map((m) => m[1]);
    const tipos = [...banderas.matchAll(/^\s+\d+ => '([^']+)',$/gm)].map((m) => m[1]);
    const tiposPwa = [...leer('app/Node/A/PwaController.php').matchAll(/'nombre' => '([^']+)'/g)].map((m) => m[1]);
    const coordinacion = leer('app/Agents/A6/Coordinacion.php');
    const acciones = [...coordinacion.matchAll(/const ACCION_\w+ = '([^']+)'/g)].map((m) => m[1]);
    const motivos = [...leer('app/Agents/A2/Triaje.php').matchAll(/\[0\.\d, '([^']+)'\]/g)].map((m) => m[1]);
    const conMayuscula = (texto) => texto.charAt(0).toUpperCase() + texto.slice(1);

    assert.equal(etiquetas.length, 15);
    assert.equal(tipos.length, 8);
    assert.equal(tiposPwa.length, 8);
    assert.equal(acciones.length, 2);
    assert.equal(motivos.length, 5);

    const datos = [
        ...etiquetas, ...etiquetas.map(conMayuscula), ...tipos.map(conMayuscula), ...tiposPwa, ...acciones, ...motivos,
        'Albergue', 'Acopio', 'Sangre', 'disponible', 'ocupada', 'asignada', 'ambulancia', 'bomberos',
        'confirmar ubicación', 'definir tipo de unidad', 'sin unidad disponible', 'personas: desconocido',
        'posible rumor: verificar con otra fuente',
    ];
    const faltan = datos.filter((dato) => traducirDato(dato, 'en') === dato);
    assert.deepEqual(faltan, []);
});

test('traducir: español por defecto, inglés con parámetros y sin traducción vuelve al español', () => {
    assert.equal(traducir('Conectado a :nodo', { nodo: 'B' }), 'Conectado a B');
    assert.equal(traducir('Conectado a :nodo', { nodo: 'B' }, 'en'), 'Connected to B');
    assert.equal(traducir('Una frase que no existe', {}, 'en'), 'Una frase que no existe');
    assert.equal(traducir('  Sin\n        ubicación  ', {}, 'en'), 'No location');
    assert.equal(traducir('Sin :unidad disponible en la ciudad', { unidad: 'ambulance' }, 'en'), 'No ambulance available in the city');
});

test('reemplazar no toca los «:» que no son parámetros ni los parámetros sin valor', () => {
    assert.equal(reemplazar('Brecha: :texto', { texto: 'x' }), 'Brecha: x');
    assert.equal(reemplazar(':a y :b', { a: 1 }), '1 y :b');
    assert.equal(reemplazar(':a', { a: ':b' }), ':b');
});

test('traducirDato usa los patrones y traduce sus partes', () => {
    assert.equal(traducirDato('enviar Ambulancia Alfa', 'en'), 'send Ambulancia Alfa');
    assert.equal(traducirDato('contradicciones: 2', 'en'), 'contradictions: 2');
    assert.equal(traducirDato('3 frente a 5 personas', 'en'), '3 vs 5 people');
    assert.equal(traducirDato('tipo 3 frente a tipo 1', 'en'), 'type 3 vs type 1');
    assert.equal(traducirDato('enviar Ambulancia Alfa', 'es'), 'enviar Ambulancia Alfa');
    assert.equal(traducirDato(null, 'en'), null);
    assert.ok(PATRONES_EN.length >= 4);
});

test('las horas siguen el idioma y mantienen el reloj de 12 h', () => {
    const momento = new Date(Date.UTC(2026, 10, 9, 18, 5, 0));
    const hora = (idioma) => momento.toLocaleTimeString(localeDe(idioma), { hour: '2-digit', minute: '2-digit', timeZone: 'UTC' });

    assert.equal(localeDe('es'), 'es-CO');
    assert.match(hora('es'), /^06:05\sp\.\s?m\.$/);
    assert.match(hora('en'), /^06:05\sPM$/);
});
