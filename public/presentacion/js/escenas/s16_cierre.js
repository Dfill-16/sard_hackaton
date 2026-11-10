/*
 * S16_cierre — «La última luz» (STORYBOARD §S16 · 45 s). [CTA a la demo en vivo]
 *
 * Objetivo: cerrar con el mensaje exacto y dejarlo resonar.
 *   «La tecnología puede salvar vidas cuando se pone al servicio de la comunidad»
 *
 * Composición (STORYBOARD §S16):
 *   · Encuadre cerrado de la linterna, a intensidad 2,6 CONSTANTE (sin latido: el sistema
 *     está en reposo, no en emergencia).
 *   · Niebla a 0,012 que se disipa un 20 % (0,012 → 0,0096) y termina 0,2 s ANTES del texto.
 *   · Mensaje letra a letra con SplitText (stagger 0,045), DIRECTO sobre el 3D (sin tarjeta).
 *   · Pie y CTA pequeño «A continuación: demo en vivo». Botón «Repetir» (reinicia la escena).
 *   · Deriva de cámara de 8 s hacia z 18.
 * Navegación: → / «Ir a la demo» abren el panel de la demo (alSiguiente). R reinicia la
 * presentación (lo gestiona el router de forma global).
 */
(function (SARD) {
    'use strict';

    const { Util } = SARD;

    const NIEBLA_BASE = 0.012;
    const NIEBLA_DISIPADA = NIEBLA_BASE * 0.8; // se disipa un 20 %
    const LINTERNA = 2.6;
    const STAGGER = 0.045;
    const DERIVA_Z = 18;
    const DERIVA_DUR = 8;

    SARD.Escenas.definir({
        id: 'S16_cierre',

        // La cámara la mueve la propia escena (encuadre cerrado de la linterna) en activar.
        escenario: { encuadre: null, niebla: NIEBLA_BASE, linterna: LINTERNA, baliza: false, haz: false, duracionAmbiente: 1.4 },

        plantilla(t) {
            return Util.el('section', { class: 's16' }, [
                Util.el('h1', { class: 's16__mensaje', text: t.mensaje }),
                Util.el('div', { class: 's16__cta' }, [
                    Util.el('p', { class: 'label s16__cta-texto', text: t.cta }),
                    Util.el('button', {
                        type: 'button', class: 'btn btn--secundario s16__a-demo', text: t.ctaBoton,
                        on: { click: () => SARD.HUD.abrirDemo() },
                    }),
                ]),
                Util.el('button', {
                    type: 'button', class: 's16__repetir', text: t.repetir,
                    on: { click: () => this.reproducir(this.ctx, true) },
                }),
                Util.el('p', { class: 's16__pie', text: t.pie }),
            ]);
        },

        cargar(ctx) {
            // Se guarda el ctx para que «Repetir» (handler del DOM) pueda reproducir la escena.
            this.ctx = ctx;
        },

        activar(ctx) {
            this.ctx = ctx;
            this.encuadrar(ctx);
            this.reproducir(ctx, false);
        },

        /** Encuadre cerrado de la linterna del faro + deriva lenta de 8 s hacia z 18. */
        encuadrar(ctx) {
            if (!ctx.hay3D() || !SARD.Faro.linterna) { return; }
            const linterna = SARD.Faro.linterna.getWorldPosition(new THREE.Vector3());
            // Cámara cerca de la linterna; el objetivo va 2,6 m por debajo para que la luz quede
            // ENCIMA del mensaje (centrado) y no detrás de las letras.
            SARD.Motor.encuadrar(
                { pos: [linterna.x + 1.5, linterna.y - 1, linterna.z + 10], objetivo: [linterna.x, linterna.y - 2.6, linterna.z], alto: 6 },
                { duracion: ctx.reducido() ? 0 : 1.2 },
            );
        },

        /**
         * Reproduce la entrada: disipa la niebla, escribe el mensaje letra a letra y lanza la
         * deriva de cámara. Con reduced-motion o al repetir/volver, aplica el estado final.
         * @param {boolean} repetir  true = lo pidió el botón «Repetir» (siempre reproduce).
         */
        reproducir(ctx, repetir) {
            const s = ctx.seccion;
            const mensaje = s.querySelector('.s16__mensaje');

            // SplitText en caracteres (una sola vez); si no está disponible, se anima el bloque.
            if (!ctx.split && window.SplitText) {
                try { ctx.split = new SplitText(mensaje, { type: 'words,chars' }); } catch (e) { ctx.split = null; }
            }
            const letras = ctx.split ? ctx.split.chars : [mensaje];

            // Al repetir se mata la deriva y los tweens previos para no pisar el estado.
            ctx.loops.forEach((l) => { try { l.kill(); } catch (e) { /* ya muerto */ } });
            ctx.loops = [];
            gsap.killTweensOf(letras);

            const reduce = ctx.reducido();
            const yaVista = SARD.Estado.get('visitadas').has(ctx.escena.id);
            const inmediato = reduce || (yaVista && !repetir);

            if (inmediato) {
                this.estadoFinal(ctx);
                return;
            }

            // La niebla se disipa un 20 % y termina 0,2 s antes de que empiece el texto.
            if (ctx.hay3D()) { SARD.Niebla.a(NIEBLA_DISIPADA, 2.0); }

            gsap.set(letras, { autoAlpha: 0, y: 14 });
            gsap.set([s.querySelector('.s16__cta'), s.querySelector('.s16__pie'), s.querySelector('.s16__repetir')], { autoAlpha: 0 });

            ctx.timeline = gsap.timeline()
                // Texto letra a letra: empieza a 1,8 s (0,2 s después de que la niebla llegue a su valor).
                .to(letras, { autoAlpha: 1, y: 0, duration: 0.5, ease: 'power2.out', stagger: STAGGER }, 1.8)
                .to(s.querySelector('.s16__cta'), { autoAlpha: 1, duration: 0.6, ease: 'power1.out' }, '>-0.1')
                .to(s.querySelector('.s16__pie'), { autoAlpha: 1, duration: 0.8, ease: 'power1.out' }, '<')
                .to(s.querySelector('.s16__repetir'), { autoAlpha: 1, duration: 0.6 }, '<');

            // Deriva de cámara de 8 s hacia z 18. Arranca cuando termina el encuadre cerrado (1,2 s)
            // para no pelear con él; el valor final se calcula al arrancar (siempre se aleja).
            if (ctx.hay3D()) {
                const camara = SARD.Motor.camara;
                SARD.Anim.loop(ctx, gsap.to(camara.position, {
                    z: () => Math.max(DERIVA_Z, camara.position.z + 3), duration: DERIVA_DUR, delay: 1.25, ease: 'none',
                    onUpdate: () => {
                        // La linterna se mantiene en el mismo punto de pantalla (encima del mensaje)
                        // aunque la cámara se aleje: el objetivo baja en proporción a la distancia.
                        const l = SARD.Faro.linterna.getWorldPosition(new THREE.Vector3());
                        camara.lookAt(l.x, l.y - 0.26 * camara.position.distanceTo(l), l.z);
                    },
                }));
            }
        },

        /** Estado final: mensaje y pie visibles, niebla disipada, sin movimiento. */
        estadoFinal(ctx) {
            const s = ctx.seccion;
            const letras = ctx.split ? ctx.split.chars : [s.querySelector('.s16__mensaje')];
            gsap.set(letras, { autoAlpha: 1, y: 0, clearProps: 'transform' });
            gsap.set([s.querySelector('.s16__cta'), s.querySelector('.s16__pie'), s.querySelector('.s16__repetir')], { autoAlpha: 1 });
            if (ctx.hay3D()) { SARD.Niebla.a(NIEBLA_DISIPADA, 0); }
            ctx.timeline?.progress(1, true).pause();
        },

        /** «Siguiente» en el cierre abre el panel de la demo en vivo (no hay más escenas). */
        alSiguiente() {
            SARD.HUD.abrirDemo();
            return true;
        },

        /** Al salir, libera el SplitText para no dejar <div> de caracteres huérfanos en el DOM. */
        salir(ctx) {
            if (ctx.split) {
                try { ctx.split.revert(); } catch (e) { /* ignora */ }
                ctx.split = null;
            }
        },
    });
})(window.SARD = window.SARD || {});
