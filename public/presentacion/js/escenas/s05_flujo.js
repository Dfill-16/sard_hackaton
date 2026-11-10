/*
 * S05_flujo — «El recorrido de un reporte» (STORYBOARD §S05 · 45 s).
 *
 * Objetivo: que el jurado pueda dibujar el flujo de memoria.
 *
 * Diagrama SVG, no 3D: la relación es lineal y jerárquica, no espacial (CONCEPT §6.4).
 * El 3D queda de fondo al 25 % mientras se traza.
 *
 * Animación → qué explica:
 *   · La línea se dibuja de izquierda a derecha y los nodos aparecen siguiéndola: es un
 *     recorrido con orden.
 *   · El último nodo (ACUSE) late: el ACK es lo que cierra el circuito.
 * Interacción: cada nodo es un <button> real sobre el SVG; hover/foco muestra el detalle y el
 * clic lo fija debajo del diagrama.
 */
(function (SARD) {
    'use strict';

    const { Util } = SARD;
    const NS = 'http://www.w3.org/2000/svg';
    const VB = { w: 1200, h: 220 };
    const X0 = 80;
    const PASO = (1120 - X0) / 6;
    const Y = 100;
    const R = 22;

    function svg(etiqueta, attrs = {}, hijos = []) {
        const n = document.createElementNS(NS, etiqueta);
        Object.entries(attrs).forEach(([k, v]) => n.setAttribute(k, v));
        hijos.forEach((h) => n.append(h));
        return n;
    }

    SARD.Escenas.definir({
        id: 'S05_flujo',

        // Fondo atenuado al 25 % (el diagrama es el protagonista).
        escenario: { niebla: 0.022, linterna: 2.2, baliza: true, atenuado: true },

        plantilla(t, escena) {
            const xs = t.nodos.map((_, i) => X0 + i * PASO);
            const titulo = svg('title', { id: 's05-svg-titulo' });
            titulo.textContent = t.svgTitulo;
            const desc = svg('desc', { id: 's05-svg-desc' });
            desc.textContent = t.svgDesc;
            const dibujo = svg('svg', {
                class: 's05__svg', viewBox: `0 0 ${VB.w} ${VB.h}`, role: 'img', 'aria-labelledby': 's05-svg-titulo s05-svg-desc',
            }, [
                titulo, desc,
                svg('path', { class: 's05__linea', d: `M${X0} ${Y} H${xs[xs.length - 1]}` }),
                ...t.nodos.map((n, i) => svg('g', { class: `s05__nodo${i === t.nodos.length - 1 ? ' s05__acuse' : ''}`, 'data-i': String(i) }, [
                    svg('circle', { cx: xs[i], cy: Y, r: R }),
                    svg('text', { x: xs[i], y: Y + 52, 'text-anchor': 'middle' }, [document.createTextNode(n.etiqueta)]),
                ])),
            ]);

            // Botones reales posicionados sobre cada nodo (teclado, lector de pantalla, hover).
            const botones = t.nodos.map((n, i) => Util.el('button', {
                // El detalle lo aporta el tooltip vía aria-describedby: aquí solo el nombre del paso.
                type: 'button', class: 's05__boton', 'aria-pressed': 'false', 'aria-label': n.etiqueta,
                style: `left:${(xs[i] / VB.w) * 100}%;top:${(Y / VB.h) * 100}%`,
                dataset: { i: String(i) },
                on: { click: () => this.fijar(escena.ctx, i) },
            }));

            return Util.el('section', { class: 's05' }, [
                Util.el('div', { class: 'panel panel--ancho pila s05__panel' }, [
                    Util.el('h1', { class: 's05__h1', text: t.h1 }),
                    Util.el('figure', { class: 's05__figura' }, [dibujo, ...botones]),
                    Util.el('p', { class: 'caption s05__detalle', 'aria-live': 'polite', text: t.instruccion }),
                    Util.el('p', { class: 'label s05__pie', text: t.pie }),
                    // Equivalente textual del diagrama (INTERACTION_SPEC §7.3).
                    Util.el('ol', { class: 'sr-only' }, t.nodos.map((n) => Util.el('li', { text: `${n.etiqueta}: ${n.detalle}` }))),
                ]),
            ]);
        },

        cargar(ctx) {
            ctx.seccion.querySelectorAll('.s05__boton').forEach((b, i) => {
                SARD.Tooltip.adjuntar(b, ctx.textos.nodos[i].detalle, ctx.senal);
            });
        },

        activar(ctx) {
            const s = ctx.seccion;
            SARD.Anim.entrar(ctx, () => gsap.timeline({ paused: true })
                .from(s.querySelector('.s05__h1'), { y: -12, autoAlpha: 0, duration: 0.5, ease: 'power2.out' })
                // Trazo: la línea se dibuja (1,4 s)…
                .fromTo(s.querySelector('.s05__linea'), { drawSVG: '0%' }, { drawSVG: '100%', duration: 1.4, ease: 'power1.inOut' })
                // …y los nodos aparecen siguiéndola.
                .from(s.querySelectorAll('.s05__nodo'), { scale: 0.5, autoAlpha: 0, transformOrigin: '50% 45%', duration: 0.35, ease: 'back.out(1.8)', stagger: 0.18 }, '-=1.1')
                .from(s.querySelectorAll('.s05__detalle, .s05__pie'), { autoAlpha: 0, duration: 0.4 }, '-=0.2')
                // Pulso de confirmación al final: el acuse cierra el circuito.
                .fromTo(s.querySelector('.s05__acuse circle'), { scale: 1, transformOrigin: '50% 50%' },
                    { scale: 1.25, duration: 0.3, ease: 'power2.out', yoyo: true, repeat: 1 }, '-=0.2'));
        },

        estadoFinal(ctx) { ctx.timeline?.progress(1).pause(); },

        /** Fija el detalle de un paso debajo del diagrama y resalta su nodo. */
        fijar(ctx, i) {
            const s = ctx.seccion;
            const n = ctx.textos.nodos[i];
            s.querySelector('.s05__detalle').textContent = `${n.etiqueta} — ${n.detalle}`;
            s.querySelectorAll('.s05__boton').forEach((b, k) => b.setAttribute('aria-pressed', String(k === i)));
            s.querySelectorAll('.s05__nodo').forEach((g, k) => g.classList.toggle('activo', k === i));
        },
    });
})(window.SARD = window.SARD || {});
