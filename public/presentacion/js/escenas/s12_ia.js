/*
 * S12_ia — «La IA sugiere. La persona decide.» (STORYBOARD §S12 · 45 s).
 *
 * Objetivo: que el jurado sepa que el LLM no despacha nada y que el sistema no depende de él.
 *
 * Animación → qué explica:
 *   · Dos carriles: a la izquierda un autómata (esfera con anillos, sin rostro, ámbar
 *     = --sard-degraded), a la derecha la silueta del operador frente a su panel (acento).
 *   · Entre ambos, una barrera de luz. La IA «intenta» cruzar con una señal; la barrera se
 *     enciende y la frena — en el mismo instante en que se resalta «Nunca decide».
 *   · «modo reglas»: el autómata se apaga y el sistema sigue igual (rótulo real del tablero).
 *   · «modo LLM»: aparece la cadena real de proveedores y el fallback a reglas.
 */
(function (SARD) {
    'use strict';

    const { Util } = SARD;
    const AMBAR = 0xfbbf24;
    const X = { ia: 2, barrera: 6, persona: 10 };
    const Z = 6;

    SARD.Escenas.definir({
        id: 'S12_ia',

        escenario: { encuadre: null, niebla: 0.018, linterna: 2.2, baliza: true },

        plantilla(t, escena) {
            const lista = (items, clase) => Util.el('ul', { class: `s12__lista ${clase}` }, items.map((x, i) => Util.el('li', { dataset: { i: String(i) }, text: x })));
            const modo = (valor, texto) => Util.el('button', {
                type: 'button', class: 's12__modo', 'aria-pressed': String(valor === 'llm'), dataset: { modo: valor }, text: texto,
                on: { click: () => this.fijarModo(escena.ctx, valor) },
            });
            return Util.el('section', { class: 's12', dataset: { modo: 'llm' } }, [
                Util.el('div', { class: 'panel panel--ancho pila s12__panel' }, [
                    Util.el('h1', { class: 's12__h1', text: t.h1 }),
                    Util.el('div', { class: 's12__carriles' }, [
                        Util.el('section', { class: 's12__carril s12__carril--ia', 'aria-labelledby': 's12-ia' }, [
                            Util.el('div', { class: 's12__carril-cabecera' }, [
                                Util.el('h2', { id: 's12-ia', class: 's12__carril-titulo', text: t.carrilIa }),
                                Util.el('div', { class: 's12__modos', role: 'group', 'aria-label': t.modoTitulo }, [modo('reglas', t.modoReglas), modo('llm', t.modoLlm)]),
                            ]),
                            lista(t.puede, 's12__puede'),
                            lista(t.nunca, 's12__nunca'),
                            Util.el('p', { class: 'caption s12__proveedores' }, [`${t.proveedoresTitulo}: `, Util.el('span', { class: 'dato', text: `${t.proveedores.join(' → ')} → ${t.modoReglas}` })]),
                            Util.el('p', { class: 'badge s12__badge-reglas', text: t.modoReglas }),
                            Util.el('button', { type: 'button', class: 'btn btn--fantasma s12__evento-boton', 'aria-expanded': 'false', 'aria-controls': 's12-evento', text: t.eventoBoton, on: { click: (e) => {
                                const b = e.currentTarget; const abierto = b.getAttribute('aria-expanded') !== 'true';
                                b.setAttribute('aria-expanded', String(abierto)); document.getElementById('s12-evento').hidden = !abierto;
                            } } }),
                            Util.el('pre', { id: 's12-evento', class: 'codigo s12__evento', hidden: true }, Util.el('code', { text: `${t.evento} ${t.eventoFormato}` })),
                        ]),
                        Util.el('section', { class: 's12__carril s12__carril--persona', 'aria-labelledby': 's12-persona' }, [
                            Util.el('h2', { id: 's12-persona', class: 's12__carril-titulo', text: t.carrilPersona }),
                            lista(t.persona, 's12__persona'),
                        ]),
                    ]),
                    Util.el('p', { class: 's12__pie', text: t.pie }),
                ]),
            ]);
        },

        cargar(ctx) {
            const g = ctx.grupo3D('carriles');
            if (!g) { return; }
            const y = (x) => SARD.Terreno.altura(x, Z);

            // Autómata (IA): esfera con dos anillos, sin rostro.
            const ia = new THREE.Group();
            ia.position.set(X.ia, y(X.ia) + 1.6, Z);
            const nucleo = new THREE.Mesh(SARD.M3.geo('Sphere', [0.55, 20, 14]), new THREE.MeshStandardMaterial({ color: 0x3e3e3a, emissive: AMBAR, emissiveIntensity: 0.6, roughness: 0.4 }));
            const anillos = [0, Math.PI / 2].map((r) => {
                const a = new THREE.Mesh(SARD.M3.geo('Torus', [0.95, 0.03, 8, 48]), new THREE.MeshBasicMaterial({ color: AMBAR, transparent: true, opacity: 0.7 }));
                a.rotation.set(Math.PI / 2 + r * 0.4, r, 0);
                ia.add(a);
                return a;
            });
            ia.add(nucleo);

            // Barrera de luz entre los carriles.
            const barrera = new THREE.Mesh(SARD.M3.geo('Box', [0.08, 3.2, 1.8]), new THREE.MeshBasicMaterial({ color: 0xededec, transparent: true, opacity: 0.22, depthWrite: false }));
            barrera.position.set(X.barrera, y(X.barrera) + 1.6, Z);

            // Persona: silueta frente a su panel, mirando hacia la barrera.
            const op = SARD.Actores.crearOperador();
            op.grupo.position.set(X.persona, y(X.persona), Z);
            op.grupo.rotation.y = Math.PI / 2;
            op.pantalla.material.emissive.setHex(0xff4433);
            op.pantalla.material.emissiveIntensity = 0.5;

            // Señal de la IA: viaja hacia la barrera y rebota.
            const senal = new THREE.Mesh(SARD.M3.geo('Sphere', [0.16, 12, 8]), new THREE.MeshStandardMaterial({ color: AMBAR, emissive: AMBAR, emissiveIntensity: 1.6 }));
            senal.position.copy(ia.position);

            g.add(ia, barrera, op.grupo, senal);
            Object.assign(ctx, { ia, nucleo, anillos, barrera, senal, operador: op });
        },

        activar(ctx) {
            const s = ctx.seccion;
            if (ctx.hay3D()) {
                const h = SARD.Terreno.altura(X.barrera, Z);
                SARD.Motor.encuadrar({ pos: [X.barrera, h + 4.5, Z + 15], objetivo: [X.barrera, h + 1.6, Z], ancho: 12.5, alto: 5 }, { duracion: 1.6 });
                if (!ctx.reducido()) {
                    SARD.Anim.loop(ctx, gsap.to(ctx.anillos.map((a) => a.rotation), { z: `+=${Math.PI * 2}`, duration: 8, ease: 'none', repeat: -1 }));
                }
            }
            this.fijarModo(ctx, s.dataset.modo, true);

            SARD.Anim.entrar(ctx, () => gsap.timeline({ paused: true })
                .from(s.querySelector('.s12__h1'), { y: -12, autoAlpha: 0, duration: 0.5, ease: 'power2.out' })
                .from(s.querySelector('.s12__carril--ia'), { x: -30, autoAlpha: 0, duration: 0.55, ease: 'power2.out' })
                .from(s.querySelector('.s12__carril--persona'), { x: 30, autoAlpha: 0, duration: 0.55, ease: 'power2.out' }, '-=0.35')
                .from(s.querySelector('.s12__pie'), { autoAlpha: 0, duration: 0.4 }, '-=0.2'));
        },

        /** Bucle de 4 s: la IA intenta decidir → la barrera se enciende y la frena. */
        intentar(ctx) {
            ctx.intento?.kill();
            if (!ctx.senal || ctx.reducido()) { return; }
            const s = ctx.seccion;
            const nuncaDecide = s.querySelector('.s12__nunca li:last-child');
            const xIa = ctx.ia.position.x;
            ctx.senal.visible = true;
            ctx.intento = SARD.Anim.loop(ctx, gsap.timeline({ repeat: -1, repeatDelay: 2.5, delay: 2 })
                .set(ctx.senal.position, { x: xIa })
                .to(ctx.senal.position, { x: X.barrera - 0.35, duration: 1.2, ease: 'power1.inOut' })
                // La barrera frena la señal y «Nunca decide» se resalta en el mismo instante.
                .to(ctx.barrera.material, { opacity: 0.9, duration: 0.2 })
                .call(() => nuncaDecide.classList.add('frenado'), null, '<')
                .to(ctx.senal.position, { x: X.barrera - 2, duration: 0.3, ease: 'power2.out' })
                .to(ctx.barrera.material, { opacity: 0.22, duration: 0.5 })
                .call(() => nuncaDecide.classList.remove('frenado'), null, '<0.3'));
        },

        /** modo reglas: sin LLM, todo sigue por reglas · modo LLM: apoyo acotado. */
        fijarModo(ctx, modo, silencioso = false) {
            const s = ctx.seccion;
            s.dataset.modo = modo;
            s.querySelectorAll('.s12__modo').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.modo === modo)));
            if (!silencioso) { ctx.anunciar(modo === 'reglas' ? ctx.textos.anuncioReglas : ctx.textos.anuncioLlm); }
            if (!ctx.nucleo) { return; }
            const llm = modo === 'llm';
            const d = silencioso || ctx.reducido() ? 0 : 0.4;
            gsap.to(ctx.nucleo.material, { emissiveIntensity: llm ? 0.6 : 0, duration: d, overwrite: true });
            ctx.anillos.forEach((a) => gsap.to(a.material, { opacity: llm ? 0.7 : 0.12, duration: d, overwrite: true }));
            if (llm) { this.intentar(ctx); } else { ctx.intento?.kill(); ctx.senal.visible = false; }
        },

        estadoFinal(ctx) { ctx.timeline?.progress(1, true).pause(); },
    });
})(window.SARD = window.SARD || {});
