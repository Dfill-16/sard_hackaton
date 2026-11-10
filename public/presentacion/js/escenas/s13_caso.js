/*
 * S13_caso — «Un derrumbe, de principio a fin» (STORYBOARD §S13 · 90 s). [DEMO en vivo al final]
 *
 * Objetivo: que el jurado vea el caso RESUELTO, no explicado.
 *
 * Seis fases encadenadas; → / Espacio avanzan una fase (INTERACTION_SPEC §2.7) y luego la
 * escena. La fase HTML activa y el objeto 3D de esa fase comparten un único índice.
 *   0 Reporte sin señal     → el punto del ciudadano tenue en la niebla densa.
 *   1 Llega a la central    → el paquete viaja al faro (líder).
 *   2 P1 · persona atrapada → el derrumbe se marca en rojo P1.
 *   3 3 dispositivos        → dos boyas más en el mismo punto; confianza 0 → 85 % (fórmula real).
 *   4 Una persona aprueba   → «Operador» + «Aprobar y alertar»; SOLO ENTONCES parte el vehículo.
 *   5 Campo confirma        → «Recibido» y la luz verde del acuse.
 * La niebla se despeja fase a fase (0,026 → 0,008): la resolución del caso es la disipación.
 *
 * Datos: punto del derrumbe de demostración; unidad y distancia calculadas como A6 sobre las
 * unidades de SemillaSeeder (datos/caso.js) → Bomberos Estación 2 · 400 m. Al volver a la
 * escena el caso se reinicia (es una demostración, tiene sentido repetirla).
 */
