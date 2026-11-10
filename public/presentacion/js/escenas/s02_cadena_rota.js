/*
 * S02_cadena_rota — «La cadena de seis fallas» (STORYBOARD §S02 · 45 s).
 *
 * Objetivo: que el jurado pueda enumerar al menos tres fallas, y vea que cada una tiene su
 * módulo en SARD (tooltip de cada eslabón).
 *
 * Animación → qué explica:
 *   · Seis nodos unidos por un cable tenso sobre el mar (sin relieve que compita con la lista).
 *   · Al pulsar un eslabón, su tramo de cable se afloja (opacidad 1 → 0,25) y los dos nodos se
 *     separan 0,4 m: ahí se rompe la cadena. ACUMULATIVO: se pueden romper varios.
 *   · La fila HTML y el nodo 3D reciben el estado en el mismo frame (un único índice i).
 * Color: la rotura usa --sard-down (#ef4444, «caído»), no el acento: el #ff4433 aparece por
 * primera vez en S03 (desviación anotada).
 * Salida: el cable se recompone (los tramos vuelven a opacidad 1).
 */
(function (SARD) {
    'use strict';

    const { Util } = SARD;
    const N = 6;
    const SEPARACION = 3;
    // Cadena centrada en x = 0; la zona libre del motor la coloca junto a la tarjeta.
    const ORIGEN = { x: -((N - 1) * SEPARACION) / 2, y: 6, z: -30 };
    const COLOR_CAIDO = 0xef4444;

    function posicion(i) {
        return new THREE.Vector3(ORIGEN.x + i * SEPARACION, ORIGEN.y, ORIGEN.z);
    }

    function crearCadena() {
        const nodos = [];
        const cables = [];
        for (let i = 0; i < N; i++) {
            const g = new THREE.Group();
            g.position.copy(posicion(i));
            const esfera = new THREE.Mesh(SARD.M3.geo('Sphere', [0.6, 20, 14]),
                new THREE.MeshStandardMaterial({ color: 0x3e3e3a, emissive: 0x000000, roughness: 0.5, metalness: 0.2 }));
            const anillo = new THREE.Mesh(SARD.M3.geo('Torus', [0.95, 0.04, 8, 48]),
                new THREE.MeshBasicMaterial({ color: 0x62605b, transparent: true, opacity: 0.8 }));
            anillo.rotation.x = Math.PI / 2;
            g.add(esfera, anillo);
            g.userData = { esfera, anillo, base: g.position.x };
            nodos.push(g);
        }
        for (let i = 0; i < N - 1; i++) {
            const largo = SEPARACION - 1.2;
            const cable = new THREE.Mesh(SARD.M3.geo('Cylinder', [0.07, 0.07, 1, 8]),
                new THREE.MeshStandardMaterial({ color: 0xa1a09a, emissive: 0x3e3e3a, transparent: true, opacity: 1, roughness: 0.6 }));
            cable.rotation.z = Math.PI / 2;
            cable.scale.y = largo;
            cable.position.copy(posicion(i)).add(new THREE.Vector3(SEPARACION / 2, 0, 0));
            cables.push(cable);
        }
        return { nodos, cables };
    }

    /** Tramo que se rompe con el eslabón i: el que va a su derecha (el último usa el de su izquierda). */
    const tramo = (i) => Math.min(i, N - 2);

    SARD.Escenas.definir({
        id: 'S02_cadena_rota',

        escenario: {
            // Plano cenital suave (pitch ≈ 55°) sobre el mar: fondo sin relieve.
            // ancho: la cadena (15 m + nodos) debe caber entera en el hueco junto a la tarjeta.
            encuadre: { pos: [0, 26, -16], objetivo: [0, 6, -30], ancho: 18 },
            niebla: 0.022,
            linterna: 1.4,
            baliza: false,
            duracionCamara: 1.6,
        },

        plantilla(t, escena) {
            const filas = t.eslabones.map((e, i) => {
                const idDetalle = `s02-detalle-${i}`;
                const boton = Util.el('button', {
                    type: 'button', class: 's02__eslabon', 'aria-pressed': 'false', 'aria-expanded': 'false', 'aria-controls': idDetalle,
                    dataset: { i: String(i) },
                    on: { click: () => this.alternar(escena.ctx, i) },
                }, [
                    Util.el('span', { class: 's02__num dato', text: String(i + 1) }),
                    Util.el('span', { class: 's02__falla', text: e.falla }),
                ]);
                return Util.el('li', { class: 's02__fila' }, [
                    boton,
                    Util.el('p', { id: idDetalle, class: 's02__detalle', hidden: true, text: e.consecuencia }),
                ]);
            });
            return Util.el('section', { class: 's02' }, [
                Util.el('div', { class: 'panel panel--izquierda panel--compacto pila s02__panel' }, [
                    Util.el('h1', { class: 's02__h1', text: t.h1 }),
                    Util.el('ol', { class: 's02__lista' }, filas),
                    Util.el('button', {
                        type: 'button', class: 'btn btn--secundario s02__atajo', text: t.atajo,
                        on: { click: () => SARD.Router.irA('S09_arquitectura') },
                    }),
                ]),
            ]);
        },

        cargar(ctx) {
            // Tooltip con el módulo de SARD que resuelve cada falla.
            ctx.seccion.querySelectorAll('.s02__eslabon').forEach((b, i) => {
                SARD.Tooltip.adjuntar(b, ctx.textos.eslabones[i].modulo, ctx.senal);
            });
            const g = ctx.grupo3D('cadena');
            if (!g) { return; }
            const { nodos, cables } = crearCadena();
            ctx.nodos = nodos;
            ctx.cables = cables;
            g.add(...nodos, ...cables);
        },

        activar(ctx) {
            const s = ctx.seccion;
            // Estado acumulativo (INTERACTION_SPEC §2.5): al volver, los tramos rotos siguen rotos.
            s.querySelectorAll('.s02__eslabon[aria-pressed="true"]').forEach((b) => this.pintar3D(ctx, Number(b.dataset.i), true, true));
            SARD.Anim.entrar(ctx, () => {
                const tl = gsap.timeline({ paused: true })
                    .from(s.querySelector('.s02__h1'), { y: -16, autoAlpha: 0, duration: 0.6, ease: 'power2.out' })
                    .from(s.querySelectorAll('.s02__fila'), { x: -12, autoAlpha: 0, duration: 0.45, ease: 'back.out(1.8)', stagger: 0.12 }, '-=0.2')
                    .from(s.querySelector('.s02__atajo'), { autoAlpha: 0, duration: 0.4 }, '-=0.1');
                if (ctx.hay3D()) {
                    // Los nodos aparecen con su fila; el cable se tensa después (escala del tramo).
                    tl.from(ctx.nodos.map((n) => n.scale), { x: 0.4, y: 0.4, z: 0.4, duration: 0.45, ease: 'back.out(1.8)', stagger: 0.12 }, 0.4)
                        .from(ctx.cables.map((c) => c.scale), { y: 0.01, duration: 0.5, ease: 'power2.out', stagger: 0.08 }, 1.0);
                }
                return tl;
            });
        },

        estadoFinal(ctx) { ctx.timeline?.progress(1).pause(); },

        /** Alterna un eslabón (acumulativo). Feedback inmediato: misma clase en HTML y 3D. */
        alternar(ctx, i) {
            const boton = ctx.seccion.querySelector(`.s02__eslabon[data-i="${i}"]`);
            const activo = boton.getAttribute('aria-pressed') !== 'true';
            boton.setAttribute('aria-pressed', String(activo));
            boton.setAttribute('aria-expanded', String(activo));
            const detalle = ctx.seccion.querySelector(`#s02-detalle-${i}`);
            detalle.hidden = !activo;
            if (activo && !ctx.reducido()) {
                gsap.fromTo(detalle, { autoAlpha: 0, y: 8 }, { autoAlpha: 1, y: 0, duration: 0.3, ease: 'power2.out' });
            }
            this.pintar3D(ctx, i, activo, false);
            ctx.anunciar(`${ctx.textos.eslabones[i].falla}: ${activo ? ctx.textos.eslabones[i].consecuencia : ''}`);
        },

        /** Rompe (o recompone) el tramo del eslabón i en 3D. */
        pintar3D(ctx, i, roto, inmediato) {
            if (!ctx.nodos) { return; }
            const d = inmediato || ctx.reducido() ? 0 : 1;
            const nodo = ctx.nodos[i];
            const t = tramo(i);
            gsap.to(nodo.scale, { x: roto ? 1.15 : 1, y: roto ? 1.15 : 1, z: roto ? 1.15 : 1, duration: 0.18 * d, ease: 'power2.out' });
            const color = new THREE.Color(roto ? COLOR_CAIDO : 0x000000);
            gsap.to(nodo.userData.esfera.material.emissive, { r: color.r, g: color.g, b: color.b, duration: 0.18 * d });
            // overwrite: la recomposición de salida, si quedó pendiente, no pisa el estado restaurado.
            gsap.to(ctx.cables[t].material, { opacity: roto ? 0.25 : 1, duration: 0.25 * d, overwrite: true });
            // Las dos esferas del tramo se separan 0,4 m (0,2 cada una). Se calcula desde la base
            // contando cuántos eslabones rompen cada tramo, para que sea acumulativo y reversible.
            const rotos = new Set(Array.from(ctx.seccion.querySelectorAll('.s02__eslabon[aria-pressed="true"]')).map((b) => tramo(Number(b.dataset.i))));
            ctx.nodos.forEach((n, k) => {
                let desplazamiento = 0;
                if (rotos.has(k - 1)) { desplazamiento += 0.2; }
                if (rotos.has(k)) { desplazamiento -= 0.2; }
                gsap.to(n.position, { x: n.userData.base + desplazamiento, duration: 0.4 * d, ease: 'power2.out' });
            });
        },

        /** Salida: el cable se recompone mientras la escena se funde. */
        salir(ctx) {
            if (!ctx.cables || ctx.reducido()) { return; }
            ctx.cables.forEach((c) => gsap.to(c.material, { opacity: 1, duration: 0.4 }));
        },
    });
})(window.SARD = window.SARD || {});
