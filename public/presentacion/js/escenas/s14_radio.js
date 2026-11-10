/*
 * S14_radio — «Fase 2: hablar por radio» (STORYBOARD §S14 · 60 s). [FASE 2 · NO IMPLEMENTADO]
 *
 * Objetivo: presentar la comunicación por radio como visión de futuro, con honestidad
 * explícita sobre lo que NO está construido (badge obligatorio, siempre visible).
 *
 * Cadena: CELULAR → ESTACIÓN (Wi-Fi 100–300 m) → RADIO (2–20 km) → GATEWAY → HTTP → NODO A.
 * En 3D: 4 estaciones de radio (torre + panel solar + antena) a distancias visiblemente
 * distintas + el NODO A (la parte que YA existe). Las capas nuevas (radio) van en
 * --sard-degraded (#fbbf24); la capa existente (Laravel, el Nodo A) en el acento #ff4433.
 * Un pulso con leve distorsión salta de estación en estación; al llegar al Nodo A se resalta
 * «Lo que NO cambia» (la lógica de Laravel, íntegra).
 */
(function (SARD) {
    'use strict';

    const { Util } = SARD;

    const AMBAR = 0xfbbf24;      // --sard-degraded: la capa de radio (no implementada)
    const ACENTO = 0xff4433;     // la capa existente (Laravel)
    // La cadena vive tierra adentro (+z) y la cámara la mira hacia el mar: el faro queda
    // al fondo, pequeño, y no tapa las estaciones.
    const Z = 20;
    const Y = 3;
    const ESCALA_TORRE = 1.7;
    // X de las 4 estaciones de radio + el Nodo A. Separaciones distintas (300 m ↔ 20 km):
    // espaciado creciente para que se LEA la diferencia de distancia sin salirse de cuadro.
    const XS = [-16, -11, -3, 7, 15];
    const ANCHO = 40;

    /** Torre de radio: mástil + antena + panel solar, con una baliza ámbar. */
    function crearTorreRadio(altura) {
        const g = new THREE.Group();
        const mastil = new THREE.Mesh(SARD.M3.geo('Cylinder', [0.12, 0.18, altura, 8]), SARD.M3.mat('torre'));
        mastil.position.y = altura / 2;
        mastil.castShadow = true;
        const antena = new THREE.Mesh(SARD.M3.geo('Cylinder', [0.03, 0.03, 1.2, 6]), SARD.M3.mat('torre'));
        antena.position.y = altura + 0.6;
        // Baliza ámbar (radio = capa nueva): material propio por torre.
        const baliza = new THREE.Mesh(SARD.M3.geo('Sphere', [0.22, 12, 8]),
            new THREE.MeshStandardMaterial({ color: AMBAR, emissive: AMBAR, emissiveIntensity: 1.3 }));
        baliza.position.y = altura + 0.3;
        // Panel solar inclinado hacia el cielo.
        const panel = new THREE.Mesh(SARD.M3.geo('Box', [1.1, 0.06, 0.7]),
            new THREE.MeshStandardMaterial({ color: 0x1b2a3a, emissive: 0x16222f, emissiveIntensity: 0.4, roughness: 0.4, metalness: 0.5 }));
        panel.position.set(0.7, 1.1, 0);
        panel.rotation.z = -0.5;
        const soporte = new THREE.Mesh(SARD.M3.geo('Cylinder', [0.04, 0.04, 1, 6]), SARD.M3.mat('torre'));
        soporte.position.set(0.7, 0.55, 0);
        g.add(mastil, antena, baliza, panel, soporte);
        g.userData = { baliza };
        return g;
    }

    function crearEscena3D() {
        const g = new THREE.Group();
        const alturas = [3.4, 4.2, 5.2, 4.6]; // alturas distintas: estaciones reales, no clones
        const torres = XS.slice(0, 4).map((x, i) => {
            const t = crearTorreRadio(alturas[i]);
            t.position.set(x, SARD.Terreno.altura(x, Z), Z);
            t.scale.setScalar(ESCALA_TORRE);
            g.add(t);
            return t;
        });

        // Nodo A: la capa que YA existe, marcada con el acento del producto.
        const nodoA = SARD.Actores.crearNodoA();
        nodoA.position.set(XS[4], SARD.Terreno.altura(XS[4], Z), Z);
        nodoA.userData.anillo.material = new THREE.MeshBasicMaterial({ color: ACENTO, transparent: true, opacity: 0.7 });
        nodoA.userData.cubo.material = new THREE.MeshStandardMaterial({ color: 0x232322, emissive: ACENTO, emissiveIntensity: 0.18, roughness: 0.5, metalness: 0.3 });
        g.add(nodoA);

        // Luz ámbar única sobre la cadena: las torres se leen en la noche (una sola luz extra).
        const luz = new THREE.PointLight(AMBAR, SARD.Luces.puntual(1.4), 45, 1.4);
        luz.position.set(0, 12, Z + 4);
        g.add(luz);

        // Puntos de salto del pulso (baliza de cada torre y el Nodo A), en coordenadas reales.
        g.updateMatrixWorld(true);
        const saltos = torres.map((t) => t.userData.baliza.getWorldPosition(new THREE.Vector3()))
            .concat([new THREE.Vector3(nodoA.position.x, nodoA.position.y + 2.4, nodoA.position.z)]);

        const pulso = SARD.Actores.crearPulso(AMBAR, 1.3);
        g.add(pulso);

        return { g, torres, nodoA, saltos, pulso };
    }

    SARD.Escenas.definir({
        id: 'S14_radio',

        escenario: { encuadre: { pos: [0, 13, Z + 30], objetivo: [0, Y + 2, Z], ancho: ANCHO }, niebla: 0.02, linterna: 2.2, baliza: true },

        plantilla(t, escena) {
            const idNo = 's14-no-cambia';
            const idSi = 's14-si-cambia';
            const cadena = Util.el('ol', { class: 's14__cadena' }, t.cadena.flatMap((paso, i) => [
                i ? Util.el('li', { class: 's14__flecha', 'aria-hidden': 'true', text: '→' }) : null,
                Util.el('li', { class: `s14__hop${i === t.cadena.length - 1 ? ' s14__hop--existente' : ''}`, dataset: { i: String(i) }, text: paso }),
            ]));

            // Bloque desplegable genérico (botón + región).
            const bloque = (clase, id, titulo, resumen, detalle, borde) => Util.el('div', { class: `s14__bloque ${borde}` }, [
                Util.el('button', {
                    type: 'button', class: `s14__bloque-boton ${clase}`, 'aria-expanded': 'false', 'aria-controls': id,
                    on: { click: (e) => {
                        const b = e.currentTarget;
                        const abierto = b.getAttribute('aria-expanded') !== 'true';
                        b.setAttribute('aria-expanded', String(abierto));
                        document.getElementById(id).hidden = !abierto;
                    } },
                }, [
                    Util.el('span', { class: 's14__bloque-titulo', text: titulo }),
                    Util.el('span', { class: 's14__bloque-resumen', text: resumen }),
                ]),
                Util.el('p', { id, class: 'caption s14__bloque-detalle', hidden: true, text: detalle }),
            ]);

            // Fichas de las estaciones (también visibles en el panel: no solo en el tooltip 3D).
            const fichas = Util.el('ul', { class: 's14__fichas' }, t.estaciones.map(([nombre, detalle], i) => Util.el('li', { class: 's14__ficha', dataset: { i: String(i) } }, [
                Util.el('span', { class: 's14__ficha-nombre', text: nombre }),
                Util.el('span', { class: 'dato s14__ficha-detalle', text: detalle }),
            ])));

            return Util.el('section', { class: 's14' }, [
                Util.el('div', { class: 'panel panel--compacto pila s14__panel' }, [
                    Util.el('span', { class: 'badge badge--degradado s14__badge', role: 'status', 'data-pulso': 'si', text: t.badge }),
                    Util.el('h1', { class: 's14__h1', text: t.h1 }),
                    cadena,
                    Util.el('div', { class: 's14__bloques' }, [
                        bloque('s14__no', idNo, t.noCambiaTitulo, t.noCambia, t.noCambiaLista, 's14__bloque--existente'),
                        bloque('s14__si', idSi, t.siCambiaTitulo, t.siCambia, t.siCambiaDetalle, 's14__bloque--nuevo'),
                    ]),
                    Util.el('h2', { class: 'label', text: t.estacionesTitulo }),
                    fichas,
                ]),
            ]);
        },

        cargar(ctx) {
            const g = ctx.grupo3D('radio');
            if (!g) { return; }
            Object.assign(ctx, crearEscena3D());
            g.add(ctx.g);

            // Un ancla + tooltip por estación de radio (la info ya está en el panel: rule 5).
            ctx.torres.forEach((torre, i) => {
                const ficha = ctx.textos.estaciones[i];
                if (!ficha) { return; }
                const boton = Util.el('button', { type: 'button', class: 'ancla ancla--punto', 'aria-label': `${ficha[0]}: ${ficha[1]}` });
                SARD.Tooltip.adjuntar(boton, `${ficha[0]} · ${ficha[1]}`, ctx.senal);
                // Resalta la ficha del panel al enfocar/hover la torre.
                const fichaEl = ctx.seccion.querySelector(`.s14__ficha[data-i="${i}"]`);
                const marcar = (on) => fichaEl?.classList.toggle('resaltada', on);
                ctx.escuchar(boton, 'pointerenter', () => marcar(true));
                ctx.escuchar(boton, 'pointerleave', () => marcar(false));
                ctx.escuchar(boton, 'focus', () => marcar(true));
                ctx.escuchar(boton, 'blur', () => marcar(false));
                ctx.anclar(torre.userData.baliza, boton, { desplazamientoY: 0.4 });
            });
        },

        activar(ctx) {
            const s = ctx.seccion;
            SARD.Anim.entrar(ctx, () => gsap.timeline({ paused: true })
                .from(s.querySelector('.s14__badge'), { scale: 0.8, autoAlpha: 0, duration: 0.4, ease: 'back.out(2)' })
                .from(s.querySelector('.s14__h1'), { y: -12, autoAlpha: 0, duration: 0.5, ease: 'power2.out' }, '-=0.2')
                .from(s.querySelectorAll('.s14__hop'), { autoAlpha: 0, y: 8, duration: 0.3, stagger: 0.06 }, '-=0.15')
                .from(s.querySelectorAll('.s14__bloque'), { autoAlpha: 0, y: 14, duration: 0.45, stagger: 0.12 }, '-=0.1')
                .from(s.querySelectorAll('.s14__ficha'), { autoAlpha: 0, x: -10, duration: 0.3, stagger: 0.06 }, '-=0.2'));

            if (ctx.hay3D()) {
                // Entrada de las torres (al volver, el estado final las deja ya visibles).
                if (!SARD.Estado.get('visitadas').has(ctx.escena.id) && !ctx.reducido()) {
                    gsap.from(ctx.torres.map((t) => t.scale), { x: 0.3, y: 0.3, z: 0.3, duration: 0.5, ease: 'back.out(1.7)', stagger: 0.16 });
                }
                this.lanzarPulso(ctx);
            }
        },

        estadoFinal(ctx) {
            ctx.timeline?.progress(1, true).pause();
            if (ctx.pulso) { ctx.pulso.material.opacity = 0; }
        },

        /**
         * Pulso que salta de estación en estación con leve distorsión (offset aleatorio): hace
         * visible que la radio es un medio distinto. Al llegar al Nodo A resalta «No cambia».
         */
        lanzarPulso(ctx) {
            if (!ctx.saltos || ctx.reducido()) { return; }
            const azar = SARD.M3.aleatorio(214);
            // Distorsión fija por salto (determinista): la radio desvía un poco cada rebote.
            const jitter = ctx.saltos.map(() => ({ x: (azar() - 0.5) * 1.2, y: 1.4 + azar() * 0.8 }));

            const tl = gsap.timeline({ repeat: -1, repeatDelay: 1.2 });
            tl.set(ctx.pulso.position, { x: ctx.saltos[0].x, y: ctx.saltos[0].y, z: ctx.saltos[0].z })
                .set(ctx.pulso.material, { opacity: 1 })
                .call(() => this.resaltarNoCambia(ctx, false));

            for (let i = 1; i < ctx.saltos.length; i++) {
                const destino = ctx.saltos[i];
                const esNodoA = i === ctx.saltos.length - 1;
                const j = jitter[i];
                const medio = { t: 0 };
                let desde = null;
                tl.to(medio, {
                    t: 1, duration: 0.55, ease: 'power1.inOut',
                    onStart: () => { desde = ctx.pulso.position.clone(); medio.t = 0; },
                    onUpdate: () => {
                        const t = medio.t;
                        if (!desde) { return; }
                        ctx.pulso.position.set(
                            desde.x + (destino.x - desde.x) * t + Math.sin(t * Math.PI) * j.x,
                            desde.y + (destino.y - desde.y) * t + Math.sin(t * Math.PI) * j.y,
                            destino.z,
                        );
                    },
                });
                if (esNodoA) {
                    tl.call(() => this.resaltarNoCambia(ctx, true))
                        .to(ctx.nodoA.userData.cubo.material, { emissiveIntensity: 0.6, duration: 0.3, yoyo: true, repeat: 1 });
                } else {
                    tl.to(ctx.torres[i].userData.baliza.material, { emissiveIntensity: 2.4, duration: 0.2, yoyo: true, repeat: 1 }, '<');
                }
            }
            tl.to(ctx.pulso.material, { opacity: 0, duration: 0.3 });
            ctx.pulsoTl = SARD.Anim.loop(ctx, tl);
        },

        resaltarNoCambia(ctx, on) {
            ctx.seccion.querySelector('.s14__bloque--existente')?.classList.toggle('resaltado', on);
            if (on) { ctx.anunciar(`${ctx.textos.noCambiaTitulo}: ${ctx.textos.noCambia}`); }
        },

        salir(ctx) {
            if (ctx.pulso) { ctx.pulso.material.opacity = 0; }
            this.resaltarNoCambia(ctx, false);
        },
    });
})(window.SARD = window.SARD || {});
