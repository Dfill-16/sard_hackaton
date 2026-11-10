/*
 * feedback.js — tooltips accesibles y helpers de animación compartidos por las escenas.
 *
 * Tooltip: aparece con hover y con foco de teclado (≤ 120 ms), se cierra con Esc, y su texto
 * también está en aria-describedby para lectores de pantalla (nunca solo visual).
 */
(function (SARD) {
    'use strict';

    let contador = 0;
    let flotante = null;

    function nodoFlotante() {
        if (!flotante) {
            flotante = document.createElement('div');
            flotante.className = 'tooltip tooltip--flotante';
            flotante.setAttribute('aria-hidden', 'true'); // el lector lo lee vía aria-describedby
            document.body.append(flotante);
        }
        return flotante;
    }

    let descripciones = null;
    function contenedorDescripciones() {
        if (!descripciones) {
            descripciones = document.createElement('div');
            descripciones.id = 'descripciones-tooltip';
            descripciones.className = 'sr-only';
            document.body.append(descripciones);
        }
        return descripciones;
    }

    function mostrar(objetivo, texto) {
        const t = nodoFlotante();
        t.textContent = texto;
        t.dataset.visible = 'si';
        const r = objetivo.getBoundingClientRect();
        const ancho = t.offsetWidth;
        const alto = t.offsetHeight;
        let x = r.left + r.width / 2 - ancho / 2;
        let y = r.top - alto - 8;
        if (y < 56) { y = r.bottom + 8; } // no tapar el HUD
        x = Math.max(8, Math.min(window.innerWidth - ancho - 8, x));
        t.style.transform = `translate(${Math.round(x)}px, ${Math.round(y)}px)`;
    }

    function ocultar() {
        if (flotante) { flotante.dataset.visible = 'no'; }
    }

    const Tooltip = {
        /**
         * @param {HTMLElement} el      elemento enfocable (button)
         * @param {string} texto
         * @param {AbortSignal} [senal] para retirar los listeners con la escena
         */
        adjuntar(el, texto, senal) {
            const id = `tt-${++contador}`;
            const descripcion = SARD.Util.el('span', { id, text: texto });
            // Contenedor propio: el elemento puede no estar aún en el DOM (botones anclados al 3D),
            // y aria-describedby necesita un destino que exista siempre.
            contenedorDescripciones().append(descripcion);
            senal?.addEventListener('abort', () => descripcion.remove(), { once: true });
            const previos = el.getAttribute('aria-describedby');
            el.setAttribute('aria-describedby', previos ? `${previos} ${id}` : id);
            const opciones = senal ? { signal: senal } : undefined;
            const ver = () => mostrar(el, texto);
            el.addEventListener('pointerenter', ver, opciones);
            el.addEventListener('focus', ver, opciones);
            el.addEventListener('pointerleave', ocultar, opciones);
            el.addEventListener('blur', ocultar, opciones);
            el.addEventListener('keydown', (e) => { if (e.key === 'Escape') { ocultar(); } }, opciones);
        },
        ocultar,
    };

    const Anim = {
        /**
         * Entrada de una escena: la primera vez se reproduce su timeline; al volver a una escena
         * ya vista se muestra en su estado final (INTERACTION_SPEC §2.5), sin repetir la
         * animación. Con reduced-motion, siempre el estado final.
         * @param {object} ctx
         * @param {() => gsap.core.Timeline} construir
         */
        entrar(ctx, construir) {
            if (!ctx.timeline) { ctx.timeline = construir(); }
            const yaVista = SARD.Estado.get('visitadas').has(ctx.escena.id);
            if (ctx.reducido() || yaVista) {
                // suppressEvents: saltar al final no re-ejecuta los .call() del timeline.
                ctx.timeline.progress(1, true).pause();
            } else {
                ctx.timeline.restart();
            }
            return ctx.timeline;
        },

        /** Registra y devuelve un loop ambiental si existe (los null se ignoran). */
        loop(ctx, tween) {
            if (tween) { ctx.registrarLoop(tween); }
            return tween;
        },
    };

    SARD.Tooltip = Tooltip;
    SARD.Anim = Anim;
})(window.SARD = window.SARD || {});
