/*
 * utilidades.js — helpers puros: números, DOM, plantillas de texto y accesibilidad.
 */
(function (SARD) {
    'use strict';

    const Util = {
        clamp: (v, min, max) => Math.min(max, Math.max(min, v)),
        lerp: (a, b, t) => a + (b - a) * t,

        $: (sel, raiz = document) => raiz.querySelector(sel),
        $$: (sel, raiz = document) => Array.from(raiz.querySelectorAll(sel)),

        /** Escapa texto para insertarlo como HTML. */
        escapar(texto) {
            return String(texto ?? '')
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        },

        /** Sustituye {clave} por valores. Las claves ausentes quedan visibles como [PENDIENTE: clave]. */
        plantilla(texto, valores = {}) {
            return String(texto).replace(/\{(\w+)\}/g, (_, clave) =>
                clave in valores ? String(valores[clave]) : `[PENDIENTE: ${clave}]`);
        },

        /**
         * Crea un elemento. attrs admite: class, text, html, dataset {}, on {evento: fn}, y atributos.
         * @returns {HTMLElement}
         */
        el(etiqueta, attrs = {}, hijos = []) {
            const nodo = document.createElement(etiqueta);
            for (const [clave, valor] of Object.entries(attrs)) {
                if (valor === undefined || valor === null || valor === false) {
                    continue;
                }
                if (clave === 'class') { nodo.className = valor; }
                else if (clave === 'text') { nodo.textContent = valor; }
                else if (clave === 'html') { nodo.innerHTML = valor; }
                else if (clave === 'dataset') { Object.assign(nodo.dataset, valor); }
                else if (clave === 'on') {
                    for (const [evento, fn] of Object.entries(valor)) { nodo.addEventListener(evento, fn); }
                }
                else { nodo.setAttribute(clave, valor === true ? '' : valor); }
            }
            for (const hijo of [].concat(hijos)) {
                if (hijo === null || hijo === undefined || hijo === false) { continue; }
                nodo.append(hijo instanceof Node ? hijo : document.createTextNode(String(hijo)));
            }
            return nodo;
        },

        /** Lee una ruta tipo 'ui.hud.indice' dentro de un objeto. */
        ruta(obj, camino) {
            return camino.split('.').reduce((acc, k) => (acc == null ? undefined : acc[k]), obj);
        },

        /** true si el foco está en un campo donde las teclas de una letra no deben interceptarse. */
        esCampoTexto(nodo) {
            if (!nodo || nodo === document.body) { return false; }
            const etiqueta = nodo.tagName;
            return etiqueta === 'INPUT' || etiqueta === 'TEXTAREA' || etiqueta === 'SELECT' || nodo.isContentEditable;
        },

        /** mm:ss */
        reloj(segundos) {
            const s = Math.max(0, Math.round(segundos));
            return `${String(Math.floor(s / 60)).padStart(2, '0')}:${String(s % 60).padStart(2, '0')}`;
        },

        minutos(segundos) {
            const m = segundos / 60;
            return Number.isInteger(m) ? String(m) : m.toLocaleString('es-CO', { maximumFractionDigits: 2 });
        },

        /** Copia texto al portapapeles; en file:// cae a execCommand si la API no está disponible. */
        async copiar(texto) {
            try {
                if (navigator.clipboard && window.isSecureContext) {
                    await navigator.clipboard.writeText(texto);
                    return true;
                }
            } catch (e) { /* cae al método clásico */ }
            const area = Util.el('textarea', { readonly: true, style: 'position:fixed;opacity:0;pointer-events:none' });
            area.value = texto;
            document.body.append(area);
            area.select();
            let ok = false;
            try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
            area.remove();
            return ok;
        },
    };

    SARD.Util = Util;
})(window.SARD = window.SARD || {});
