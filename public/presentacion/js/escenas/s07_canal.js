/*
 * S07_canal — «Cuando el enlace se degrada» (STORYBOARD §S07 · 90 s).
 *
 * Objetivo: que el jurado diga «reintenta el emisor, no el canal».
 *
 * Simulación (no una animación decorativa): cada 0,75 s el emisor (nodo A) envía un mensaje
 * por el anillo hacia el líder. Con la probabilidad de pérdida del CANAL real (30 %, semilla
 * 42 → reproducible) el mensaje se desvanece al 60 % del trayecto y cae en la niebla. El canal
 * no hace nada más: es EL EMISOR quien reintenta, con la serie real 1 → 2 → 4 → 8 → 16 → 30 s.
 *   · Trayecto a velocidad constante (900 ms, ease none): son datos.
 *   · Pérdida visible (fundido de 400 ms y caída), no un «pop».
 *   · Repetidores ámbar en el anillo: multisalto (1–3 saltos).
 *   · Contadores ENVIADOS / PERDIDOS / ENTREGADOS cambian en el mismo frame que el evento.
 * «Perder un mensaje» sube la pérdida a 60 % (rotulado como demostración) y «Recuperar el
 * enlace» la devuelve al 30 %. Las ventanas 5 s / 15 s no se simulan (se rotula).
 */
