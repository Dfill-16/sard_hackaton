/*
 * S11_trazabilidad — «El log que no se puede tocar» (STORYBOARD §S11 · 30 s).
 *
 * Objetivo: entender que el registro es inalterable por diseño.
 *
 * Animación → qué explica:
 *   · Un cilindro de capas apiladas: una capa = un evento. Solo crece hacia arriba (append-only).
 *   · Las 5 capas de arriba se «escriben» una a una con un destello de 120 ms; en el mismo frame
 *     aparece su fila en el rastro y el contador seq sube (408 → 412).
 *   · En el rastro, el «hash previo» de cada fila es el hash de la anterior: eso es la cadena.
 *   · Cada capa lleva una muesca en un ángulo distinto (su «grabado»); el cilindro gira lento.
 * Interacción: clic en una fila → ficha del evento y su capa se ilumina; «Verificar la cadena»
 * muestra el resultado literal de sard:verificar-log.
 * Datos: tipos de evento reales (EventLog); seq, época y hashes son de muestra y se rotulan.
 */
(function (SARD) {
    'use strict';

    const { Util } = SARD;
    const BASE = 7;            // capas anteriores (ya escritas)
    const ALTO = 0.2;

    function crearCilindro(eventos) {
        const g = new THREE.Group();
        const base = new THREE.Mesh(SARD.M3.geo('Cylinder', [1.35, 1.45, 0.22, 40]), SARD.M3.mat('roca'));
        base.position.y = 0.11;
        g.add(base);
        const capas = [];
        const total = BASE + eventos.length;
        for (let i = 0; i < total; i++) {
            const nueva = i >= BASE;
            const capa = new THREE.Group();
            const disco = new THREE.Mesh(SARD.M3.geo('Cylinder', [1.15, 1.15, 0.16, 40]),
                new THREE.MeshStandardMaterial({ color: 0x232322, emissive: 0xc8d8e8, emissiveIntensity: nueva ? 0 : 0.08, roughness: 0.45, metalness: 0.5, transparent: true, opacity: nueva ? 0.25 : 1 }));
            // Muesca: el «grabado» de la capa, en un ángulo derivado de su hash (o de su índice).
            const hash = nueva ? eventos[i - BASE][5] : null;
            const angulo = hash ? (parseInt(hash.slice(0, 3), 16) % 360) * (Math.PI / 180) : i * 1.7;
            const muesca = new THREE.Mesh(SARD.M3.geo('Box', [0.36, 0.05, 0.08]), SARD.M3.mat('muesca', { color: 0xededec, emissive: 0xededec, emissiveIntensity: 0.4 }));
            muesca.position.set(Math.cos(angulo) * 1.1, 0.06, Math.sin(angulo) * 1.1);
            muesca.rotation.y = -angulo;
            capa.add(disco, muesca);
            capa.position.y = 0.3 + i * ALTO;
            capa.userData.disco = disco;
            g.add(capa);
            capas.push(capa);
        }
        return { g, nuevas: capas.slice(BASE) };
    }

    SARD.Escenas.definir({
        id: 'S11_trazabilidad',

        escenario: { encuadre: null, niebla: 0.02, linterna: 2.2, baliza: true },

        /** El cilindro vive entre la tarjeta de las reglas y la del rastro. */
        zonaLibre(seccion, { arriba, alto, margen }) {
            const a = seccion.querySelector('.s11__izq').getBoundingClientRect();
            const b = seccion.querySelector('.s11__der').getBoundingClientRect();
            return { x: a.right + margen, y: arriba, w: Math.max(0, b.left - a.right - 2 * margen), h: alto };
        },

        plantilla(t, escena) {
            const filas = t.eventos.map(([seq, tipo, nodo, epoca, previo, hash], i) => Util.el('tr', { class: 's11__fila', dataset: { i: String(i) } }, [
                Util.el('th', { scope: 'row' }, Util.el('button', {
                    type: 'button', class: 's11__seq dato', 'aria-pressed': 'false', text: String(seq),
                    'aria-label': Util.plantilla(t.ficha, { tipo, nodo, seq, epoca, hash }),
                    on: { click: () => this.seleccionar(escena.ctx, i) },
                })),
                Util.el('td', { text: tipo }),
                Util.el('td', { class: 'dato', text: nodo }),
                Util.el('td', { class: 'dato', text: String(epoca) }),
                Util.el('td', { class: 'dato muted', text: previo }),
                Util.el('td', { class: 'dato', text: hash }),
            ]));
            return Util.el('section', { class: 's11' }, [
                Util.el('div', { class: 'panel panel--compacto pila s11__izq' }, [
                    Util.el('h1', { class: 's11__h1', text: t.h1 }),
                    Util.el('ul', { class: 's11__tarjetas' }, t.tarjetas.map(([titulo, texto]) => Util.el('li', { class: 'tarjeta s11__tarjeta' }, [
                        Util.el('span', { class: 'label', text: titulo }), Util.el('span', { text: texto }),
                    ]))),
                    Util.el('div', { class: 's11__verificar' }, [
                        Util.el('button', { type: 'button', class: 'btn btn--secundario s11__boton', html: `${SARD.Iconos.html('cadena')}<span>${Util.escapar(t.verificar)}</span>`, on: { click: () => this.verificar(escena.ctx) } }),
                        Util.el('div', { class: 'codigo' }, Util.el('code', { text: t.comandoVerificar })),
                        Util.el('p', { class: 's11__resultado dato', role: 'status', hidden: true, text: t.resultado }),
                    ]),
                ]),
                Util.el('div', { class: 'panel panel--compacto pila s11__der' }, [
                    Util.el('div', { class: 's11__cabecera' }, [
                        Util.el('h2', { class: 'label', text: t.rastroTitulo }),
                        Util.el('p', { class: 's11__contador dato' }, [`${t.seq} `, Util.el('span', { class: 's11__n', text: String(t.eventos[t.eventos.length - 1][0]) })]),
                    ]),
                    Util.el('div', { class: 's11__tabla-caja' }, Util.el('table', { class: 'tabla s11__tabla' }, [
                        Util.el('caption', { class: 'sr-only', text: t.rastroTitulo }),
                        Util.el('thead', {}, Util.el('tr', {}, t.columnas.map((c) => Util.el('th', { scope: 'col', text: c })))),
                        Util.el('tbody', {}, filas),
                    ])),
                    Util.el('p', { class: 's11__ficha dato', 'aria-live': 'polite' }),
                    Util.el('p', { class: 'caption', text: t.ejemploRotulo }),
                ]),
            ]);
        },

        cargar(ctx) {
            const g = ctx.grupo3D('log');
            if (!g) { return; }
            const { g: cilindro, nuevas } = crearCilindro(ctx.textos.eventos);
            const f = SARD.Faro.grupo.position;
            const x = f.x + 5;
            const z = f.z + 3.5;
            cilindro.position.set(x, SARD.Terreno.altura(x, z), z);
            g.add(cilindro);
            ctx.cilindro = cilindro;
            ctx.capas = nuevas;
        },

        activar(ctx) {
            const s = ctx.seccion;
            const t = ctx.textos;
            if (ctx.hay3D()) {
                const c = ctx.cilindro.position;
                SARD.Motor.encuadrar({ pos: [c.x + 2.5, c.y + 3.6, c.z + 7], objetivo: [c.x, c.y + 1.6, c.z], ancho: 4.4, alto: 4.6 }, { duracion: 1.4 });
                // Gira 12°/s: la muesca de cada capa hace visible el giro.
                SARD.Anim.loop(ctx, ctx.reducido() ? null : gsap.to(ctx.cilindro.rotation, { y: `+=${Math.PI * 2}`, duration: 30, ease: 'none', repeat: -1 }));
            }
            SARD.Anim.entrar(ctx, () => {
                const n = { v: t.eventos[0][0] - 1 };
                const tl = gsap.timeline({ paused: true })
                    .from(s.querySelector('.s11__h1'), { y: -12, autoAlpha: 0, duration: 0.5, ease: 'power2.out' })
                    .from(s.querySelectorAll('.s11__tarjeta'), { autoAlpha: 0, y: 16, duration: 0.4, ease: 'power2.out', stagger: 0.1 }, '-=0.15')
                    .set(s.querySelectorAll('.s11__fila'), { autoAlpha: 0 }, 0)
                    .call(() => { s.querySelector('.s11__n').textContent = String(n.v); }, null, 0);
                // Escritura: capa (3D), fila (HTML) y seq cambian en el mismo instante.
                t.eventos.forEach((ev, i) => {
                    const en = 0.9 + i * 0.18;
                    tl.to(s.querySelector(`.s11__fila[data-i="${i}"]`), { autoAlpha: 1, duration: 0.18 }, en)
                        .call(() => { s.querySelector('.s11__n').textContent = String(ev[0]); }, null, en);
                    if (ctx.capas) {
                        const disco = ctx.capas[i].userData.disco;
                        tl.to(disco.material, { opacity: 1, duration: 0.18, ease: 'power2.out' }, en)
                            .to(disco.material, { emissiveIntensity: 1.2, duration: 0.06 }, en)
                            .to(disco.material, { emissiveIntensity: 0.08, duration: 0.12 }, en + 0.06);
                    }
                });
                return tl;
            });
        },

        estadoFinal(ctx) {
            ctx.timeline?.progress(1, true).pause();
            const s = ctx.seccion;
            gsap.set(s.querySelectorAll('.s11__fila'), { autoAlpha: 1 });
            ctx.capas?.forEach((c) => { c.userData.disco.material.opacity = 1; });
        },

        /** Fila i → ficha del evento y su capa iluminada. */
        seleccionar(ctx, i) {
            const s = ctx.seccion;
            const [seq, tipo, nodo, epoca, , hash] = ctx.textos.eventos[i];
            s.querySelectorAll('.s11__seq').forEach((b, k) => b.setAttribute('aria-pressed', String(k === i)));
            s.querySelectorAll('.s11__fila').forEach((f, k) => f.classList.toggle('activa', k === i));
            s.querySelector('.s11__ficha').textContent = Util.plantilla(ctx.textos.ficha, { tipo, nodo, seq, epoca, hash });
            ctx.capas?.forEach((c, k) => {
                gsap.to(c.userData.disco.material, { emissiveIntensity: k === i ? 0.9 : 0.08, duration: ctx.reducido() ? 0 : 0.2, overwrite: true });
            });
        },

        /** Muestra el resultado literal del comando de verificación. */
        verificar(ctx) {
            const r = ctx.seccion.querySelector('.s11__resultado');
            r.hidden = false;
            if (!ctx.reducido()) { gsap.fromTo(r, { autoAlpha: 0, y: 6 }, { autoAlpha: 1, y: 0, duration: 0.3, ease: 'power2.out' }); }
            if (ctx.capas && !ctx.reducido()) {
                // Recorre la cadena de abajo arriba: cada eslabón se comprueba contra el anterior.
                ctx.capas.forEach((c, k) => gsap.fromTo(c.userData.disco.material, { emissiveIntensity: 0.9 }, { emissiveIntensity: 0.08, duration: 0.3, delay: k * 0.08 }));
            }
        },
    });
})(window.SARD = window.SARD || {});
