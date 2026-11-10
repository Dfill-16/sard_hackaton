/*
 * S09_arquitectura — «Los cinco nodos y el canal» (STORYBOARD §S09 · 75 s).
 *
 * Objetivo: que el jurado pueda describir la topología con sus palabras.
 *
 * Animación → qué explica:
 *   · A, CANAL, B (el faro, líder), C (torre gemela, seguidor) y D (unidad de campo) aparecen
 *     escalonados; las líneas se encienden después: primero los procesos, luego cómo se hablan.
 *   · Reportes A → CANAL → B: pasan por el canal (gris).
 *   · Latido B → C cada 1 s (latido_s real) y réplica del log: van DIRECTO, fuera del canal,
 *     precisamente porque el canal es lo que falla.
 *   · Alerta B → D en acento: solo sale lo que una persona aprobó.
 * Interacción: hover/foco en cada etiqueta → ficha (rol, puerto, URL, qué corre); clic en CANAL
 * → S07; arrastre de cámara ±25° y zoom 18–45 m.
 * El color de las líneas nunca va solo: la leyenda de la tarjeta lo nombra.
 */
(function (SARD) {
    'use strict';

    const { Util } = SARD;
    const COLORES = { reporte: 0xa1a09a, latido: 0xc8d8e8, alerta: 0xff4433 };

    SARD.Escenas.definir({
        id: 'S09_arquitectura',

        escenario: { encuadre: null, niebla: 0.018, linterna: 2.4, baliza: true },

        plantilla(t) {
            const fila = (n) => Util.el('li', { class: 's09__nodo' }, [
                Util.el('span', { class: 's09__id dato', text: n.id }),
                Util.el('span', { class: 'caption dato', text: n.puerto }),
                Util.el('span', { class: 's09__rol', text: n.rol }),
                // La ficha completa también vive aquí: sin 3D no hay etiquetas ancladas.
                Util.el('span', { class: 's09__extra sr-only', text: `${n.url} · ${n.corre}` }),
            ]);
            return Util.el('section', { class: 's09' }, [
                Util.el('div', { class: 'panel panel--izquierda panel--compacto pila s09__panel' }, [
                    Util.el('h1', { class: 's09__h1', text: t.h1 }),
                    Util.el('ul', { class: 's09__nodos' }, t.nodos.map(fila)),
                    Util.el('ul', { class: 's09__leyenda' }, t.leyenda.map(([clave, texto]) => Util.el('li', {}, [
                        Util.el('span', { class: `s09__muestra s09__muestra--${clave}`, 'aria-hidden': 'true' }),
                        Util.el('span', { class: 'caption', text: texto }),
                    ]))),
                ]),
            ]);
        },

        cargar(ctx) {
            const t = ctx.textos;
            // Botones anclados: uno por nodo, con su ficha como tooltip (también en aria).
            ctx.chips = t.nodos.map((n) => {
                const esCanal = n.id === 'CANAL';
                const b = Util.el('button', {
                    type: 'button', class: `ancla ancla--chip s09__chip s09__chip--${n.id}`,
                    'aria-label': `Nodo ${n.id}`,
                    text: n.id === 'B' ? `B · ${t.lider}` : (n.id === 'C' ? `C · ${t.seguidor}` : n.id),
                });
                SARD.Tooltip.adjuntar(b, Util.plantilla(t.nodoFicha, n), ctx.senal);
                if (esCanal) { ctx.escuchar(b, 'click', () => SARD.Router.irA('S07_canal')); }
                return b;
            });

            const g = ctx.grupo3D('topologia');
            if (!g) { return; }
            const T = SARD.Actores.topologia();
            ctx.T = T;

            const nodoA = SARD.Actores.crearNodoA();
            nodoA.position.copy(T.A);
            const canal = new THREE.Mesh(SARD.M3.geo('Torus', [2.2, 0.06, 8, 64]), new THREE.MeshStandardMaterial({ color: 0x3e3e3a, emissive: 0xfbbf24, emissiveIntensity: 0.25, transparent: true, opacity: 0.8 }));
            canal.rotation.x = Math.PI / 2;
            canal.position.copy(T.CANAL);
            const gemela = SARD.Faro.crearGemela(T.C);
            // C es seguidor: linterna tenue y baliza apagada.
            gemela.linterna.material.emissiveIntensity = 0.6;
            gemela.resplandor.material.opacity = 0.12;
            gemela.balizaMesh.material.emissiveIntensity = 0;
            const vehiculo = SARD.Actores.crearVehiculo();
            vehiculo.position.copy(T.D);
            vehiculo.rotation.y = -0.6;

            const lineas = [
                SARD.Actores.crearLinea(T.A, T.CANAL, COLORES.reporte, { arco: 1 }),
                SARD.Actores.crearLinea(T.CANAL, T.B, COLORES.reporte, { arco: 1.5 }),
                SARD.Actores.crearLinea(T.CANAL, T.C, COLORES.reporte, { arco: 3, opacidad: 0.3 }),
                SARD.Actores.crearLinea(T.B, T.C, COLORES.latido, { arco: 4, opacidad: 0.7 }),
                SARD.Actores.crearLinea(T.B, T.D, COLORES.alerta, { arco: 3, opacidad: 0.7 }),
            ];
            ctx.curvaLatido = lineas[3].curva;
            ctx.curvasReporte = [lineas[0].curva, lineas[1].curva];
            ctx.pulsoLatido = SARD.Actores.crearPulso(COLORES.latido, 1.3);
            ctx.pulsoReplica = SARD.Actores.crearPulso(COLORES.latido, 0.7);
            ctx.pulsoReporte = SARD.Actores.crearPulso(0xededec, 1);

            ctx.objetos = { A: nodoA, CANAL: canal, B: SARD.Faro.linterna, C: gemela.torre, D: vehiculo };
            ctx.nodos3D = [nodoA, canal, gemela.torre, vehiculo];
            ctx.lineas = lineas.map((l) => l.tubo);
            g.add(nodoA, canal, gemela.torre, vehiculo, ...ctx.lineas, ctx.pulsoLatido, ctx.pulsoReplica, ctx.pulsoReporte);
        },

        activar(ctx) {
            const s = ctx.seccion;
            if (ctx.hay3D()) {
                const T = ctx.T;
                // ancho: de A a D con margen para sus etiquetas; alto: la torre B entera con su etiqueta.
                const centro = [(T.A.x + T.D.x) / 2, 7, (T.C.z + T.A.z) / 2];
                const tween = SARD.Motor.encuadrar({ pos: [centro[0] + 2, 26, centro[2] + 32], objetivo: centro, ancho: 42, alto: 24 }, { duracion: 1.6 });
                // Órbita manual con límites (±25°, elevación 12–60°, zoom 18–45 m) al terminar el encuadre.
                const orbitar = () => SARD.Motor.orbitar({ azimut: (25 * Math.PI) / 180, polarMin: (30 * Math.PI) / 180, polarMax: (78 * Math.PI) / 180, distanciaMin: 18, distanciaMax: 45 });
                if (tween) { tween.eventCallback('onComplete', orbitar); } else { orbitar(); }
                SARD.Anim.loop(ctx, SARD.Motor.derivaOrbital(4, 26, 1.7));
                // Etiqueta sobre cada nodo (los chips siguen el orden de textos.nodos). B se ancla a
                // la linterna del faro; C, a la base de la gemela (+15,5 m: encima de su linterna).
                const alturaEtiqueta = { A: 3, B: 2.2, C: 15.5, D: 3, CANAL: 1.5 };
                ctx.textos.nodos.forEach((n, i) => ctx.anclar(ctx.objetos[n.id], ctx.chips[i], { desplazamientoY: alturaEtiqueta[n.id] }));

                if (!ctx.reducido()) {
                    // Latido B → C cada 1 s (0,45 s de trayecto + 0,55 s de espera) y réplica del log.
                    const viaje = (pulso, curva, retraso) => {
                        const p = { t: 0 };
                        return gsap.timeline({ repeat: -1, repeatDelay: 0.55, delay: retraso })
                            .set(pulso.material, { opacity: 1 })
                            .to(p, { t: 1, duration: 0.45, ease: 'none', onUpdate: () => pulso.position.copy(curva.getPoint(p.t)) })
                            .set(pulso.material, { opacity: 0 });
                    };
                    SARD.Anim.loop(ctx, viaje(ctx.pulsoLatido, ctx.curvaLatido, 2.2));
                    SARD.Anim.loop(ctx, viaje(ctx.pulsoReplica, ctx.curvaLatido, 2.7));
                    // Un reporte cruza A → CANAL → B cada 2,4 s.
                    const r = { t: 0 };
                    SARD.Anim.loop(ctx, gsap.timeline({ repeat: -1, repeatDelay: 0.6, delay: 2.4 })
                        .set(ctx.pulsoReporte.material, { opacity: 1 })
                        .to(r, { t: 1, duration: 0.9, ease: 'none', onUpdate: () => ctx.pulsoReporte.position.copy(ctx.curvasReporte[0].getPoint(r.t)) })
                        .set(r, { t: 0 })
                        .to(r, { t: 1, duration: 0.9, ease: 'none', onUpdate: () => ctx.pulsoReporte.position.copy(ctx.curvasReporte[1].getPoint(r.t)) })
                        .set(ctx.pulsoReporte.material, { opacity: 0 }));
                }
            }

            SARD.Anim.entrar(ctx, () => {
                const tl = gsap.timeline({ paused: true })
                    .from(s.querySelector('.s09__h1'), { y: -12, autoAlpha: 0, duration: 0.55, ease: 'power2.out' })
                    .from(s.querySelectorAll('.s09__nodo'), { autoAlpha: 0, x: -10, duration: 0.35, stagger: 0.14 }, '-=0.15')
                    .from(s.querySelectorAll('.s09__leyenda li'), { autoAlpha: 0, duration: 0.3, stagger: 0.1 }, '-=0.2');
                if (ctx.nodos3D) {
                    tl.from(ctx.nodos3D.map((n) => n.scale), { x: 0.55, y: 0.55, z: 0.55, duration: 0.5, ease: 'back.out(1.6)', stagger: 0.14 }, 0.4)
                        .from(ctx.lineas.map((l) => l.material), { opacity: 0, duration: 0.6, ease: 'power1.out', stagger: 0.1 }, 1.0)
                        .from(ctx.chips, { opacity: 0, duration: 0.3, stagger: 0.1 }, 1.0);
                }
                return tl;
            });
        },

        estadoFinal(ctx) { ctx.timeline?.progress(1, true).pause(); },
    });
})(window.SARD = window.SARD || {});
