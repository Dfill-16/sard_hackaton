/*
 * S04_principios — «Cinco reglas que no se rompen» (STORYBOARD §S04 · 75 s).
 *
 * Objetivo: que el jurado recuerde al menos la segunda regla («la IA no decide»).
 *
 * Animación → qué explica:
 *   · Cinco placas en semicírculo alrededor de la base del faro: las reglas sostienen el faro.
 *   · Al activar una regla, su placa sube 0,15 m, la luz de acento se desplaza hasta ella y el
 *     faro emite un destello: 1 destello = 1 regla.
 *   · La tarjeta HTML y su placa comparten el índice i y cambian en el mismo frame.
 * Navegación especial (INTERACTION_SPEC §2.7): → / Espacio recorren las 5 reglas y solo
 * después pasan a la escena siguiente; ← las recorre hacia atrás.
 */
(function (SARD) {
    'use strict';

    const { Util } = SARD;
    const RADIO = 4.2;
    const ANGULOS = [-60, -30, 0, 30, 60].map((g) => (g * Math.PI) / 180);
    const ALTURA_PLACA = 1.25;
    const POS_BALIZA = { x: 0, y: 2.3, z: 2.2 }; // posición de reposo (faro.js)

    function crearPlacas() {
        return ANGULOS.map((a) => {
            const placa = new THREE.Mesh(SARD.M3.geo('Box', [1.15, 0.14, 0.75]),
                new THREE.MeshStandardMaterial({ color: 0x1d1d1b, emissive: 0xff4433, emissiveIntensity: 0, roughness: 0.45, metalness: 0.6 }));
            placa.position.set(Math.sin(a) * RADIO, ALTURA_PLACA, Math.cos(a) * RADIO);
            placa.rotation.y = a;
            placa.castShadow = true;
            placa.receiveShadow = true;
            return placa;
        });
    }

    SARD.Escenas.definir({
        id: 'S04_principios',

        escenario: { encuadre: null, niebla: 0.014, linterna: 3.0, baliza: true },

        plantilla(t, escena) {
            const tarjetas = t.principios.map((p, i) => Util.el('li', {}, Util.el('button', {
                type: 'button', class: `tarjeta s04__tarjeta s04__tarjeta-${i}`, 'aria-pressed': 'false', dataset: { i: String(i) },
                on: { click: () => this.activarPrincipio(escena.ctx, i) },
            }, [
                Util.el('span', { class: 's04__icono', html: SARD.Iconos.html(p.icono) }),
                Util.el('span', { class: 'label s04__titulo', text: p.titulo }),
                Util.el('span', { class: 's04__apoyo', text: p.apoyo }),
                Util.el('span', { class: 's04__completa', text: p.completa }),
            ])));
            return Util.el('section', { class: 's04' }, [
                Util.el('div', { class: 'panel panel--ancho pila s04__panel' }, [
                    Util.el('div', { class: 's04__cabecera' }, [
                        Util.el('h1', { class: 's04__h1', text: t.h1 }),
                        Util.el('button', { type: 'button', class: 'btn btn--secundario s04__atajo', text: t.atajo, on: { click: () => SARD.Router.irA('S08_agentes') } }),
                    ]),
                    Util.el('ol', { class: 's04__rejilla' }, tarjetas),
                ]),
            ]);
        },

        cargar(ctx) {
            const g = ctx.grupo3D('placas');
            if (!g) { return; }
            // Mismo origen que el faro: la baliza (hija del faro) se mueve en coordenadas compartidas.
            g.position.copy(SARD.Faro.grupo.position);
            ctx.placas = crearPlacas();
            g.add(...ctx.placas);
        },

        activar(ctx) {
            const s = ctx.seccion;
            if (ctx.hay3D()) {
                const b = SARD.Faro.grupo.position;
                SARD.Motor.encuadrar({ pos: [b.x + 3, b.y + 8.5, b.z + 19], objetivo: [b.x, b.y + 4, b.z + 1.5], ancho: 12, alto: 10 }, { duracion: 1.6 });
                // Deriva de 1°: la imagen no se siente congelada.
                SARD.Anim.loop(ctx, SARD.Motor.derivaOrbital(1, 18, 1.7));
            }
            SARD.Anim.entrar(ctx, () => {
                const tl = gsap.timeline({ paused: true })
                    .from(s.querySelector('.s04__h1'), { y: -14, autoAlpha: 0, duration: 0.6, ease: 'power2.out' })
                    .from(s.querySelectorAll('.s04__tarjeta'), { y: 22, autoAlpha: 0, duration: 0.5, ease: 'power2.out', stagger: 0.09 }, '-=0.15')
                    .from(s.querySelector('.s04__atajo'), { autoAlpha: 0, duration: 0.4 }, '<0.3');
                if (ctx.placas) {
                    // Placas (3D) y tarjetas (HTML) entran en paralelo, con el mismo escalonado.
                    tl.from(ctx.placas.map((p) => p.scale), { x: 0.85, y: 0.85, z: 0.85, duration: 0.5, ease: 'back.out(1.6)', stagger: 0.09 }, 0.45);
                }
                return tl;
            });
            // Al volver, la regla activa conserva su estado (también en 3D).
            const paso = Number(s.dataset.paso ?? -1);
            if (paso >= 0) {
                this.activarPrincipio(ctx, paso, true);
            } else if (ctx.placas) {
                // La salida las hundió: vuelven a su altura de reposo.
                ctx.placas.forEach((p) => { gsap.killTweensOf(p.position); p.position.y = ALTURA_PLACA; });
            }
        },

        estadoFinal(ctx) { ctx.timeline?.progress(1).pause(); },

        /** Activa la regla i (una a la vez): tarjeta expandida + placa elevada + destello. */
        activarPrincipio(ctx, i, inmediato = false) {
            const s = ctx.seccion;
            const d = inmediato || ctx.reducido() ? 0 : 1;
            s.dataset.paso = String(i);
            s.querySelectorAll('.s04__tarjeta').forEach((b, k) => b.setAttribute('aria-pressed', String(k === i)));
            if (d) {
                gsap.fromTo(s.querySelector(`.s04__tarjeta-${i}`), { scale: 1 }, { scale: 1.03, duration: 0.18, ease: 'power2.out', yoyo: true, repeat: 1 });
            }
            ctx.anunciar(`${ctx.textos.principios[i].titulo}. ${ctx.textos.principios[i].completa}`);
            if (!ctx.placas) { return; }
            ctx.placas.forEach((p, k) => {
                const activa = k === i;
                gsap.to(p.position, { y: ALTURA_PLACA + (activa ? 0.15 : 0), duration: 0.35 * d, ease: 'power2.out', overwrite: true });
                gsap.to(p.material, { emissiveIntensity: activa ? 0.9 : 0, duration: 0.3 * d, overwrite: true });
            });
            const destino = ctx.placas[i].position;
            gsap.to(SARD.Luces.baliza.position, { x: destino.x, y: ALTURA_PLACA + 1.2, z: destino.z, duration: 0.45 * d, ease: 'power2.inOut', overwrite: true });
            if (d) {
                const reposo = SARD.Faro.resplandor.material.opacity;
                gsap.fromTo(SARD.Faro.resplandor.material, { opacity: 0.9 }, { opacity: reposo, duration: 0.45, ease: 'power2.out' });
            }
        },

        /** → recorre las reglas antes de cambiar de escena. */
        avanzarPaso(ctx) {
            const paso = Number(ctx.seccion.dataset.paso ?? -1);
            if (paso >= ctx.textos.principios.length - 1) { return false; }
            this.activarPrincipio(ctx, paso + 1);
            return true;
        },

        retrocederPaso(ctx) {
            const paso = Number(ctx.seccion.dataset.paso ?? -1);
            if (paso <= 0) { return false; }
            this.activarPrincipio(ctx, paso - 1);
            return true;
        },

        /** Salida: las placas se hunden y la luz de acento vuelve a la base del faro. */
        salir(ctx) {
            if (!ctx.placas) { return; }
            ctx.placas.forEach((p) => gsap.to(p.position, { y: ALTURA_PLACA - 0.4, duration: 0.4, ease: 'power1.in', overwrite: true }));
            gsap.to(SARD.Luces.baliza.position, { ...POS_BALIZA, duration: 0.4, overwrite: true });
        },
    });
})(window.SARD = window.SARD || {});
