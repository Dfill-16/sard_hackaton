/*
 * S00_apertura — «La noche que se apagó Manizales» (STORYBOARD §S00 · 45 s).
 *
 * Objetivo: enganchar con un momento concreto, no con una definición. Cero UI de producto:
 * baliza de acento apagada, HUD mínimo.
 *
 * Animación → qué explica:
 *   · Dolly descendente 6 s por la niebla (0,14,46) → (0,7,30): entramos en la noche.
 *   · Niebla 0.030 que sube y baja en ciclo de 9 s: la incertidumbre máxima; cuando sube,
 *     el faro casi desaparece.
 *   · Linterna que respira (1.8 ↔ 2.4, 3 s): el sistema sigue vivo aunque no se vea.
 *   · Tres bloques de texto en momentos distintos, nunca más de dos a la vez.
 * Salida: la niebla sube mientras la escena se funde.
 */
(function (SARD) {
    'use strict';

    const { Util } = SARD;

    // Posiciones del storyboard; el objetivo sube de y=4 a y=8 para que la linterna (≈ 17 m)
    // quede dentro del encuadre al final del dolly.
    const CAMARA_INICIO = { pos: [0, 14, 46], objetivo: [0, 8, 0] };
    const CAMARA_FIN = { pos: [0, 7, 30], objetivo: [0, 8, 0] };
    const MOMENTO_PIE = 39; // s: 52/60 del storyboard escalado a 45 s

    SARD.Escenas.definir({
        id: 'S00_apertura',

        escenario: { encuadre: null, niebla: 0.03, linterna: 2.0, baliza: false },

        plantilla(t) {
            return Util.el('section', { class: 's00' }, [
                Util.el('div', { class: 's00__texto sombra-texto' }, [
                    Util.el('h1', { class: 'display s00__titulo', text: t.titulo }),
                    Util.el('p', { class: 'texto-l s00__sub', text: t.sub }),
                ]),
                Util.el('p', { class: 'label s00__pie', text: t.pie }),
            ]);
        },

        cargar(ctx) {
            // Tooltip de la linterna: botón anclado (solo con 3D; el texto es decorativo).
            ctx.botonLinterna = Util.el('button', { type: 'button', class: 'ancla ancla--punto', 'aria-label': ctx.textos.tooltipLinterna });
            SARD.Tooltip.adjuntar(ctx.botonLinterna, ctx.textos.tooltipLinterna, ctx.senal);
        },

        activar(ctx) {
            const s = ctx.seccion;
            if (ctx.hay3D()) {
                // Dolly: la cámara empieza arriba y desciende por la niebla, sin cortes.
                SARD.Motor.encuadrar(CAMARA_INICIO, { duracion: 0 });
                SARD.Motor.encuadrar(CAMARA_FIN, { duracion: 6, ease: 'power1.inOut' });
                // Niebla: ciclo de 9 s alrededor de la densidad máxima.
                const control = { d: 0.03 };
                SARD.Anim.loop(ctx, ctx.reducido() ? null : gsap.to(control, {
                    d: 0.04, duration: 4.5, ease: 'sine.inOut', yoyo: true, repeat: -1, delay: 1.2,
                    onUpdate: () => SARD.Niebla.a(control.d, 0),
                }));
                SARD.Anim.loop(ctx, SARD.Faro.respirar());
                ctx.anclar(SARD.Faro.linterna, ctx.botonLinterna);
            }

            SARD.Anim.entrar(ctx, () => gsap.timeline({ paused: true })
                .addLabel('entrada', 1.2) // la primera respiración de la linterna (+0.2 s de desfase)
                .from(s.querySelector('.s00__titulo'), { yPercent: 12, autoAlpha: 0, duration: 0.9, ease: 'power2.out' }, 'entrada')
                .from(s.querySelector('.s00__sub'), { y: 16, autoAlpha: 0, duration: 0.7, ease: 'power2.out' }, 'entrada+=0.35')
                // El pie entra tarde y el subtítulo se retira: nunca más de dos bloques a la vez.
                .to(s.querySelector('.s00__sub'), { autoAlpha: 0, duration: 0.6, ease: 'power1.in' }, MOMENTO_PIE)
                .from(s.querySelector('.s00__pie'), { autoAlpha: 0, duration: 0.6, ease: 'power1.out' }, MOMENTO_PIE + 0.3));
        },

        /** Estado final estable (reduced-motion o fallo): el final del timeline, dos bloques. */
        estadoFinal(ctx) {
            ctx.timeline?.progress(1).pause();
        },

        salir(ctx) {
            if (ctx.hay3D()) { SARD.Niebla.a(0.036, 0.45, 'power1.in'); }
        },
    });
})(window.SARD = window.SARD || {});
