/*
 * estado.js — estado global observable. Una sola fuente de verdad: HUD, índice,
 * controles y escenas leen de aquí; nadie mantiene estado duplicado.
 */
(function (SARD) {
    'use strict';

    const datos = {
        escenaActual: null,     // id de la escena activa
        indiceActual: -1,
        capituloActual: 0,
        navegando: false,       // true durante una transición: el input se ignora
        pausado: false,         // pausa del presentador (P)
        demoAbierta: false,
        controlesOcultos: false,
        reduccionMovimiento: false,
        modoTexto: false,       // true si no hay WebGL o la calidad cae al nivel 3
        calidad: 'auto',        // auto | alta | media | baja (H2)
        nivelCalidad: 0,        // 0..3 (H2)
        visitadas: new Set(),
        completadas: new Set(),
    };

    const oyentes = new Map();

    const Estado = {
        get(clave) {
            return datos[clave];
        },

        set(clave, valor) {
            const anterior = datos[clave];
            if (anterior === valor) { return; }
            datos[clave] = valor;
            (oyentes.get(clave) || []).forEach((cb) => {
                try { cb(valor, anterior); } catch (e) { SARD.Errores?.registrar('estado', `${clave}: ${e.message}`); }
            });
        },

        /** Notifica sin cambiar la referencia (para Sets). */
        tocar(clave) {
            (oyentes.get(clave) || []).forEach((cb) => cb(datos[clave], datos[clave]));
        },

        /** @returns {() => void} función para darse de baja */
        on(clave, cb) {
            if (!oyentes.has(clave)) { oyentes.set(clave, []); }
            oyentes.get(clave).push(cb);
            return () => {
                const lista = oyentes.get(clave) || [];
                const i = lista.indexOf(cb);
                if (i >= 0) { lista.splice(i, 1); }
            };
        },
    };

    SARD.Estado = Estado;
})(window.SARD = window.SARD || {});
