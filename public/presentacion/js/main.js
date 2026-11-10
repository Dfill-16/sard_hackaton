/*
 * main.js — punto de entrada. App.iniciar() detecta WebGL: con WebGL carga Three.js y el
 * escenario base; sin él (o con ?sin3d) la presentación sigue completa en modo texto.
 */
(function (SARD) {
    'use strict';

    function arrancar() {
        SARD.App.iniciar().catch((e) => {
            SARD.Errores?.registrar('arranque', e.message);
            document.getElementById('loader')?.remove();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', arrancar, { once: true });
    } else {
        arrancar();
    }
})(window.SARD = window.SARD || {});
