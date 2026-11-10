/*
 * S03_promesa — «SARD GAMMA TECH» (STORYBOARD §S03 · 60 s).
 *
 * Objetivo: que el jurado repita la promesa con sus palabras.
 *
 * Animación → qué explica:
 *   · La niebla baja de 0,030 a 0,012 ANTES de que aparezca el texto: la promesa despeja la
 *     incertidumbre y luego se nombra.
 *   · La linterna sube de 2,0 a 3,2 y la baliza #ff4433 se enciende: primera aparición del
 *     acento en toda la presentación (la solución).
 *   · Dolly de aproximación al faro (4 s): vamos hacia la respuesta.
 *   · El haz barre el mar: el sistema vigila aunque no haya señal.
 *   · La silueta del operador levanta la cabeza 4° (único microgesto): hay una persona.
 */
(function (SARD) {
    'use strict';

    const { Util } = SARD;

    SARD.Escenas.definir({
        id: 'S03_promesa',

        escenario: { encuadre: null, niebla: 0.012, linterna: 3.2, baliza: true, haz: true, duracionAmbiente: 2.2 },

        plantilla(t) {
            const linea = (clase, texto) => Util.el('span', { class: 'mascara' }, Util.el('span', { class: `mascara__linea ${clase}`, text: texto }));
            const idDetalle = 's03-detalle';
            return Util.el('section', { class: 's03' }, [
                Util.el('div', { class: 'panel panel--izquierda pila s03__tarjeta' }, [
                    Util.el('h1', { class: 's03__promesa' }, [linea('s03__linea1', t.linea1), linea('s03__linea2', t.linea2)]),
                    Util.el('p', { class: 's03__nombre' }, [`${t.marca} `, Util.el('span', { class: 'acento', text: t.marcaAcento })]),
                ]),
                Util.el('div', { class: 's03__dato-caja' }, [
                    Util.el('button', {
                        type: 'button', class: 's03__dato', 'aria-expanded': 'false', 'aria-controls': idDetalle, text: t.dato,
                        on: {
                            click: (e) => {
                                const b = e.currentTarget;
                                const abierto = b.getAttribute('aria-expanded') !== 'true';
                                b.setAttribute('aria-expanded', String(abierto));
                                const d = document.getElementById(idDetalle);
                                d.hidden = !abierto;
                                if (abierto && !SARD.Estado.get('reduccionMovimiento')) {
                                    gsap.fromTo(d, { autoAlpha: 0, y: 6 }, { autoAlpha: 1, y: 0, duration: 0.25, ease: 'power2.out' });
                                }
                            },
                        },
                    }),
                    Util.el('p', { id: idDetalle, class: 's03__detalle', hidden: true, text: t.detalle }),
                ]),
            ]);
        },

        cargar(ctx) {
            const g = ctx.grupo3D('operador');
            if (!g) { return; }
            const op = SARD.Actores.crearOperador();
            // Junto a la base del faro, de espaldas a la cámara, mirando su panel y la torre.
            const local = new THREE.Vector3(2.2, 1.2, 2.6);
            op.grupo.position.copy(SARD.Faro.grupo.localToWorld(local.clone()));
            op.grupo.rotation.y = Math.atan2(local.x, local.z);
            g.add(op.grupo);
            ctx.operador = op;
        },

        activar(ctx) {
            const s = ctx.seccion;
            if (ctx.hay3D()) {
                const base = SARD.Faro.grupo.position;
                const objetivo = [base.x, base.y + 8, base.z]; // punto medio de la torre
                SARD.Motor.encuadrar({ pos: [0, 20, 60], objetivo }, { duracion: 0 });
                SARD.Motor.encuadrar({ pos: [0, 11, 26], objetivo, alto: 18 }, { duracion: ctx.reducido() ? 0 : 4, ease: 'power2.inOut' });
                // El haz barre el mar (12 s por vuelta), levemente inclinado hacia el agua.
                SARD.Faro.haz.rotation.z = -0.08;
                SARD.Anim.loop(ctx, ctx.reducido() ? null : gsap.to(SARD.Faro.haz.rotation, { y: `+=${Math.PI * 2}`, duration: 12, ease: 'none', repeat: -1 }));
                ctx.operador.cabeza.rotation.x = 0;
            }

            SARD.Anim.entrar(ctx, () => {
                const tl = gsap.timeline({ paused: true })
                    .from(s.querySelector('.s03__linea1'), { yPercent: 100, autoAlpha: 0, duration: 0.8, ease: 'power3.out' }, 0.6)
                    .from(s.querySelector('.s03__linea2'), { yPercent: 100, autoAlpha: 0, duration: 0.8, ease: 'power3.out' }, 0.85)
                    .from(s.querySelector('.s03__nombre'), { autoAlpha: 0, scale: 0.94, transformOrigin: 'left center', duration: 0.7, ease: 'power2.out' }, 1.3)
                    .from(s.querySelector('.s03__dato'), { autoAlpha: 0, y: 10, duration: 0.5, ease: 'power1.out' }, 1.7);
                if (ctx.operador) {
                    // Microgesto único: la persona levanta la cabeza 4° (0,07 rad).
                    tl.to(ctx.operador.cabeza.rotation, { x: -0.07, duration: 0.9, ease: 'power2.inOut' }, 1.9);
                }
                return tl;
            });
        },

        // El haz no necesita apagarse aquí: el escenario de la escena siguiente lo oculta (haz: false).
        estadoFinal(ctx) { ctx.timeline?.progress(1).pause(); },
    });
})(window.SARD = window.SARD || {});
