/*
 * S10_resiliencia — «Cuando el líder cae» (STORYBOARD §S10 · 45 s).
 *
 * Objetivo: que el jurado diga «otro nodo toma el mando en unos diez segundos».
 *
 * Animación → qué explica:
 *   · Latido B → C cada 1 s mientras B vive.
 *   · «Matar al líder»: el faro (B) se apaga, el contador llega a 10 latidos perdidos
 *     (ACELERADO: 0,15 s por latido en pantalla; el rótulo dice el valor real, 1 s) y C
 *     enciende su linterna y su baliza #ff750f; un anillo de época se expande desde C.
 *     C escribe un evento que B no tiene (LiderAsumido): su pila de log crece.
 *   · «Traerlo de vuelta»: B vuelve como SEGUIDOR (época mayor en C); el evento que le faltaba
 *     viaja de la pila de C a la de B (unión por UUID): las dos pilas quedan iguales, 0 perdidos.
 *     El latido ahora va de C a B. La niebla se disipa: todo vuelve a la normalidad.
 * Estado (DOM, se conserva al volver): data-fase = inicial | caido | unido.
 */
(function (SARD) {
    'use strict';

    const { Util } = SARD;
    const CAPAS_INICIALES = 6;
    const ALTO_CAPA = 0.16;
    const NARANJA = 0xff750f;

    function crearPila(posicion, n) {
        const g = new THREE.Group();
        g.position.copy(posicion);
        const mat = SARD.M3.mat('capa-log', { color: 0x3e3e3a, emissive: 0xc8d8e8, emissiveIntensity: 0.15, roughness: 0.5, metalness: 0.4 });
        const capas = Array.from({ length: n }, (_, i) => {
            const c = new THREE.Mesh(SARD.M3.geo('Cylinder', [0.7, 0.7, 0.12, 24]), mat);
            c.position.y = 0.1 + i * ALTO_CAPA;
            c.castShadow = true;
            g.add(c);
            return c;
        });
        g.userData.capas = capas;
        return g;
    }

    function nuevaCapa(pila, visible = true) {
        const c = new THREE.Mesh(SARD.M3.geo('Cylinder', [0.7, 0.7, 0.12, 24]), new THREE.MeshStandardMaterial({ color: 0x3e3e3a, emissive: 0x4ade80, emissiveIntensity: 0.9, roughness: 0.5 }));
        c.position.y = 0.1 + pila.userData.capas.length * ALTO_CAPA;
        c.visible = visible;
        pila.add(c);
        pila.userData.capas.push(c);
        return c;
    }

    SARD.Escenas.definir({
        id: 'S10_resiliencia',

        escenario: { encuadre: null, niebla: 0.024, linterna: 2.2, baliza: true },

        plantilla(t, escena) {
            return Util.el('section', { class: 's10', dataset: { fase: 'inicial' } }, [
                Util.el('div', { class: 'panel panel--izquierda panel--compacto pila s10__panel' }, [
                    Util.el('h1', { class: 's10__h1', text: t.h1 }),
                    Util.el('button', { type: 'button', class: 's10__contador', 'aria-expanded': 'false', 'aria-controls': 's10-detalle', on: { click: (e) => {
                        const b = e.currentTarget; const abierto = b.getAttribute('aria-expanded') !== 'true';
                        b.setAttribute('aria-expanded', String(abierto)); document.getElementById('s10-detalle').hidden = !abierto;
                    } } }, [
                        Util.el('span', { class: 's10__grande dato', text: t.contador }),
                        Util.el('span', { class: 's10__vivo dato' }, [Util.el('span', { class: 's10__n', text: '0' }), ` / 10 ${t.perdidos}`]),
                    ]),
                    Util.el('p', { id: 's10-detalle', class: 'caption dato', hidden: true, text: t.contadorDetalle }),
                    Util.el('ol', { class: 's10__estados' }, t.estados.map((e, i) => Util.el('li', { class: 's10__estado', dataset: { i: String(i) }, text: e }))),
                    Util.el('div', { class: 's10__acciones' }, [
                        Util.el('button', { type: 'button', class: 'btn btn--primario s10__matar', text: t.matar, on: { click: () => this.matar(escena.ctx) } }),
                        Util.el('button', { type: 'button', class: 'btn btn--secundario s10__revivir', disabled: true, text: t.revivir, on: { click: () => this.revivir(escena.ctx) } }),
                    ]),
                    Util.el('p', { class: 'caption', text: t.honestidad }),
                    Util.el('div', { class: 'codigo' }, [Util.el('span', { text: t.comandoRotulo }), Util.el('code', { text: t.comando })]),
                ]),
            ]);
        },

        cargar(ctx) {
            const g = ctx.grupo3D('torres');
            if (!g) { return; }
            const T = SARD.Actores.topologia();
            ctx.T = T;
            const gemela = SARD.Faro.crearGemela(T.C);
            ctx.gemela = gemela;
            const haciaCamara = new THREE.Vector3(0, 0, 3.2);
            ctx.pilaB = crearPila(T.B.clone().add(new THREE.Vector3(2.4, 1.2, 3)), CAPAS_INICIALES);
            ctx.pilaC = crearPila(T.C.clone().add(haciaCamara), CAPAS_INICIALES);
            const latido = SARD.Actores.crearLinea(T.B, T.C, 0xc8d8e8, { arco: 4, opacidad: 0.5 });
            ctx.curvaLatido = latido.curva;
            ctx.pulso = SARD.Actores.crearPulso(0xc8d8e8, 1.3);
            ctx.anilloEpoca = new THREE.Mesh(SARD.M3.geo('Ring', [0.9, 1.1, 48]), new THREE.MeshBasicMaterial({ color: NARANJA, transparent: true, opacity: 0, side: THREE.DoubleSide, depthWrite: false }));
            ctx.anilloEpoca.rotation.x = -Math.PI / 2;
            ctx.anilloEpoca.position.copy(T.C).add(new THREE.Vector3(0, 0.3, 0));
            g.add(gemela.torre, ctx.pilaB, ctx.pilaC, latido.tubo, ctx.pulso, ctx.anilloEpoca);
        },

        activar(ctx) {
            const s = ctx.seccion;
            if (ctx.hay3D()) {
                const { B, C } = ctx.T;
                // alto: las dos torres enteras (≈ 17 m sobre la roca) con aire para el HUD.
                const centro = [(B.x + C.x) / 2, (B.y + C.y) / 2 + 7.5, (B.z + C.z) / 2];
                SARD.Motor.encuadrar({ pos: [centro[0] + 1, centro[1] + 3, centro[2] + 26], objetivo: centro, ancho: 22, alto: 23 }, { duracion: 1.6 });
            }
            this.aplicarFase(ctx, s.dataset.fase);
            SARD.Anim.entrar(ctx, () => gsap.timeline({ paused: true })
                .from(s.querySelector('.s10__h1'), { y: -12, autoAlpha: 0, duration: 0.55, ease: 'power2.out' })
                .from(s.querySelectorAll('.s10__contador, .s10__estado, .s10__acciones'), { autoAlpha: 0, y: 8, duration: 0.35, stagger: 0.08 }, '-=0.2'));
        },

        estadoFinal(ctx) { ctx.timeline?.progress(1, true).pause(); },

        /** Latido continuo desde el líder actual hacia el seguidor (cada 1 s). */
        latir(ctx, desdeB) {
            ctx.latido?.kill();
            if (!ctx.pulso || ctx.reducido()) { return; }
            const p = { t: 0 };
            const punto = (v) => ctx.pulso.position.copy(ctx.curvaLatido.getPoint(desdeB ? v : 1 - v));
            ctx.latido = SARD.Anim.loop(ctx, gsap.timeline({ repeat: -1, repeatDelay: 0.55, delay: 0.4 })
                .set(ctx.pulso.material, { opacity: 1 })
                .fromTo(p, { t: 0 }, { t: 1, duration: 0.45, ease: 'none', onUpdate: () => punto(p.t) })
                .set(ctx.pulso.material, { opacity: 0 }));
        },

        /** Aplica sin animación el estado de una fase (al volver a la escena o con reduced-motion). */
        aplicarFase(ctx, fase) {
            const s = ctx.seccion;
            s.dataset.fase = fase;
            const activos = { inicial: [0], caido: [0, 1, 2], unido: [0, 1, 2, 3, 4] }[fase];
            s.querySelectorAll('.s10__estado').forEach((li, i) => {
                li.classList.toggle('hecho', activos.includes(i));
                li.classList.toggle('actual', i === activos[activos.length - 1]);
            });
            s.querySelector('.s10__n').textContent = fase === 'inicial' ? '0' : '10';
            s.querySelector('.s10__matar').disabled = fase === 'caido';
            s.querySelector('.s10__revivir').disabled = fase !== 'caido';
            if (!ctx.gemela) { return; }
            const caido = fase === 'caido';
            const cLider = fase !== 'inicial';
            // B (el faro): apagado solo mientras está caído. C: líder desde que asume.
            SARD.Faro.intensidad(caido ? 0 : 2.2, 0);
            SARD.Faro.baliza(fase === 'inicial', 0);
            ctx.gemela.linterna.material.emissiveIntensity = cLider ? 2.6 : 0.6;
            ctx.gemela.resplandor.material.opacity = cLider ? 0.45 : 0.12;
            ctx.gemela.balizaMesh.material.emissiveIntensity = cLider ? 1.4 : 0;
            // Pilas de log: C tiene 7 capas desde que asume; B, 7 desde que se unen.
            while (ctx.pilaC.userData.capas.length < (cLider ? CAPAS_INICIALES + 1 : CAPAS_INICIALES)) { nuevaCapa(ctx.pilaC); }
            while (ctx.pilaB.userData.capas.length < (fase === 'unido' ? CAPAS_INICIALES + 1 : CAPAS_INICIALES)) { nuevaCapa(ctx.pilaB); }
            ctx.anilloEpoca.material.opacity = 0;
            SARD.Niebla.a(fase === 'unido' ? 0.01 : 0.024, 0);
            if (caido) { ctx.latido?.kill(); } else { this.latir(ctx, fase === 'inicial'); }
        },

        /** Bloques 1 y 2: muere B, se cuentan 10 latidos, C asume con época 2. */
        matar(ctx) {
            const s = ctx.seccion;
            if (s.dataset.fase === 'unido') { this.aplicarFase(ctx, 'inicial'); this.reiniciarPilas(ctx); }
            if (ctx.reducido()) { this.aplicarFase(ctx, 'caido'); ctx.anunciar(ctx.textos.anuncioMuerte); return; }
            ctx.latido?.kill();
            s.querySelector('.s10__matar').disabled = true;
            const contador = { n: 0 };
            const estado = (i) => () => {
                s.querySelectorAll('.s10__estado').forEach((li, k) => { li.classList.toggle('hecho', k <= i); li.classList.toggle('actual', k === i); });
            };
            const tl = gsap.timeline({ onComplete: () => { this.aplicarFase(ctx, 'caido'); ctx.anunciar(ctx.textos.anuncioMuerte); } })
                .call(estado(1), null, 0);
            if (ctx.gemela) {
                tl.add(SARD.Faro.intensidad(0, 0.4, 'power2.out'), 0)
                    .add(SARD.Faro.baliza(false, 0.4), 0)
                    .call(() => SARD.Niebla.a(0.024, 0.4), null, 0);
            }
            // 10 latidos acelerados (0,15 s cada uno); el rótulo dice el valor real.
            tl.to(contador, { n: 10, duration: 1.5, ease: 'none', snap: { n: 1 }, onUpdate: () => { s.querySelector('.s10__n').textContent = String(contador.n); } }, 0.1)
                .call(estado(2), null, 1.7);
            if (ctx.gemela) {
                const capa = nuevaCapa(ctx.pilaC, false);
                tl.to(ctx.gemela.linterna.material, { emissiveIntensity: 2.6, duration: 0.6, ease: 'power2.out' }, 1.7)
                    .to(ctx.gemela.resplandor.material, { opacity: 0.45, duration: 0.6 }, 1.7)
                    .to(ctx.gemela.balizaMesh.material, { emissiveIntensity: 1.4, duration: 1.2, ease: 'back.out(1.6)' }, 1.7)
                    .fromTo(ctx.anilloEpoca.scale, { x: 0.2, y: 0.2 }, { x: 3, y: 3, duration: 1.2, ease: 'power2.out' }, 1.8)
                    .fromTo(ctx.anilloEpoca.material, { opacity: 0.9 }, { opacity: 0, duration: 1.2, ease: 'power2.out' }, 1.8)
                    // C escribe LiderAsumido: una capa que B no tiene.
                    .call(() => { capa.visible = true; }, null, 2.2)
                    .from(capa.scale, { x: 0.01, z: 0.01, duration: 0.3, ease: 'back.out(2)' }, 2.2);
            }
            ctx.registrarLoop(tl);
        },

        /** Bloque 3: B vuelve como seguidor y la capa que le faltaba viaja desde C (unión por UUID). */
        revivir(ctx) {
            const s = ctx.seccion;
            if (ctx.reducido()) { this.aplicarFase(ctx, 'unido'); ctx.anunciar(ctx.textos.anuncioVuelta); return; }
            s.querySelector('.s10__revivir').disabled = true;
            const estado = (i) => () => {
                s.querySelectorAll('.s10__estado').forEach((li, k) => { li.classList.toggle('hecho', k <= i); li.classList.toggle('actual', k === i); });
            };
            const tl = gsap.timeline({ onComplete: () => { this.aplicarFase(ctx, 'unido'); ctx.anunciar(ctx.textos.anuncioVuelta); } })
                .call(estado(3), null, 0);
            if (ctx.gemela) {
                // B se enciende como seguidor (sin baliza de líder).
                tl.add(SARD.Faro.intensidad(2.2, 0.6, 'power2.out'), 0)
                    .call(() => SARD.Niebla.a(0.01, 1.2), null, 0.2);
                // La capa que falta viaja de la pila de C a la de B.
                const origen = ctx.pilaC.userData.capas[ctx.pilaC.userData.capas.length - 1];
                const destinoPila = ctx.pilaB;
                const capa = nuevaCapa(destinoPila, false);
                const inicio = destinoPila.worldToLocal(origen.getWorldPosition(new THREE.Vector3()));
                const final = capa.position.clone();
                const medio = inicio.clone().lerp(final, 0.5).add(new THREE.Vector3(0, 3, 0));
                const curva = new THREE.QuadraticBezierCurve3(inicio, medio, final);
                const v = { t: 0 };
                tl.call(() => { capa.visible = true; }, null, 0.4)
                    .to(v, { t: 1, duration: 0.9, ease: 'power2.inOut', onUpdate: () => capa.position.copy(curva.getPoint(v.t)) }, 0.4);
            }
            tl.call(estado(4), null, 1.4)
                .fromTo(s.querySelector('.s10__estado[data-i="4"]'), { scale: 0.92 }, { scale: 1, duration: 0.4, ease: 'back.out(2)' }, 1.4);
            ctx.registrarLoop(tl);
        },

        /** Vuelve las pilas a 6 capas (para repetir la demostración). */
        reiniciarPilas(ctx) {
            if (!ctx.pilaB) { return; }
            [ctx.pilaB, ctx.pilaC].forEach((pila) => {
                while (pila.userData.capas.length > CAPAS_INICIALES) {
                    const c = pila.userData.capas.pop();
                    pila.remove(c);
                    if (!SARD.M3.esCompartido(c.material)) { c.material.dispose(); }
                }
            });
        },
    });
})(window.SARD = window.SARD || {});
