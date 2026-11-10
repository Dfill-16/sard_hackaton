/*
 * S08_agentes — «Del reporte al incidente» (STORYBOARD §S08 · 75 s).
 *
 * Objetivo: distinguir las cuatro funciones del líder (A1 → A2 → A3 → A6) y entender que la
 * cuarta propone pero NO despacha.
 *
 * Animación → qué explica:
 *   · Un punto de luz recorre las 4 estaciones: el mismo reporte pasa por cuatro manos.
 *     La etiqueta de cada estación aparece cuando el punto la alcanza (mismo frame).
 *   · A1: una rejilla (ordena) · A2: barras P1–P4 (prioriza).
 *   · A3: tres puntos convergen en uno (tres dispositivos, un incidente; los originales siguen).
 *   · A6: el vector de la propuesta se detiene en una barrera de luz: aquí se detiene el sistema.
 * Navegación especial (INTERACTION_SPEC §2.7): → / Espacio recorren las 4 estaciones (cada una
 * abre su ficha «Hace / No hace») y solo después pasan a la escena siguiente.
 */
(function (SARD) {
    'use strict';

    const { Util } = SARD;
    const XS = [-9, -3, 3, 9];
    const Z = -26;
    const Y = 3;
    const POS_BALIZA = { x: 0, y: 2.3, z: 2.2 };

    function crearEstaciones() {
        const plataforma = SARD.M3.mat('plataforma', { color: 0x1d1d1b, roughness: 0.6, metalness: 0.3 });
        const emblema = SARD.M3.mat('emblema', { color: 0x62605b, emissive: 0x3e3e3a, emissiveIntensity: 0.6, roughness: 0.5 });
        const estaciones = XS.map((x) => {
            const g = new THREE.Group();
            g.position.set(x, Y, Z);
            const base = new THREE.Mesh(SARD.M3.geo('Cylinder', [1.8, 2, 0.3, 32]), plataforma);
            base.receiveShadow = true;
            g.add(base);
            return g;
        });
        // A1 · rejilla 3×3 (ordenar sin inventar).
        for (let i = 0; i < 9; i++) {
            const c = new THREE.Mesh(SARD.M3.geo('Box', [0.32, 0.12, 0.32]), emblema);
            c.position.set(((i % 3) - 1) * 0.45, 0.22, (Math.floor(i / 3) - 1) * 0.45);
            estaciones[0].add(c);
        }
        // A2 · cuatro barras P1–P4 (alto = urgencia; el texto P1–P4 está en la tarjeta).
        [0xef4444, 0xfb923c, 0xfacc15, 0x57534e].forEach((color, i) => {
            const b = new THREE.Mesh(SARD.M3.geo('Box', [0.28, 1, 0.28]), new THREE.MeshStandardMaterial({ color, emissive: color, emissiveIntensity: 0.35 }));
            const alto = 1.2 - i * 0.25;
            b.scale.y = alto;
            b.position.set((i - 1.5) * 0.42, 0.15 + alto / 2, 0);
            estaciones[1].add(b);
        });
        // A3 · tres puntos que convergen + anillo del incidente.
        const puntos = [[-0.9, -0.5], [0.8, -0.6], [0.1, 0.9]].map(([x, z]) => {
            const p = new THREE.Mesh(SARD.M3.geo('Sphere', [0.18, 12, 8]), new THREE.MeshStandardMaterial({ color: 0xc8d8e8, emissive: 0xc8d8e8, emissiveIntensity: 1 }));
            p.position.set(x, 0.4, z);
            p.userData.origen = { x, z };
            estaciones[2].add(p);
            return p;
        });
        const anillo = new THREE.Mesh(SARD.M3.geo('Torus', [0.55, 0.04, 8, 40]), emblema);
        anillo.rotation.x = Math.PI / 2;
        anillo.position.y = 0.4;
        estaciones[2].add(anillo);
        // A6 · vector (propuesta) que se detiene en la barrera.
        const vector = new THREE.Group();
        const cuerpo = new THREE.Mesh(SARD.M3.geo('Cylinder', [0.06, 0.06, 1, 8]), emblema);
        cuerpo.rotation.z = Math.PI / 2;
        cuerpo.position.x = 0.5;
        const punta = new THREE.Mesh(SARD.M3.geo('Cone', [0.16, 0.36, 12]), emblema);
        punta.rotation.z = -Math.PI / 2;
        punta.position.x = 1.15;
        vector.add(cuerpo, punta);
        vector.position.set(-0.6, 0.5, 0); // la punta queda justo antes de la barrera
        estaciones[3].add(vector);
        // Cortina de luz perpendicular al vector: «aquí se detiene el sistema».
        const barrera = new THREE.Mesh(SARD.M3.geo('Plane', [1.8, 2.2]),
            new THREE.MeshBasicMaterial({ color: 0xededec, transparent: true, opacity: 0, side: THREE.DoubleSide, depthWrite: false }));
        barrera.rotation.y = Math.PI / 2;
        barrera.position.set(0.85, 1.1, 0);
        estaciones[3].add(barrera);

        const viajero = new THREE.Sprite(new THREE.SpriteMaterial({ map: SARD.M3.textura('particula'), color: 0xfff6e8, transparent: true, opacity: 0, blending: THREE.AdditiveBlending, depthWrite: false, fog: false }));
        viajero.scale.set(1.6, 1.6, 1);
        viajero.position.set(XS[0] - 4, Y + 1.4, Z);
        return { estaciones, puntos, vector, barrera, viajero };
    }

    SARD.Escenas.definir({
        id: 'S08_agentes',

        escenario: { encuadre: { pos: [0, 17, -8], objetivo: [0, Y + 0.5, Z], ancho: 24 }, niebla: 0.02, linterna: 2.2, baliza: true },

        plantilla(t, escena) {
            const [antes, despues] = t.pie.split('{propone}');
            const tarjetas = t.estaciones.map((e, i) => Util.el('li', {}, Util.el('button', {
                type: 'button', class: `tarjeta s08__est s08__est-${i}`, 'aria-pressed': 'false', 'aria-expanded': 'false', dataset: { i: String(i) },
                on: { click: () => this.abrir(escena.ctx, i) },
            }, [
                Util.el('span', { class: 's08__num dato', text: String(i + 1) }),
                Util.el('span', { class: 's08__verbo', text: e.verbo }),
                Util.el('span', { class: 'caption s08__agente', text: e.agente }),
                Util.el('span', { class: 'chip chip--mono s08__dato', text: e.dato }),
                Util.el('span', { class: 's08__ficha' }, [
                    Util.el('span', { class: 's08__hace' }, [Util.el('strong', { text: `${t.hace}: ` }), e.hace]),
                    Util.el('span', { class: 's08__no-hace' }, [Util.el('strong', { text: `${t.noHace}: ` }), e.noHace]),
                ]),
            ])));
            return Util.el('section', { class: 's08' }, [
                Util.el('div', { class: 'panel panel--ancho pila s08__panel' }, [
                    Util.el('h1', { class: 's08__h1', text: t.h1 }),
                    Util.el('ol', { class: 's08__rejilla' }, tarjetas),
                    Util.el('div', { class: 's08__pie-fila' }, [
                        Util.el('p', { class: 's08__pie' }, [antes, Util.el('span', { class: 'acento', text: t.propone }), despues]),
                        Util.el('p', { class: 'caption', text: t.datoRotulo }),
                    ]),
                ]),
            ]);
        },

        cargar(ctx) {
            const g = ctx.grupo3D('estaciones');
            if (!g) { return; }
            Object.assign(ctx, crearEstaciones());
            g.add(...ctx.estaciones, ctx.viajero);
        },

        activar(ctx) {
            const s = ctx.seccion;
            const etiquetas = s.querySelectorAll('.s08__dato');
            SARD.Anim.entrar(ctx, () => {
                const tl = gsap.timeline({ paused: true })
                    .from(s.querySelector('.s08__h1'), { y: -12, autoAlpha: 0, duration: 0.55, ease: 'power2.out' })
                    .from(s.querySelectorAll('.s08__est'), { y: 16, autoAlpha: 0, duration: 0.45, ease: 'power2.out', stagger: 0.16 }, '-=0.15')
                    .set(etiquetas, { autoAlpha: 0 }, 0);
                if (ctx.estaciones) {
                    tl.from(ctx.estaciones.map((e) => e.scale), { x: 0.5, y: 0.5, z: 0.5, duration: 0.45, ease: 'back.out(1.7)', stagger: 0.16 }, 0.4)
                        .set(ctx.viajero.material, { opacity: 1 }, 1.3);
                    // El punto recorre las 4 estaciones; cada etiqueta aparece al llegar.
                    XS.forEach((x, i) => {
                        tl.to(ctx.viajero.position, { x, duration: 0.55, ease: 'power2.inOut' }, 1.3 + i * 0.75)
                            .to(etiquetas[i], { autoAlpha: 1, duration: 0.3 }, 1.3 + i * 0.75 + 0.5);
                    });
                    // A3: los tres puntos convergen en uno.
                    tl.to(ctx.puntos.map((p) => p.position), { x: 0, z: 0, duration: 0.7, ease: 'power2.inOut' }, 1.3 + 2 * 0.75 + 0.5)
                        // A6: el vector avanza y se detiene en la barrera, que se enciende.
                        .from(ctx.vector.scale, { x: 0.01, duration: 0.6, ease: 'power2.out' }, 1.3 + 3 * 0.75 + 0.4)
                        .to(ctx.barrera.material, { opacity: 0.55, duration: 0.3 }, '<0.3')
                        .to(ctx.viajero.material, { opacity: 0, duration: 0.4 }, '>');
                } else {
                    tl.to(etiquetas, { autoAlpha: 1, duration: 0.3, stagger: 0.2 });
                }
                return tl.from(s.querySelector('.s08__pie-fila'), { autoAlpha: 0, duration: 0.4 }, '-=0.2');
            });
            const paso = Number(s.dataset.paso ?? -1);
            if (paso >= 0) { this.abrir(ctx, paso, true); }
        },

        estadoFinal(ctx) { ctx.timeline?.progress(1, true).pause(); },

        /** Abre la ficha «Hace / No hace» de la estación i (una a la vez) y lleva la luz allí. */
        abrir(ctx, i, inmediato = false) {
            const s = ctx.seccion;
            s.dataset.paso = String(i);
            s.querySelectorAll('.s08__est').forEach((b, k) => {
                b.setAttribute('aria-pressed', String(k === i));
                b.setAttribute('aria-expanded', String(k === i));
            });
            const e = ctx.textos.estaciones[i];
            ctx.anunciar(`${e.verbo}. ${ctx.textos.hace}: ${e.hace} ${ctx.textos.noHace}: ${e.noHace}`);
            if (!ctx.estaciones) { return; }
            // La luz de acento (baliza, hija del faro) se traslada sobre la estación activa.
            const destino = SARD.Faro.grupo.worldToLocal(new THREE.Vector3(XS[i], Y + 2.2, Z));
            gsap.to(SARD.Luces.baliza.position, { x: destino.x, y: destino.y, z: destino.z, duration: inmediato || ctx.reducido() ? 0 : 0.45, ease: 'power2.inOut', overwrite: true });
            gsap.to(SARD.Luces.baliza, { distance: 9, duration: 0.3, overwrite: 'auto' });
        },

        avanzarPaso(ctx) {
            const paso = Number(ctx.seccion.dataset.paso ?? -1);
            if (paso >= XS.length - 1) { return false; }
            this.abrir(ctx, paso + 1);
            return true;
        },

        retrocederPaso(ctx) {
            const paso = Number(ctx.seccion.dataset.paso ?? -1);
            if (paso <= 0) { return false; }
            this.abrir(ctx, paso - 1);
            return true;
        },

        /** Salida: la luz de acento vuelve a la base del faro. */
        salir(ctx) {
            if (!ctx.hay3D()) { return; }
            gsap.to(SARD.Luces.baliza.position, { ...POS_BALIZA, duration: 0.4, overwrite: true });
            gsap.to(SARD.Luces.baliza, { distance: 7, duration: 0.4 });
        },
    });
})(window.SARD = window.SARD || {});