(function (SARD) {
    'use strict';

    const { Util } = SARD;
    const NIEBLA = [0.026, 0.022, 0.018, 0.014, 0.011, 0.008];
    const ESCALA = 50; // m por unidad de mundo (geometría relativa real entre el caso y la estación)
    const ROJO = 0xef4444;
    const INCIDENTE = { x: 2, z: 10 };
    const OFFSETS_BOYAS = [[-1, 0.6], [1.5, -1], [0.3, 1.8]]; // a menos de 300 m (6 u)

    function crearEscena3D() {
        const alto = (x, z) => SARD.Terreno.altura(x, z);
        const g = new THREE.Group();
        const centro = new THREE.Vector3(INCIDENTE.x, alto(INCIDENTE.x, INCIDENTE.z), INCIDENTE.z);

        // Derrumbe: rocas irregulares (icosaedros) + anillo P1 que se enciende en la fase 2.
        const azar = SARD.M3.aleatorio(13);
        for (let i = 0; i < 6; i++) {
            const r = new THREE.Mesh(SARD.M3.geo('Icosahedron', [0.5 + azar() * 0.5, 0]), SARD.M3.mat('roca'));
            const a = azar() * Math.PI * 2;
            r.position.set(centro.x + Math.cos(a) * azar() * 1.2, centro.y + 0.3, centro.z + Math.sin(a) * azar() * 1.2);
            r.rotation.set(azar() * 3, azar() * 3, azar() * 3);
            r.castShadow = true;
            g.add(r);
        }
        const anilloP1 = new THREE.Mesh(SARD.M3.geo('Ring', [2.2, 2.45, 48]), new THREE.MeshBasicMaterial({ color: ROJO, transparent: true, opacity: 0, side: THREE.DoubleSide, depthWrite: false }));
        anilloP1.rotation.x = -Math.PI / 2;
        anilloP1.position.copy(centro).add(new THREE.Vector3(0, 0.15, 0));
        g.add(anilloP1);

        // Boyas de reporte (una por teléfono).
        const boyas = OFFSETS_BOYAS.map(([dx, dz]) => {
            const b = new THREE.Group();
            b.position.set(centro.x + dx, alto(centro.x + dx, centro.z + dz) + 1.4, centro.z + dz);
            const esfera = new THREE.Mesh(SARD.M3.geo('Sphere', [0.3, 14, 10]), new THREE.MeshStandardMaterial({ color: 0x8fb8d8, emissive: 0x8fb8d8, emissiveIntensity: 1.2 }));
            const halo = new THREE.Sprite(new THREE.SpriteMaterial({ map: SARD.M3.textura('halo'), color: 0x8fb8d8, transparent: true, opacity: 0.5, blending: THREE.AdditiveBlending, depthWrite: false, fog: false }));
            halo.scale.set(2.6, 2.6, 1);
            b.add(esfera, halo);
            b.userData = { esfera, halo };
            g.add(b);
            return b;
        });

        // Unidad: posición relativa REAL de Bomberos Estación 2 respecto del punto del caso.
        const [recomendada] = SARD.datos.recomendar();
        const m = SARD.datos.enMetros(recomendada.lat, recomendada.lon);
        const base = new THREE.Vector3(centro.x + m.x / ESCALA, 0, centro.z + m.z / ESCALA);
        base.y = alto(base.x, base.z);
        const vehiculo = SARD.Actores.crearVehiculo();
        vehiculo.position.copy(base);
        vehiculo.lookAt(centro.x, base.y, centro.z);
        vehiculo.rotateY(-Math.PI / 2); // el frente del vehículo es +x
        const llegada = centro.clone().lerp(base, 1.6 / base.distanceTo(centro));
        llegada.y = alto(llegada.x, llegada.z);
        g.add(vehiculo);

        const paquete = SARD.Actores.crearPulso(0xc8d8e8, 1.4);
        g.add(paquete);
        return { g, centro, boyas, anilloP1, vehiculo, base, llegada, paquete, recomendada };
    }

    SARD.Escenas.definir({
        id: 'S13_caso',

        escenario: { encuadre: null, niebla: NIEBLA[0], linterna: 2.4, baliza: true, haz: true },

        zonaLibre(seccion, { arriba, alto, margen }) {
            const a = seccion.querySelector('.s13__izq').getBoundingClientRect();
            const b = seccion.querySelector('.s13__der').getBoundingClientRect();
            return { x: a.right + margen, y: arriba, w: Math.max(0, b.left - a.right - 2 * margen), h: alto };
        },

        plantilla(t, escena) {
            const [recomendada] = SARD.datos.recomendar();
            const propuesta = Util.plantilla(t.propuesta, { unidad: recomendada.nombre, distancia: Util.plantilla(t.metros, { m: recomendada.distancia_m }) });
            return Util.el('section', { class: 's13', dataset: { paso: '-1' } }, [
                Util.el('div', { class: 'panel panel--compacto pila s13__izq' }, [
                    Util.el('h1', { class: 's13__h1', text: t.h1 }),
                    Util.el('ol', { class: 's13__fases' }, t.fases.map((f, i) => Util.el('li', {}, Util.el('button', {
                        type: 'button', class: 's13__fase', dataset: { i: String(i) },
                        on: { click: () => this.irAFase(escena.ctx, i) },
                    }, [Util.el('span', { class: 'dato s13__num', text: String(i + 1) }), Util.el('span', { text: f })])))),
                    Util.el('div', { class: 's13__barra', 'aria-hidden': 'true' }, Util.el('div', { class: 's13__barra-relleno' })),
                    Util.el('p', { class: 'caption', text: t.demo }),
                ]),
                Util.el('div', { class: 'panel panel--compacto pila s13__der' }, [
                    Util.el('p', { class: 's13__detalle', 'aria-live': 'polite' }),
                    Util.el('div', { class: 's13__incidente', hidden: true }, [
                        Util.el('span', { class: 'prioridad prioridad--p1', text: 'P1' }),
                        Util.el('span', { class: 'dato s13__incidente-texto', text: t.incidente.replace(/^P1 · /, '') }),
                    ]),
                    Util.el('p', { class: 's13__confianza', hidden: true }, [
                        Util.el('span', { class: 'metrica__valor s13__conf-n', text: '0 %' }),
                        Util.el('span', { class: 'caption dato', text: t.formula }),
                    ]),
                    Util.el('div', { class: 's13__decision', hidden: true }, [
                        Util.el('p', { class: 's13__propuesta', text: propuesta }),
                        Util.el('label', { class: 's13__operador' }, [
                            Util.el('span', { class: 'label', text: t.operador }),
                            Util.el('span', { class: 's13__campo dato', dataset: { placeholder: t.operadorPlaceholder } }),
                        ]),
                        Util.el('div', { class: 's13__botones', 'aria-hidden': 'true' }, [
                            Util.el('span', { class: 'btn btn--primario s13__aprobar', text: t.aprobar }),
                            Util.el('span', { class: 'btn btn--secundario', text: t.descartar }),
                        ]),
                    ]),
                    Util.el('p', { class: 's13__ack', hidden: true }, [Util.el('span', { html: SARD.Iconos.html('check') }), Util.el('span', { text: t.recibido })]),
                    Util.el('p', { class: 'caption', text: t.rotulo }),
                ]),
            ]);
        },

        cargar(ctx) {
            const g = ctx.grupo3D('caso');
            if (!g) { return; }
            Object.assign(ctx, crearEscena3D());
            g.add(ctx.g);
        },

        activar(ctx) {
            const s = ctx.seccion;
            if (ctx.hay3D()) {
                const c = ctx.centro;
                const b = ctx.base;
                const medio = [(c.x + b.x) / 2, c.y + 1, (c.z + b.z) / 2];
                SARD.Motor.encuadrar({ pos: [medio[0] + 3, medio[1] + 13, medio[2] + 17], objetivo: medio, ancho: 16, alto: 8 }, { duracion: 1.6 });
            }
            // Es una demostración: al volver se reinicia desde la fase 1.
            this.reiniciar(ctx);
            SARD.Anim.entrar(ctx, () => gsap.timeline({ paused: true })
                .from(s.querySelector('.s13__h1'), { y: -12, autoAlpha: 0, duration: 0.5, ease: 'power2.out' })
                .from(s.querySelectorAll('.s13__fase'), { autoAlpha: 0, x: -12, duration: 0.3, stagger: 0.07 }, '-=0.15')
                .from(s.querySelector('.s13__der'), { autoAlpha: 0, x: 20, duration: 0.45 }, '-=0.2'));
        },

        estadoFinal(ctx) { ctx.timeline?.progress(1, true).pause(); },

        /** Estado base: ninguna fase, niebla densa, una sola boya tenue, unidad en su estación. */
        reiniciar(ctx) {
            const s = ctx.seccion;
            ctx.fase?.kill();
            s.dataset.paso = '-1';
            s.querySelectorAll('.s13__fase').forEach((b) => { b.classList.remove('hecha', 'activa'); b.removeAttribute('aria-current'); });
            s.querySelector('.s13__detalle').textContent = '';
            ['.s13__incidente', '.s13__confianza', '.s13__decision', '.s13__ack'].forEach((q) => { s.querySelector(q).hidden = true; });
            s.querySelector('.s13__campo').textContent = '';
            s.querySelector('.s13__conf-n').textContent = '0 %';
            gsap.set(s.querySelector('.s13__barra-relleno'), { scaleX: 0 });
            if (!ctx.boyas) { return; }
            ctx.boyas.forEach((b, i) => { b.visible = i === 0; b.userData.halo.material.opacity = 0.12; });
            ctx.anilloP1.material.opacity = 0;
            gsap.killTweensOf(ctx.vehiculo.position);
            ctx.vehiculo.position.copy(ctx.base);
            ctx.paquete.material.opacity = 0;
            SARD.Luces.estado.intensity = 0;
        },

        /** Marca la fase i como activa (y las anteriores como hechas). */
        marcar(ctx, i) {
            const s = ctx.seccion;
            s.dataset.paso = String(i);
            s.querySelectorAll('.s13__fase').forEach((b, k) => {
                b.classList.toggle('hecha', k < i);
                b.classList.toggle('activa', k === i);
                if (k === i) { b.setAttribute('aria-current', 'step'); } else { b.removeAttribute('aria-current'); }
            });
            s.querySelector('.s13__detalle').textContent = ctx.textos.detalles[i];
            const relleno = s.querySelector('.s13__barra-relleno');
            gsap.to(relleno, { scaleX: (i + 1) / 6, duration: ctx.reducido() ? 0 : 0.4, ease: 'power2.out' });
        },

        /**
         * Construye la animación de la fase i (inmediato = estado final sin animación).
         * @returns {gsap.core.Timeline}
         */
        fase(ctx, i, inmediato) {
            const s = ctx.seccion;
            const t = ctx.textos;
            const d = (x) => (inmediato || ctx.reducido() ? 0 : x);
            const tl = gsap.timeline();
            const hay3D = !!ctx.boyas;
            this.marcar(ctx, i);
            if (hay3D) { tl.call(() => SARD.Niebla.a(NIEBLA[i], d(0.7)), null, 0); }
            if (i === 0 && hay3D) {
                tl.to(ctx.boyas[0].userData.halo.material, { opacity: 0.2, duration: d(0.5) }, 0);
            }
            if (i === 1 && hay3D) {
                const desde = ctx.boyas[0].position.clone();
                const hasta = SARD.Faro.linterna.getWorldPosition(new THREE.Vector3());
                const curva = new THREE.QuadraticBezierCurve3(desde, desde.clone().lerp(hasta, 0.5).add(new THREE.Vector3(0, 6, 0)), hasta);
                const v = { t: 0 };
                tl.to(ctx.boyas[0].userData.halo.material, { opacity: 0.5, duration: d(0.4) }, 0)
                    .set(ctx.paquete.material, { opacity: inmediato ? 0 : 1 }, 0)
                    .to(v, { t: 1, duration: d(0.9), ease: 'none', onUpdate: () => ctx.paquete.position.copy(curva.getPoint(v.t)) }, 0.1)
                    .set(ctx.paquete.material, { opacity: 0 });
            }
            if (i === 2) {
                tl.call(() => { s.querySelector('.s13__incidente').hidden = false; }, null, 0)
                    .fromTo(s.querySelector('.s13__incidente .prioridad'), { scale: 0.6, autoAlpha: 0 }, { scale: 1, autoAlpha: 1, duration: d(0.35), ease: 'back.out(2)' }, 0);
                if (hay3D) { tl.to(ctx.anilloP1.material, { opacity: 0.9, duration: d(0.4) }, 0); }
            }
            if (i === 3) {
                const conf = { n: 0 };
                const objetivo = Math.round(SARD.datos.confianza(SARD.datos.caso.incertidumbre, SARD.datos.caso.dispositivos) * 100);
                tl.call(() => { s.querySelector('.s13__confianza').hidden = false; }, null, 0)
                    .to(conf, { n: objetivo, duration: d(0.7), ease: 'power1.out', snap: { n: 1 }, onUpdate: () => { s.querySelector('.s13__conf-n').textContent = `${conf.n} %`; } }, 0.2);
                if (hay3D) {
                    ctx.boyas.slice(1).forEach((b, k) => {
                        tl.call(() => { b.visible = true; }, null, k * 0.25)
                            .fromTo(b.scale, { x: 0.2, y: 0.2, z: 0.2 }, { x: 1, y: 1, z: 1, duration: d(0.5), ease: 'power2.out' }, k * 0.25);
                    });
                }
            }
            if (i === 4) {
                const nombre = { n: 0 };
                tl.call(() => { s.querySelector('.s13__decision').hidden = false; }, null, 0)
                    // El operador escribe su nombre…
                    .to(nombre, { n: t.operadorDemo.length, duration: d(0.9), ease: 'none', snap: { n: 1 }, onUpdate: () => { s.querySelector('.s13__campo').textContent = t.operadorDemo.slice(0, nombre.n); } }, 0.2)
                    // …pulsa «Aprobar y alertar»…
                    .fromTo(s.querySelector('.s13__aprobar'), { scale: 1 }, { scale: 1.06, duration: d(0.16), ease: 'power2.out', yoyo: true, repeat: inmediato ? 0 : 1 }, '>-0.1');
                // …y SOLO ENTONCES parte la unidad.
                if (hay3D) { tl.to(ctx.vehiculo.position, { x: ctx.llegada.x, y: ctx.llegada.y, z: ctx.llegada.z, duration: d(1.4), ease: 'power1.inOut' }, '>'); }
            }
            if (i === 5) {
                tl.call(() => { s.querySelector('.s13__ack').hidden = false; }, null, 0)
                    .fromTo(s.querySelector('.s13__ack'), { autoAlpha: 0.3 }, { autoAlpha: 1, duration: d(0.35) }, 0);
                if (hay3D) {
                    tl.call(() => { SARD.Luces.estado.position.copy(ctx.vehiculo.position).add(new THREE.Vector3(0, 3, 0)); }, null, 0)
                        .to(SARD.Luces.estado, { intensity: SARD.Luces.puntual(3), duration: d(0.4) }, 0);
                }
            }
            if (inmediato) { tl.progress(1); }
            return tl;
        },

        /** Avanza o retrocede hasta la fase i: aplica las anteriores sin animación y anima la i. */
        irAFase(ctx, i) {
            this.reiniciar(ctx);
            for (let k = 0; k < i; k++) { this.fase(ctx, k, true); }
            ctx.fase = SARD.Anim.loop(ctx, this.fase(ctx, i, false));
            ctx.anunciar(`${ctx.textos.fases[i]}. ${ctx.textos.detalles[i]}`);
        },

        avanzarPaso(ctx) {
            const paso = Number(ctx.seccion.dataset.paso);
            if (paso >= ctx.textos.fases.length - 1) { return false; }
            ctx.fase?.progress(1); // termina la fase en curso antes de abrir la siguiente
            ctx.fase = SARD.Anim.loop(ctx, this.fase(ctx, paso + 1, false));
            ctx.anunciar(`${ctx.textos.fases[paso + 1]}. ${ctx.textos.detalles[paso + 1]}`);
            return true;
        },

        retrocederPaso(ctx) {
            const paso = Number(ctx.seccion.dataset.paso);
            if (paso <= 0) { return false; }
            this.irAFase(ctx, paso - 1);
            return true;
        },

        salir(ctx) {
            if (ctx.hay3D()) { SARD.Luces.estado.intensity = 0; }
        },
    });
})(window.SARD = window.SARD || {});
