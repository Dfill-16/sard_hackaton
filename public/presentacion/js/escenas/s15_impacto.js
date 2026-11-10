/*
 * S15_impacto — «Lo que cambia el día de una emergencia» (STORYBOARD §S15 · 75 s).
 *
 * Objetivo: traducir la tecnología a consecuencias concretas y verificables.
 *
 * Tres bloques (Operativo / Técnico / Comunitario). Contadores 3 / 85 % / 10 s rotulados
 * «Datos de demostración». Relieve con 3 zonas de cobertura en verde y 1 brecha en rojo, cada
 * una con su texto. Al revelar un bloque se enciende SU zona en el mismo frame. Clic en una
 * «cobertura» o en la «brecha» → la cámara vuela a esa zona.
 *
 * Scroll: SOLO válido dentro del contenedor de la escena (ScrollTrigger con scroller propio y
 * once: true). Nunca cambia de escena (eso lo hacen las teclas, no la rueda).
 */
(function (SARD) {
    'use strict';

    const { Util } = SARD;

    const VERDE = 0x4ade80;
    const ROJO = 0xef4444;
    // Tierra adentro (+z), con la cámara mirando hacia el mar: el faro queda al fondo.
    const Z = 16;
    // Posiciones de las 4 zonas sobre el relieve (3 cobertura + 1 brecha), bien separadas.
    const PUNTOS = [
        { x: -14, z: Z - 2 },
        { x: -2, z: Z + 4 },
        { x: 10, z: Z - 4 },
        { x: 4, z: Z + 12 }, // brecha
    ];

    function crearZona(color) {
        const g = new THREE.Group();
        // depthTest false: el relieve es facetado y taparía un disco plano; la zona es una marca.
        const marca = (geo) => {
            const m = new THREE.Mesh(geo, new THREE.MeshBasicMaterial({ color, transparent: true, opacity: 0, side: THREE.DoubleSide, depthWrite: false, depthTest: false }));
            m.rotation.x = -Math.PI / 2;
            m.renderOrder = 3;
            return m;
        };
        const disco = marca(SARD.M3.geo('Circle', [2.4, 32]));
        const anillo = marca(SARD.M3.geo('Ring', [2.3, 2.6, 40]));
        g.add(disco, anillo);
        g.userData = { disco, anillo };
        return g;
    }

    function crearEscena3D(ctx) {
        const g = new THREE.Group();
        const zonas = ctx.textos.zonas.map((z, i) => {
            const color = z.tipo === 'brecha' ? ROJO : VERDE;
            const grupo = crearZona(color);
            const p = PUNTOS[i];
            grupo.position.set(p.x, SARD.Terreno.altura(p.x, p.z) + 0.1, p.z);
            // Luz propia de la zona (apagada hasta que se revele su bloque). Reutiliza Luces.estado
            // NO es posible (es compartida); se crea una luz por zona y se libera con el grupo.
            const luz = new THREE.PointLight(color, 0, 14, 1.6);
            luz.position.set(p.x, SARD.Terreno.altura(p.x, p.z) + 2.5, p.z);
            grupo.add(luz);
            grupo.userData.luz = luz;
            g.add(grupo);
            return grupo;
        });
        return { g, zonas };
    }

    SARD.Escenas.definir({
        id: 'S15_impacto',

        escenario: { encuadre: { pos: [0, 24, Z + 24], objetivo: [0, 2, Z + 4], ancho: 34 }, niebla: 0.012, linterna: 2.4, baliza: true },

        plantilla(t, escena) {
            const bloques = t.bloques.map((b, i) => Util.el('section', { class: 's15__bloque', dataset: { i: String(i) }, 'aria-labelledby': `s15-bloque-${i}` }, [
                Util.el('h2', { id: `s15-bloque-${i}`, class: 's15__bloque-titulo', text: b.titulo }),
                Util.el('ul', { class: 's15__puntos' }, b.puntos.map(([p1, p2]) => Util.el('li', { class: 's15__punto' }, [
                    Util.el('span', { class: 's15__punto-1' }, [Util.el('span', { html: SARD.Iconos.html('check') }), Util.el('span', { text: p1 })]),
                    Util.el('span', { class: 's15__punto-2', text: p2 }),
                ]))),
            ]));

            const contadores = Util.el('div', { class: 's15__contadores' }, t.contadores.map((c, i) => Util.el('div', { class: 'metrica tarjeta s15__contador' }, [
                Util.el('span', { class: 'metrica__label', text: c.etiqueta }),
                Util.el('span', { class: 'metrica__valor', dataset: { c: String(i), objetivo: String(c.valor), sufijo: c.sufijo }, text: `0${c.sufijo}` }),
            ])));

            const zonas = Util.el('div', { class: 's15__zonas' }, t.zonas.map((z, i) => Util.el('button', {
                type: 'button', class: `s15__zona${z.tipo === 'brecha' ? ' s15__zona--brecha' : ''}`, dataset: { i: String(i) },
                on: { click: () => this.volarA(escena.ctx, i) },
            }, Util.el('span', { text: z.etiqueta }))));

            return Util.el('section', { class: 's15' }, Util.el('div', { class: 'panel panel--ancho pila s15__panel' }, [
                Util.el('h1', { class: 's15__h1', text: t.h1 }),
                Util.el('div', { class: 's15__bloques' }, bloques),
                contadores,
                Util.el('h2', { class: 'label', text: t.zonasTitulo }),
                zonas,
                Util.el('p', { class: 's15__pie', text: t.pie }),
            ]));
        },

        cargar(ctx) {
            const g = ctx.grupo3D('impacto');
            if (!g) { return; }
            Object.assign(ctx, crearEscena3D(ctx));
            g.add(ctx.g);
        },

        activar(ctx) {
            const s = ctx.seccion;
            const valores = s.querySelectorAll('.s15__contador .metrica__valor');

            SARD.Anim.entrar(ctx, () => {
                const tl = gsap.timeline({ paused: true })
                    .from(s.querySelector('.s15__h1'), { y: -12, autoAlpha: 0, duration: 0.5, ease: 'power2.out' });
                // Cada bloque enciende SU zona en el mismo frame que se revela.
                s.querySelectorAll('.s15__bloque').forEach((bloque, i) => {
                    const ultimo = i === s.querySelectorAll('.s15__bloque').length - 1;
                    tl.from(bloque, { autoAlpha: 0, y: 22, duration: 0.5, ease: 'power2.out' }, i === 0 ? '-=0.15' : '-=0.3')
                        .call(() => {
                            this.encenderZona(ctx, i);
                            // El último bloque enciende también la brecha (el contrapunto).
                            if (ultimo) { this.encenderZona(ctx, ctx.textos.zonas.length - 1); }
                        }, null, '<');
                });
                // Contadores (se cuentan hacia su valor).
                valores.forEach((v) => {
                    const objetivo = Number(v.dataset.objetivo);
                    const sufijo = v.dataset.sufijo || '';
                    const cont = { n: 0 };
                    tl.to(cont, { n: objetivo, duration: 0.7, ease: 'power1.out', snap: { n: 1 }, onUpdate: () => { v.textContent = `${cont.n}${sufijo}`; } }, '-=0.2');
                });
                return tl;
            });

            // La brecha (4.ª zona) se enciende con el último bloque: es el contrapunto.
            if (ctx.zonas && (ctx.reducido() || SARD.Estado.get('visitadas').has(ctx.escena.id))) {
                ctx.zonas.forEach((_, i) => this.encenderZona(ctx, i, true));
            }

            // ScrollTrigger con scroller propio (el panel): la rueda SOLO desplaza el panel,
            // nunca cambia de escena. once:true → cada bloque se revela una vez.
            this.conectarScroll(ctx);
        },

        estadoFinal(ctx) {
            ctx.timeline?.progress(1, true).pause();
            // Todos los valores a su objetivo y todas las zonas encendidas.
            ctx.seccion.querySelectorAll('.s15__contador .metrica__valor').forEach((v) => {
                v.textContent = `${v.dataset.objetivo}${v.dataset.sufijo || ''}`;
            });
            ctx.zonas?.forEach((_, i) => this.encenderZona(ctx, i, true));
        },

        /** Enciende la zona i (disco + anillo + luz) en el mismo frame que su bloque. */
        encenderZona(ctx, i, inmediato = false) {
            if (!ctx.zonas || !ctx.zonas[i]) { return; }
            const { disco, anillo, luz } = ctx.zonas[i].userData;
            const esBrecha = ctx.textos.zonas[i].tipo === 'brecha';
            const d = inmediato || ctx.reducido() ? 0 : 0.5;
            gsap.to(disco.material, { opacity: esBrecha ? 0.22 : 0.3, duration: d, overwrite: true });
            gsap.to(anillo.material, { opacity: esBrecha ? 0.6 : 0.8, duration: d, overwrite: true });
            gsap.to(luz, { intensity: SARD.Luces.puntual(esBrecha ? 0.6 : 1), duration: d, overwrite: true });
        },

        /** Clic en una zona: la cámara vuela a ella (no cambia de escena). */
        volarA(ctx, i) {
            ctx.anunciar(ctx.textos.zonas[i].etiqueta);
            if (!ctx.hay3D() || !ctx.zonas[i]) { return; }
            const p = PUNTOS[i];
            const y = SARD.Terreno.altura(p.x, p.z);
            SARD.Motor.encuadrar({ pos: [p.x + 4, y + 11, p.z + 13], objetivo: [p.x, y + 1, p.z], ancho: 14 }, { duracion: 1.2 });
            // Pequeño pulso de la zona al enfocarla.
            if (!ctx.reducido()) {
                const anillo = ctx.zonas[i].userData.anillo;
                gsap.fromTo(anillo.scale, { x: 1, y: 1, z: 1 }, { x: 1.25, y: 1.25, z: 1.25, duration: 0.4, ease: 'power2.out', yoyo: true, repeat: 1 });
            }
        },

        /**
         * ScrollTrigger por bloque con el panel como scroller. once:true. Enciende la zona del
         * bloque cuando entra en vista (en móvil/tablet, donde el panel se desplaza).
         */
        conectarScroll(ctx) {
            if (!window.ScrollTrigger || ctx.reducido()) { return; }
            const panel = ctx.seccion.querySelector('.s15__panel');
            if (!panel) { return; }
            // Contenedor que de verdad se desplaza: el panel (escritorio/tablet) o #contenido (móvil).
            const scroller = getComputedStyle(panel).overflowY === 'auto' ? panel : document.getElementById('contenido');
            ctx.scrollTriggers = Array.from(ctx.seccion.querySelectorAll('.s15__bloque')).map((bloque, i) => ScrollTrigger.create({
                trigger: bloque, scroller, start: 'top 90%', once: true,
                onEnter: () => this.encenderZona(ctx, i),
            }));
        },

        salir(ctx) {
            ctx.scrollTriggers?.forEach((st) => st.kill());
            ctx.scrollTriggers = null;
        },

        destruir(ctx) {
            ctx.scrollTriggers?.forEach((st) => st.kill());
            ctx.scrollTriggers = null;
        },
    });
})(window.SARD = window.SARD || {});