(function (SARD) {
    'use strict';

    const { Util } = SARD;
    const CENTRO = { x: 0, y: 3, z: -36 };
    const RADIO = 11;
    const PERDIDA_REAL = 0.30;
    const PERDIDA_ROTA = 0.60;
    const TICK = 0.25;           // reloj de la simulación (s)
    const CADA_EMISION = 3;      // un mensaje nuevo cada 3 ticks = 0,75 s
    const TRAYECTO = 0.9;        // s, velocidad constante
    const POOL = 14;

    /** Punto del semicírculo (t: 0 emisor → 1 destino), por el lado lejano del anillo. */
    function puntoArco(t, destino = new THREE.Vector3()) {
        const a = Math.PI - t * Math.PI;
        return destino.set(CENTRO.x + Math.cos(a) * RADIO, CENTRO.y + 0.4, CENTRO.z - Math.sin(a) * RADIO);
    }

    function crearCanal() {
        const g = new THREE.Group();
        const anillo = new THREE.Mesh(SARD.M3.geo('Torus', [RADIO, 0.08, 8, 96]),
            new THREE.MeshStandardMaterial({ color: 0x3e3e3a, emissive: 0x1d1d1b, transparent: true, opacity: 0.55, roughness: 0.8 }));
        anillo.rotation.x = Math.PI / 2;
        anillo.position.set(CENTRO.x, CENTRO.y, CENTRO.z);
        g.add(anillo);

        // Repetidores (multisalto): postes con baliza ámbar en el arco.
        const balizaAmbar = SARD.M3.mat('baliza-ambar', { color: 0xfbbf24, emissive: 0xfbbf24, emissiveIntensity: 1.2 });
        [0.25, 0.5, 0.75].forEach((t) => {
            const p = puntoArco(t);
            const poste = new THREE.Mesh(SARD.M3.geo('Cylinder', [0.08, 0.1, 1.6, 8]), SARD.M3.mat('torre'));
            poste.position.set(p.x, CENTRO.y + 0.8, p.z);
            const luz = new THREE.Mesh(SARD.M3.geo('Sphere', [0.18, 12, 8]), balizaAmbar);
            luz.position.set(p.x, CENTRO.y + 1.7, p.z);
            g.add(poste, luz);
        });

        // Emisor (nodo A): cubo con anillo. Destino (líder): torre baja con acento.
        const pe = puntoArco(0);
        const emisor = new THREE.Mesh(SARD.M3.geo('Box', [1, 1, 1]), new THREE.MeshStandardMaterial({ color: 0x232322, emissive: 0xc8d8e8, emissiveIntensity: 0.15 }));
        emisor.scale.set(1.4, 1.4, 1.4);
        emisor.position.set(pe.x, CENTRO.y + 0.7, pe.z);
        const pd = puntoArco(1);
        const destino = new THREE.Mesh(SARD.M3.geo('Cylinder', [0.6, 0.9, 3, 16]), new THREE.MeshStandardMaterial({ color: 0x232322, emissive: 0xff4433, emissiveIntensity: 0.25 }));
        destino.position.set(pd.x, CENTRO.y + 1.5, pd.z);
        g.add(emisor, destino);

        const sprites = Array.from({ length: POOL }, () => {
            const s = new THREE.Sprite(new THREE.SpriteMaterial({ map: SARD.M3.textura('particula'), color: 0xc8d8e8, transparent: true, opacity: 0, blending: THREE.AdditiveBlending, depthWrite: false, fog: false }));
            s.scale.set(1.1, 1.1, 1);
            s.userData.libre = true;
            g.add(s);
            return s;
        });
        return { g, emisor, destino, sprites };
    }

    SARD.Escenas.definir({
        id: 'S07_canal',

        escenario: { encuadre: { pos: [0, 27, -20], objetivo: [CENTRO.x, CENTRO.y, CENTRO.z], ancho: 27 }, niebla: 0.026, linterna: 1.8, baliza: true },

        /** El anillo vive entre la columna de parámetros y la de reintentos. */
        zonaLibre(seccion, { arriba, alto, margen }) {
            const a = seccion.querySelector('.s07__izq').getBoundingClientRect();
            const b = seccion.querySelector('.s07__der').getBoundingClientRect();
            return { x: a.right + margen, y: arriba, w: Math.max(0, b.left - a.right - 2 * margen), h: alto };
        },

        plantilla(t, escena) {
            const pasos = (t.serie.match(/\d+/g) || []).map(Number);
            const idJitter = 's07-jitter';
            return Util.el('section', { class: 's07' }, [
                Util.el('div', { class: 'panel panel--compacto pila s07__izq' }, [
                    Util.el('h1', { class: 's07__h1', text: t.h1 }),
                    Util.el('h2', { class: 'label', text: t.parametrosTitulo }),
                    Util.el('dl', { class: 's07__parametros' }, t.parametros.map(([k, v], i) => Util.el('div', { class: 's07__param', dataset: { i: String(i) } }, [
                        Util.el('dt', { class: 'metrica__label', text: k }),
                        Util.el('dd', { class: 'dato', text: v }),
                    ]))),
                    Util.el('div', { class: 's07__acciones' }, [
                        Util.el('button', { type: 'button', class: 'btn btn--secundario s07__romper', 'aria-pressed': 'false', text: t.romper, on: { click: () => this.fijarPerdida(escena.ctx, true) } }),
                        Util.el('button', { type: 'button', class: 'btn btn--fantasma s07__recuperar', disabled: true, text: t.recuperar, on: { click: () => this.fijarPerdida(escena.ctx, false) } }),
                    ]),
                    Util.el('p', { class: 'caption s07__honestidad', hidden: true, role: 'status', text: t.honestidad }),
                ]),
                Util.el('div', { class: 'panel panel--compacto pila s07__der' }, [
                    Util.el('div', { class: 'tarjeta tarjeta--2 pila s07__reintentos', style: 'gap:8px' }, [
                        Util.el('h2', { class: 'label', text: t.reintentosTitulo }),
                        Util.el('button', { type: 'button', class: 's07__serie dato', 'aria-expanded': 'false', 'aria-controls': idJitter, on: { click: (e) => {
                            const b = e.currentTarget; const abierto = b.getAttribute('aria-expanded') !== 'true';
                            b.setAttribute('aria-expanded', String(abierto)); document.getElementById(idJitter).hidden = !abierto;
                        } } }, pasos.flatMap((p, i) => [
                            i ? Util.el('span', { class: 's07__flecha', 'aria-hidden': 'true', text: ' → ' }) : null,
                            Util.el('span', { class: 's07__paso', dataset: { i: String(i) }, text: String(p) }),
                        ]).concat([Util.el('span', { text: ' s' })])),
                        Util.el('p', { id: idJitter, class: 'caption', hidden: true, text: t.jitter }),
                        Util.el('p', { class: 's07__clave', text: t.clave }),
                        Util.el('p', { class: 'caption s07__evento', 'aria-live': 'off' }),
                    ]),
                    Util.el('div', { class: 's07__contadores' }, t.contadores.map((c, i) => Util.el('div', { class: 'metrica tarjeta s07__contador' }, [
                        Util.el('span', { class: 'metrica__label', text: c }),
                        Util.el('span', { class: 'metrica__valor', dataset: { c: String(i) }, text: '0' }),
                    ]))),
                    Util.el('p', { class: 'caption s07__nota', text: t.nota }),
                ]),
            ]);
        },

        cargar(ctx) {
            // Al volver se conserva el nivel de pérdida elegido (INTERACTION_SPEC §2.5): se lee del DOM.
            const roto = ctx.seccion.querySelector('.s07__romper').getAttribute('aria-pressed') === 'true';
            ctx.sim = { perdida: roto ? PERDIDA_ROTA : PERDIDA_REAL, azar: SARD.M3.aleatorio(42), cola: [], tick: 0, enviados: 0, perdidos: 0, entregados: 0 };
            const g = ctx.grupo3D('canal');
            if (!g) { return; }
            Object.assign(ctx, crearCanal());
            g.add(ctx.g);
        },

        activar(ctx) {
            const s = ctx.seccion;
            SARD.Anim.entrar(ctx, () => gsap.timeline({ paused: true })
                .from(s.querySelector('.s07__h1'), { y: -14, autoAlpha: 0, duration: 0.6, ease: 'power2.out' })
                .from(s.querySelectorAll('.s07__param'), { autoAlpha: 0, x: 12, duration: 0.35, ease: 'power1.out', stagger: 0.07 }, '-=0.2')
                .from(s.querySelector('.s07__reintentos'), { scale: 0.94, autoAlpha: 0, duration: 0.5, ease: 'power2.out' }, '-=0.3')
                .from(s.querySelectorAll('.s07__contador'), { autoAlpha: 0, y: 10, duration: 0.35, stagger: 0.08 }, '-=0.2'));

            if (ctx.hay3D()) { SARD.Anim.loop(ctx, SARD.Motor.derivaOrbital(6, 22, 1.7)); }
            // Reloj de la simulación (se mata al salir con los demás loops).
            SARD.Anim.loop(ctx, gsap.timeline({ repeat: -1, delay: 1.2 }).call(() => this.paso(ctx), null, TICK));
        },

        estadoFinal(ctx) { ctx.timeline?.progress(1, true).pause(); },

        /** Un tick del reloj: emite cada 0,75 s y lanza los reintentos vencidos. */
        paso(ctx) {
            const sim = ctx.sim;
            sim.tick += 1;
            const ahora = sim.tick * TICK;
            const vencidos = sim.cola.filter((r) => r.vence <= ahora);
            sim.cola = sim.cola.filter((r) => r.vence > ahora);
            vencidos.forEach((r) => this.emitir(ctx, r.intento));
            if (sim.tick % CADA_EMISION === 0) { this.emitir(ctx, 0); }
        },

        /** Emite un mensaje (intento 0 = nuevo; >0 = reintento del EMISOR). */
        emitir(ctx, intento) {
            const sim = ctx.sim;
            const s = ctx.seccion;
            sim.enviados += 1;
            this.pintarContadores(ctx);
            const perdido = sim.azar() < sim.perdida;
            const sprite = ctx.sprites?.find((x) => x.userData.libre);
            const animar = sprite && !ctx.reducido();

            const alPerderse = () => {
                sim.perdidos += 1;
                this.pintarContadores(ctx);
                // El canal no reintenta: el emisor agenda el reintento con la serie real.
                const serie = (ctx.textos.serie.match(/\d+/g) || [1]).map(Number);
                const i = Math.min(intento, serie.length - 1);
                sim.cola.push({ vence: sim.tick * TICK + serie[i], intento: intento + 1 });
                s.querySelector('.s07__evento').textContent = Util.plantilla(ctx.textos.reintentoEn, { s: serie[i] });
                s.querySelectorAll('.s07__paso').forEach((p, k) => p.classList.toggle('activo', k === i));
                if (ctx.emisor && !ctx.reducido()) { gsap.fromTo(ctx.emisor.material, { emissiveIntensity: 1.2 }, { emissiveIntensity: 0.15, duration: 0.6 }); }
            };
            const alEntregarse = () => {
                sim.entregados += 1;
                this.pintarContadores(ctx);
                if (ctx.destino && !ctx.reducido()) { gsap.fromTo(ctx.destino.material, { emissiveIntensity: 1.4 }, { emissiveIntensity: 0.25, duration: 0.5 }); }
            };

            if (!animar) {
                if (perdido) { alPerderse(); } else { alEntregarse(); }
                return;
            }
            sprite.userData.libre = false;
            sprite.material.color.setHex(intento > 0 ? 0xfbbf24 : 0xc8d8e8); // los reintentos, en ámbar
            const viaje = { t: 0 };
            const p = new THREE.Vector3();
            const tl = gsap.timeline({ onComplete: () => { sprite.material.opacity = 0; sprite.userData.libre = true; } });
            tl.set(sprite.material, { opacity: 1 })
                .to(viaje, { t: perdido ? 0.6 : 1, duration: TRAYECTO * (perdido ? 0.6 : 1), ease: 'none', onUpdate: () => sprite.position.copy(puntoArco(viaje.t, p)) });
            if (perdido) {
                // Se desvanece al 60 % y cae en la niebla (400 ms).
                tl.call(alPerderse)
                    .to(sprite.material, { opacity: 0, duration: 0.4, ease: 'power1.in' })
                    .to(sprite.position, { y: '-=2', duration: 0.4, ease: 'power1.in' }, '<');
            } else {
                tl.call(alEntregarse)
                    .to(sprite.material.color, { r: 0.29, g: 0.87, b: 0.5, duration: 0.15 })
                    .to(sprite.material, { opacity: 0, duration: 0.2 });
            }
            ctx.registrarLoop(tl);
            // Poda: los timelines terminados no se acumulan en el registro de loops.
            if (ctx.loops.length > 60) { ctx.loops = ctx.loops.filter((x) => x.isActive() || x.repeat?.() === -1); }
        },

        pintarContadores(ctx) {
            const { enviados, perdidos, entregados } = ctx.sim;
            const v = ctx.seccion.querySelectorAll('.s07__contador .metrica__valor');
            v[0].textContent = String(enviados);
            v[1].textContent = String(perdidos);
            v[2].textContent = String(entregados);
        },

        /** Sube la pérdida a 60 % (demostración) o la devuelve al 30 % real. */
        fijarPerdida(ctx, roto) {
            const s = ctx.seccion;
            ctx.sim.perdida = roto ? PERDIDA_ROTA : PERDIDA_REAL;
            const dd = s.querySelector('.s07__param[data-i="0"] dd');
            dd.textContent = roto ? ctx.textos.perdida60 : ctx.textos.parametros[0][1];
            s.querySelector('.s07__param[data-i="0"]').classList.toggle('alterado', roto);
            s.querySelector('.s07__romper').setAttribute('aria-pressed', String(roto));
            s.querySelector('.s07__romper').disabled = roto;
            s.querySelector('.s07__recuperar').disabled = !roto;
            s.querySelector('.s07__honestidad').hidden = !roto;
            (roto ? s.querySelector('.s07__recuperar') : s.querySelector('.s07__romper')).focus();
            ctx.anunciar(roto ? ctx.textos.anuncioRoto : ctx.textos.anuncioRecuperado);
            if (!ctx.reducido()) { gsap.fromTo(dd, { scale: 1.15 }, { scale: 1, duration: 0.3, ease: 'power2.out' }); }
        },

        salir(ctx) {
            ctx.sprites?.forEach((sp) => { sp.material.opacity = 0; sp.userData.libre = true; });
        },
    });
})(window.SARD = window.SARD || {});
