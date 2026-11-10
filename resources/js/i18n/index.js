import { EN, PATRONES_EN } from './en.js';

/**
 * Idioma de las interfaces (tablero, PWA ciudadana y campo): español por defecto, inglés opcional.
 *
 * - El texto fuente es el español del código: la clave del diccionario ES el texto en español,
 *   así una frase sin traducir se ve en español en lugar de una clave rota.
 * - Funciona sin red y sin recargar: el diccionario va en el bundle y el cambio es reactivo (Alpine).
 * - Solo se traduce la interfaz y los catálogos fijos (tipos, banderas, estados…). Lo que escribió
 *   el ciudadano, las justificaciones de A6 y el texto de las alertas quedan como están en el log.
 */
export const IDIOMAS = ['es', 'en'];
export const IDIOMA_POR_DEFECTO = 'es';
const CLAVE_LOCAL = 'sard-idioma';

const DICCIONARIOS = { en: EN };
const PATRONES = { en: PATRONES_EN };

/** Junta espacios y saltos de línea: el texto de Blade llega con la sangría del HTML. */
export function normalizar(texto) {
    return String(texto ?? '').replace(/\s+/g, ' ').trim();
}

/** Sustituye «:nombre» por params.nombre (mismo formato que los archivos lang de Laravel). */
export function reemplazar(texto, params = {}) {
    return texto.replace(/:([A-Za-z_][A-Za-z0-9_]*)/g, (coincidencia, nombre) =>
        Object.prototype.hasOwnProperty.call(params, nombre) ? String(params[nombre]) : coincidencia,
    );
}

/**
 * Traduce una frase de la interfaz. Si no hay traducción, devuelve el español.
 *
 * @param {string} texto frase en español, con «:parametros»
 * @param {Record<string, unknown>} params
 * @param {string} idioma
 */
export function traducir(texto, params = {}, idioma = IDIOMA_POR_DEFECTO) {
    const clave = normalizar(texto);
    const traduccion = DICCIONARIOS[idioma]?.[clave];

    return reemplazar(traduccion ?? clave, params);
}

/**
 * Traduce un dato que viene del servidor (tipo, bandera, pendiente, acción…): primero el
 * diccionario y luego los patrones con partes variables («enviar Ambulancia Alfa»).
 */
export function traducirDato(texto, idioma = IDIOMA_POR_DEFECTO) {
    if (texto === null || texto === undefined || texto === '') {
        return texto;
    }
    const clave = normalizar(texto);
    const directa = DICCIONARIOS[idioma]?.[clave];
    if (directa !== undefined) {
        return directa;
    }
    for (const [patron, plantilla] of PATRONES[idioma] ?? []) {
        const partes = clave.match(patron);
        if (partes) {
            return plantilla.replace(/\$(\d)/g, (_, indice) => traducirDato(partes[Number(indice)], idioma));
        }
    }

    return clave;
}

/** Locale para fechas y horas: los dos usan reloj de 12 h, así cambiar de idioma no cambia el formato. */
export function localeDe(idioma) {
    return idioma === 'en' ? 'en-US' : 'es-CO';
}

export function idiomaGuardado() {
    try {
        const guardado = localStorage.getItem(CLAVE_LOCAL);

        return IDIOMAS.includes(guardado) ? guardado : IDIOMA_POR_DEFECTO;
    } catch (error) {
        return IDIOMA_POR_DEFECTO;
    }
}

function guardarIdioma(idioma) {
    try {
        localStorage.setItem(CLAVE_LOCAL, idioma);
    } catch (error) {
        // Sin almacenamiento local: el idioma dura solo esta sesión.
    }
}

let alpine = null;

/** Idioma activo; dentro de una expresión de Alpine, leerlo hace que se repinte al cambiar. */
export function idiomaActual() {
    return alpine ? alpine.store('idioma').actual : IDIOMA_POR_DEFECTO;
}

export function t(texto, params = {}) {
    return traducir(texto, params, idiomaActual());
}

export function td(texto) {
    return traducirDato(texto, idiomaActual());
}

export function locale() {
    return localeDe(idiomaActual());
}

/**
 * Registra en Alpine:
 * - $store.idioma (actual, alternar())
 * - $t('frase', {params}) y $td(dato) en las expresiones
 * - x-t: traduce el texto del elemento; x-t:placeholder, x-t:title, x-t:aria-label… el atributo.
 *   El elemento con x-t solo debe contener texto (sin hijos).
 *
 * @param {import('alpinejs').Alpine} instancia
 * @param {{ titulo?: string, paramsTitulo?: Record<string, unknown> }} opciones
 */
export function instalarIdioma(instancia, opciones = {}) {
    alpine = instancia;

    instancia.store('idioma', {
        actual: idiomaGuardado(),
        alternar() {
            this.actual = this.actual === 'es' ? 'en' : 'es';
            guardarIdioma(this.actual);
            window.dispatchEvent(new CustomEvent('sard-idioma', { detail: this.actual }));
        },
    });

    instancia.magic('t', () => t);
    instancia.magic('td', () => td);

    instancia.directive('t', (el, { value }, { effect }) => {
        const original = value ? el.getAttribute(value) : el.textContent;
        effect(() => {
            const texto = traducir(original, {}, instancia.store('idioma').actual);
            if (value) {
                el.setAttribute(value, texto);
            } else {
                el.textContent = texto;
            }
        });
    });

    instancia.effect(() => {
        const idioma = instancia.store('idioma').actual;
        document.documentElement.lang = idioma;
        if (opciones.titulo) {
            document.title = traducir(opciones.titulo, opciones.paramsTitulo ?? {}, idioma);
        }
    });
}
