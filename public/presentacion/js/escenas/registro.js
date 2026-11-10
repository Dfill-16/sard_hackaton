/*
 * registro.js — esqueletos HTML de las 17 escenas (H1).
 *
 * Cada escena muestra su H1 y todos sus textos de content/es.js, sin 3D ni timeline propio.
 * En H3 cada archivo js/escenas/sNN_*.js llama a SARD.Escenas.definir() con la escena
 * completa y reemplaza al esqueleto (se cargan después de este archivo).
 */
(function (SARD) {
    'use strict';

    const { Util } = SARD;

    /** Aplana los textos de una escena a una lista de frases legibles (sin claves técnicas). */
    function aplanar(valor, salida = []) {
        if (valor == null) { return salida; }
        if (typeof valor === 'string' || typeof valor === 'number') {
            salida.push(String(valor));
        } else if (Array.isArray(valor)) {
            if (valor.every((v) => typeof v === 'string')) {
                salida.push(valor.join(' · '));
            } else {
                valor.forEach((v) => aplanar(v, salida));
            }
        } else if (typeof valor === 'object') {
            const partes = Object.entries(valor)
                .filter(([k]) => k !== 'icono')
                .map(([, v]) => (typeof v === 'string' || typeof v === 'number' ? String(v) : null))
                .filter(Boolean);
            if (partes.length) { salida.push(partes.join(' — ')); }
            Object.entries(valor).forEach(([, v]) => { if (typeof v === 'object') { aplanar(v, salida); } });
        }
        return salida;
    }

    function cabeceraCapitulo(escena) {
        const cap = SARD.contenido.capitulos[escena.capitulo];
        return Util.el('p', { class: 'esqueleto__cap label' }, `Capítulo ${cap.n} · ${cap.titulo}`);
    }

    const Esqueleto = {
        /** Plantilla genérica: panel izquierdo con H1 + bloques + nota del presentador. */
        plantilla(textos, escena) {
            const { h1, ...resto } = textos;
            const frases = aplanar(resto);
            return Util.el('section', { class: 'esqueleto-escena' }, [
                Util.el('div', { class: 'panel panel--izquierda esqueleto', style: 'max-height:70vh;overflow-y:auto' }, [
                    cabeceraCapitulo(escena),
                    Util.el('h1', { text: h1 || escena.titulo }),
                    Util.el('ul', { class: 'esqueleto__bloques' }, frases.map((f) => Util.el('li', { text: f }))),
                    escena.nota ? Util.el('p', { class: 'esqueleto__nota' }, [Util.el('span', { class: 'label', text: 'Presentador · ' }), escena.nota]) : null,
                ]),
            ]);
        },
    };

    SARD.Esqueleto = Esqueleto;
})(window.SARD = window.SARD || {});
