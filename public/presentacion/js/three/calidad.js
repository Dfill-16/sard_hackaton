/*
 * calidad.js — monitor de FPS y niveles de degradación (INTERACTION_SPEC §8.6).
 *
 * Desviación C5: PerformanceObserver no emite entradas 'frame'; se mide con el delta de
 * gsap.ticker (el mismo tick que dibuja) en una ventana móvil de 60 frames.
 *
 * Niveles (en cascada e irreversibles en modo auto):
 *   0 Completo · 1 Reducido (pixelRatio 1, sombras 512², sin partículas)
 *   2 Ligero (pixelRatio 0.75, sin sombras, sin oleaje ni haz) · 3 Mínimo (modo texto)
 * Manual: Auto / Alta (0) / Media (1) / Baja (2), desde el índice.
 */
(function (SARD) {
    'use strict';

    const { Estado } = SARD;
    const VENTANA = 60;
    const UMBRAL_MS = 22;          // < 45 FPS sostenidos
    const CALENTAMIENTO = 180;     // frames que se ignoran tras iniciar o cambiar de escena/nivel
    const SALTO_MS = 250;          // un frame más largo es una pausa (pestaña, proyector), no rendimiento
    const VENTANAS_MALAS = 2;      // ventanas lentas consecutivas antes de degradar
    const MODOS = { alta: 0, media: 1, baja: 2 };

    const muestras = new Float32Array(VENTANA);
    let indice = 0;
    let llenas = 0;
    let ignorar = CALENTAMIENTO;
    let malasSeguidas = 0;
    let panel = null;
    let ultimoPanel = 0;

    function promedio() {
        let s = 0;
        for (let i = 0; i < llenas; i++) { s += muestras[i]; }
        return llenas ? s / llenas : 0;
    }

    const Calidad = {
        NIVELES: ['completo', 'reducido', 'ligero', 'minimo'],

        ratioPixeles(nivel) {
            if (nivel === 0) { return Math.min(window.devicePixelRatio || 1, 2); }
            return nivel === 1 ? 1 : 0.75;
        },

        /** Nivel de arranque: en móvil el 3D es decorativo (30 % de opacidad) → nivel 1. */
        nivelInicial() {
            const modo = Estado.get('calidad');
            if (modo in MODOS) { return MODOS[modo]; }
            return window.innerWidth < 768 ? 1 : 0;
        },

        reiniciarMedicion() {
            llenas = 0;
            indice = 0;
            ignorar = CALENTAMIENTO;
            malasSeguidas = 0;
        },

        /** Se llama en cada frame dibujado con el delta real en ms. */
        medir(deltaMs) {
            if (panel) { Calidad.pintarPanel(deltaMs); }
            if (ignorar > 0) { ignorar--; return; }
            if (deltaMs > SALTO_MS) { return; }
            muestras[indice] = deltaMs;
            indice = (indice + 1) % VENTANA;
            llenas = Math.min(VENTANA, llenas + 1);
            // Se evalúa una vez por ventana completa.
            if (indice !== 0 || llenas < VENTANA || Estado.get('calidad') !== 'auto') { return; }
            malasSeguidas = promedio() > UMBRAL_MS ? malasSeguidas + 1 : 0;
            if (malasSeguidas >= VENTANAS_MALAS) { Calidad.degradar(); }
        },

        degradar() {
            const siguiente = Estado.get('nivelCalidad') + 1;
            SARD.Errores.registrar('calidad', `FPS bajos (${Math.round(1000 / promedio())}): nivel ${siguiente}`);
            Calidad.aplicar(siguiente);
        },

        /** Fija el modo manual (auto | alta | media | baja). */
        fijar(modo) {
            Estado.set('calidad', modo);
            Calidad.aplicar(modo === 'auto' ? Estado.get('nivelCalidad') : MODOS[modo]);
        },

        aplicar(nivel) {
            nivel = Math.max(0, Math.min(3, nivel));
            Estado.set('nivelCalidad', nivel);
            Calidad.reiniciarMedicion();
            if (nivel === 3) {
                SARD.Motor.aModoTexto(SARD.contenido.ui.avisos.calidadMinima);
                return;
            }
            SARD.Motor.aplicarCalidad(nivel);
            SARD.Luces.aplicarCalidad(nivel);
            SARD.Niebla.aplicarCalidad(nivel);
            SARD.Terreno.aplicarCalidad(nivel);
            SARD.Faro.aplicarCalidad(nivel);
        },

        /** Panel de depuración (?depurar en la URL): FPS, llamadas, triángulos, nivel. */
        activarPanel() {
            panel = document.createElement('pre');
            panel.className = 'depuracion';
            panel.setAttribute('aria-hidden', 'true');
            document.body.append(panel);
        },

        pintarPanel(deltaMs) {
            const ahora = performance.now();
            if (ahora - ultimoPanel < 500) { return; }
            ultimoPanel = ahora;
            const i = SARD.Motor.info() || {};
            const m = SARD.M3.conteo();
            panel.textContent = [
                `fps    ${Math.round(1000 / Math.max(1, deltaMs))}  (media ${llenas ? Math.round(1000 / promedio()) : '—'})`,
                `nivel  ${Estado.get('nivelCalidad')} ${Calidad.NIVELES[Estado.get('nivelCalidad')]} · ${Estado.get('calidad')}`,
                `draws  ${i.llamadas}  tris ${i.triangulos}`,
                `gpu    geo ${i.geometrias}  tex ${i.texturas}`,
                `caché  geo ${m.geometrias}  mat ${m.materiales}  tex ${m.texturas}`,
                `gsap   ${gsap.globalTimeline.getChildren(true, true, true).length} tweens`,
            ].join('\n');
        },
    };

    SARD.Calidad = Calidad;
})(window.SARD = window.SARD || {});
