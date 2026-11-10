/*
 * errores.js — captura global y registro consultable desde el diálogo de atajos (?).
 * Regla: ningún error interrumpe la presentación.
 */
(function (SARD) {
    'use strict';

    const registro = [];
    const MAX = 50;

    function registrar(origen, mensaje, extra) {
        const entrada = {
            hora: new Date().toLocaleTimeString('es-CO', { hour12: false }),
            origen,
            mensaje: String(mensaje),
            extra: extra ? String(extra) : '',
        };
        registro.push(entrada);
        if (registro.length > MAX) { registro.shift(); }
        // Se deja traza en consola para depurar, sin romper el flujo.
        console.warn(`[SARD:${origen}]`, entrada.mensaje, entrada.extra);
        return entrada;
    }

    function instalar() {
        window.addEventListener('error', (e) => {
            registrar('js', e.message, e.filename ? `${e.filename}:${e.lineno}` : '');
        });
        window.addEventListener('unhandledrejection', (e) => {
            registrar('promesa', e.reason && e.reason.message ? e.reason.message : String(e.reason));
        });
    }

    SARD.Errores = { instalar, registrar, lista: () => registro.slice() };
})(window.SARD = window.SARD || {});
