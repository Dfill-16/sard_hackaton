/*
 * Sprite de iconos de trazo 1.75 px, 24×24.
 * Trazos copiados literalmente de resources/views/pwa/partials/icono.blade.php y
 * resources/views/tablero/partials/icono.blade.php. Los cinco marcados «nuevo» son los
 * únicos creados para la presentación (ASSETS.md §3.1, #41–45), con el mismo lenguaje.
 *
 * Se inyecta inline en el documento porque <use href="sprite.svg#x"> externo no carga
 * desde file:// en Chromium.
 */
(function (SARD) {
    'use strict';

    const TRAZOS = {
        // --- PWA ---
        colapso: '<path d="M4 21V9l6-4v16M10 21h10V11l-4-2"/><path d="M14 13l-1.5 2.5L15 17l-1.5 2.5"/><path d="M3 21h18"/>',
        fuego: '<path d="M12 21c-3.9 0-6.5-2.6-6.5-6 0-3.5 2.7-5.6 3.5-9 2 1.3 3 3.2 3 5 .9-.6 1.6-1.6 1.9-2.8 2.3 1.9 4.6 4.4 4.6 6.8 0 3.4-2.6 6-6.5 6z"/><path d="M12 21c-1.5 0-2.6-1.1-2.6-2.6 0-1.4 1.2-2.4 2.6-3.9 1.4 1.5 2.6 2.5 2.6 3.9 0 1.5-1.1 2.6-2.6 2.6z"/>',
        medico: '<path d="M9 3.5h6v5.5h5.5v6H15v5.5H9V15H3.5V9H9z"/>',
        ladera: '<path d="M2.5 20h19L14 7l-3.5 5.5L8 9z"/><path d="M15 15.5h.01M18 17.5h.01M12.5 18h.01"/>',
        agua: '<path d="M3 9c1.5 0 1.5-1.2 3-1.2S7.5 9 9 9s1.5-1.2 3-1.2S13.5 9 15 9s1.5-1.2 3-1.2S19.5 9 21 9"/><path d="M3 14c1.5 0 1.5-1.2 3-1.2S7.5 14 9 14s1.5-1.2 3-1.2 1.5 1.2 3 1.2 1.5-1.2 3-1.2 1.5 1.2 3 1.2"/><path d="M3 19c1.5 0 1.5-1.2 3-1.2S7.5 19 9 19s1.5-1.2 3-1.2 1.5 1.2 3 1.2 1.5-1.2 3-1.2 1.5 1.2 3 1.2"/>',
        via: '<path d="M3 10h18v5H3z"/><path d="M7 10l-3 5M12 10l-3 5M17 10l-3 5M21 10l-3 5"/><path d="M5 15v5M19 15v5M5 10V7M19 10V7"/>',
        servicios: '<path d="M13 2.5 5 13.5h6l-1 8 8-11h-6z"/>',
        otro: '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5v5.5M12 16.5h.01"/>',
        ubicacion: '<path d="M12 21c-1.5-1.9-6.5-6.6-6.5-11a6.5 6.5 0 0 1 13 0c0 4.4-5 9.1-6.5 11z"/><circle cx="12" cy="10" r="2.3"/>',
        'sin-ubicacion': '<path d="M8.2 5.3A6 6 0 0 1 18 10c0 1.6-.6 3.2-1.4 4.6M14 18.3c-1 1-1.7 1.7-2 2.2-1.5-1.9-6-6.4-6-10.5"/><path d="M3 3l18 18"/>',
        senal: '<path d="M4 18h.01M8 18v-3M12 18v-6M16 18V9M20 18V6"/>',
        'sin-senal': '<path d="M4 18h.01M8 18v-3M12 18v-6"/><path d="M15 9l5 5M20 9l-5 5"/>',
        check: '<path d="M4.5 12.5l5 5 10-11"/>',
        reloj: '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
        envio: '<path d="M4 12h12M11 6l6 6-6 6"/><path d="M20 5v14"/>',
        menos: '<path d="M5 12h14"/>',
        mas: '<path d="M12 5v14M5 12h14"/>',
        escudo: '<path d="M12 3l7.5 3v5.5c0 4.6-3.2 8.2-7.5 9.5-4.3-1.3-7.5-4.9-7.5-9.5V6z"/>',
        microfono: '<rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5.5 11a6.5 6.5 0 0 0 13 0M12 17.5V21M8.5 21h7"/>',
        altavoz: '<path d="M4 9.5h3.5L12 5.5v13l-4.5-4H4z"/><path d="M15.5 9a4 4 0 0 1 0 6M18 6.5a7.5 7.5 0 0 1 0 11"/>',
        // --- Tablero ---
        dispositivos: '<rect x="3" y="6" width="7" height="12" rx="1.5"/><rect x="14" y="3" width="7" height="15" rx="1.5"/><path d="M6.5 15h.01M17.5 15h.01"/>',
        rumor: '<path d="M4 5h16v11H9l-5 4z"/><path d="M10 9.5a2 2 0 1 1 2.5 1.9c-.3.1-.5.4-.5.7v.4M12 14.5h.01"/>',
        alerta: '<path d="M12 4 2.8 19.5h18.4z"/><path d="M12 10v4M12 17h.01"/>',
        telefono: '<path d="M5 4h3.5l1.5 4-2 1.5a10 10 0 0 0 6.5 6.5l1.5-2 4 1.5V19a1.5 1.5 0 0 1-1.5 1.5A15.5 15.5 0 0 1 3.5 5.5 1.5 1.5 0 0 1 5 4z"/>',
        cerrar: '<path d="M6 6l12 12M18 6 6 18"/>',
        sol: '<circle cx="12" cy="12" r="4"/><path d="M12 2.5v2M12 19.5v2M2.5 12h2M19.5 12h2M5.3 5.3l1.4 1.4M17.3 17.3l1.4 1.4M5.3 18.7l1.4-1.4M17.3 6.7l1.4-1.4"/>',
        luna: '<path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5z"/>',
        'flecha-arriba': '<path d="M12 19V5M6 11l6-6 6 6"/>',
        cadena: '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>',
        plegar: '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 4v16M15.5 9.5 13 12l2.5 2.5"/>',
        desplegar: '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 4v16M13 9.5l2.5 2.5-2.5 2.5"/>',
        capas: '<path d="m12 3 9 5-9 5-9-5z"/><path d="m3 13 9 5 9-5"/>',
        // --- Nuevos (presentación) ---
        radio: '<path d="M12 11v10M8.5 21h7"/><circle cx="12" cy="9" r="2"/><path d="M8.5 5.5a5 5 0 0 0 0 7M15.5 5.5a5 5 0 0 1 0 7M6 3a8.5 8.5 0 0 0 0 12M18 3a8.5 8.5 0 0 1 0 12"/>',
        gateway: '<rect x="8" y="8" width="8" height="8" rx="1.5"/><path d="M3 12h5M16 12h5M18.5 9.5 21 12l-2.5 2.5M5.5 9.5 3 12l2.5 2.5"/>',
        'sol-panel': '<path d="M4 15 7 6h13l-3 9z"/><path d="M5.5 10.5h13M10 6l-2 9M15 6l-2 9M12 15v5M9 20h6"/>',
        'reloj-10': '<circle cx="12" cy="12" r="8.5"/><path d="M8.5 9.5 10 8.5v7M14.5 8.5a1.5 1.5 0 0 1 1.5 1.5v4a1.5 1.5 0 0 1-3 0v-4a1.5 1.5 0 0 1 1.5-1.5z"/>',
        faro: '<path d="M10 9h4l1.5 12h-7z"/><path d="M9.5 6h5v3h-5zM12 3v3M7 21h10"/><path d="M4.5 5 8 6.5M19.5 5 16 6.5"/>',
    };

    const ATRIBUTOS = 'viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"';

    /** Inyecta el sprite (oculto) una sola vez al inicio del <body>. */
    function inyectar() {
        if (document.getElementById('sprite-iconos')) {
            return;
        }
        const simbolos = Object.keys(TRAZOS)
            .map((nombre) => `<symbol id="i-${nombre}" ${ATRIBUTOS}>${TRAZOS[nombre]}</symbol>`)
            .join('');
        const contenedor = document.createElement('div');
        contenedor.innerHTML = `<svg id="sprite-iconos" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" style="position:absolute;width:0;height:0;overflow:hidden">${simbolos}</svg>`;
        document.body.prepend(contenedor.firstElementChild);
    }

    /**
     * Devuelve el marcado de un icono decorativo (aria-hidden): siempre va junto a un texto.
     * @param {string} nombre
     * @param {string} [clase]
     */
    function html(nombre, clase = 'icono') {
        if (!TRAZOS[nombre]) {
            SARD.Errores?.registrar('icono', `Icono desconocido: ${nombre}`);
            return '';
        }
        return `<svg class="${clase}" aria-hidden="true" focusable="false"><use href="#i-${nombre}"></use></svg>`;
    }

    SARD.Iconos = { inyectar, html, nombres: () => Object.keys(TRAZOS) };
})(window.SARD = window.SARD || {});
