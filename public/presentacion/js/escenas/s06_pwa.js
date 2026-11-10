/*
 * S06_pwa — «Reportar sin señal» (STORYBOARD §S06 · 75 s). [DEMO en vivo al final]
 *
 * Objetivo: el jurado ve «Pendiente» y después «Recibido por la central», y entiende que lo
 * segundo es una confirmación (ACK de 17 B), no un mensaje.
 *
 * El teléfono es una RÉPLICA no interactiva con los literales reales de la PWA (content/es.js).
 * Animación → qué explica:
 *   · El contenido del teléfono se desplaza como al usarlo: tipo → banderas → personas → nota.
 *   · El contador de bytes es un cálculo real: UTF-8 de la nota + 36 B fijos.
 *   · Sin señal: la niebla se cierra sobre el ciudadano y el estado queda «Pendiente».
 *   · Vuelve la señal: el paquete viaja al faro (la central) y el ACK verde regresa; solo
 *     entonces el estado dice «Recibido por la central».
 * Interacción: «Simular sin red» repite el envío; «Ver qué lleva el paquete» muestra el desglose.
 */
(function (SARD) {
    'use strict';

    const { Util } = SARD;
    const COLOR_CIUDADANO = 0x8fb8d8;
    const POS_CIUDADANO = { x: 8, z: -1 }; // a la profundidad del faro: ambos entran en el hueco

    const bytesUtf8 = (texto) => new TextEncoder().encode(texto).length;

    function crearCiudadano() {
        const g = new THREE.Group();
        g.position.set(POS_CIUDADANO.x, SARD.Terreno.altura(POS_CIUDADANO.x, POS_CIUDADANO.z) + 1.2, POS_CIUDADANO.z);
        const punto = new THREE.Mesh(SARD.M3.geo('Sphere', [0.35, 16, 12]),
            new THREE.MeshStandardMaterial({ color: COLOR_CIUDADANO, emissive: COLOR_CIUDADANO, emissiveIntensity: 1.6 }));
        const halo = new THREE.Sprite(new THREE.SpriteMaterial({ map: SARD.M3.textura('halo'), color: COLOR_CIUDADANO, transparent: true, opacity: 0.6, blending: THREE.AdditiveBlending, depthWrite: false, fog: false }));
        halo.scale.set(4, 4, 1);
        g.add(punto, halo);
        g.userData = { punto, halo };
        return g;
    }

    function crearSprite(color) {
        const s = new THREE.Sprite(new THREE.SpriteMaterial({ map: SARD.M3.textura('particula'), color, transparent: true, opacity: 0, blending: THREE.AdditiveBlending, depthWrite: false, fog: false }));
        s.scale.set(1.4, 1.4, 1);
        return s;
    }

    SARD.Escenas.definir({
        id: 'S06_pwa',

        escenario: { encuadre: null, niebla: 0.02, linterna: 2.6, baliza: true },

        /** El 3D vive entre la tarjeta y el teléfono. */
        zonaLibre(seccion, { arriba, alto, margen }) {
            const a = seccion.querySelector('.s06__panel').getBoundingClientRect();
            const b = seccion.querySelector('.s06__telefono').getBoundingClientRect();
            return { x: a.right + margen, y: arriba, w: Math.max(0, b.left - a.right - 2 * margen), h: alto };
        },

        plantilla(t) {
            const P = t.pwa;
            const nNota = bytesUtf8(P.notaEjemplo);
            const tipos = P.tipos.map(([icono, nombre], i) => Util.el('div', { class: 's06__tipo', dataset: { i: String(i) } }, [
                Util.el('span', { html: SARD.Iconos.html(icono) }), Util.el('span', { text: nombre }),
            ]));
            const banderas = P.banderas.map(([nombre, critica], i) => Util.el('span', { class: 's06__bandera', dataset: { i: String(i) } }, [
                critica ? Util.el('span', { class: 's06__critica' }) : null,
                Util.el('span', { text: nombre }),
                critica ? Util.el('span', { class: 'sr-only', text: P.urgente }) : null,
            ]));

            const telefono = Util.el('div', { class: 's06__telefono', role: 'group', 'aria-label': t.replica }, [
                Util.el('div', { class: 's06__cabecera' }, [
                    Util.el('span', { class: 's06__marca', text: P.marca }),
                    Util.el('span', { class: 's06__titulo-pwa', text: P.titulo }),
                    Util.el('span', { class: 's06__senal', dataset: { estado: 'con' } }, [Util.el('span', { class: 's06__punto' }), Util.el('span', { class: 's06__senal-texto', text: P.conSenal })]),
                ]),
                Util.el('div', { class: 's06__pantalla' }, Util.el('div', { class: 's06__contenido' }, [
                    Util.el('p', { class: 's06__pregunta', text: P.preguntaTipo }),
                    Util.el('div', { class: 's06__tipos' }, tipos),
                    Util.el('div', { class: 's06__bloque s06__bloque--banderas' }, [
                        Util.el('p', { class: 's06__subpregunta', text: P.preguntaBanderas }),
                        Util.el('p', { class: 's06__ayuda', text: P.ayudaBanderas }),
                        Util.el('div', { class: 's06__banderas' }, banderas),
                    ]),
                    Util.el('div', { class: 's06__bloque s06__personas' }, [
                        Util.el('div', {}, [Util.el('p', { class: 's06__subpregunta', text: P.personas }), Util.el('p', { class: 's06__ayuda', text: P.ayudaPersonas })]),
                        Util.el('output', { class: 's06__personas-valor dato', text: P.noSe }),
                    ]),
                    Util.el('div', { class: 's06__bloque s06__bloque--nota' }, [
                        Util.el('p', { class: 's06__subpregunta', text: P.nota }),
                        Util.el('p', { class: 's06__nota' }, [Util.el('span', { class: 's06__nota-texto' }), Util.el('span', { class: 's06__cursor' })]),
                        Util.el('p', { class: 's06__ayuda dato s06__nota-bytes', text: Util.plantilla(t.bytesNota, { n: 0 }) }),
                    ]),
                ])),
                Util.el('div', { class: 's06__pie' }, [
                    Util.el('div', { class: 's06__enviar' }, [Util.el('span', { html: SARD.Iconos.html('envio') }), Util.el('span', { text: P.enviar.replace(':tipo', P.tipos[P.tipoElegido][1]) })]),
                    Util.el('p', { class: 's06__pill', dataset: { estado: 'ninguno' } }, [Util.el('span', { class: 's06__pill-icono' }), Util.el('span', { class: 's06__pill-texto' })]),
                    Util.el('p', { class: 's06__pie-texto', text: P.pieEnviar }),
                ]),
            ]);

            const idDesglose = 's06-desglose';
            return Util.el('section', { class: 's06' }, [
                Util.el('div', { class: 'panel panel--compacto pila s06__panel' }, [
                    Util.el('h1', { class: 's06__h1', text: t.h1 }),
                    Util.el('p', { class: 'tarjeta tarjeta--2 s06__callout' }, [Util.el('span', { html: SARD.Iconos.html('escudo') }), Util.el('span', { text: t.callout })]),
                    Util.el('div', { class: 'pila s06__estado', style: 'gap:8px' }, [
                        Util.el('p', { class: 'label', text: t.estadoTitulo }),
                        Util.el('p', { class: 's06__bytes metrica__valor', text: Util.plantilla(t.bytes, { n: 0, total: 36 }), dataset: { n: String(nNota) } }),
                        Util.el('div', { class: 's06__chips' }, t.estado.map((e) => Util.el('span', { class: 'chip chip--mono', text: e }))),
                        Util.el('div', { class: 's06__barra', 'aria-hidden': 'true' }, Util.el('div', { class: 's06__barra-relleno' })),
                    ]),
                    Util.el('div', { class: 's06__acciones' }, [
                        Util.el('button', { type: 'button', class: 'btn btn--secundario s06__simular', html: `${SARD.Iconos.html('sin-senal')}<span>${Util.escapar(t.simular)}</span>` }),
                        Util.el('button', { type: 'button', class: 'btn btn--fantasma s06__ver-desglose', 'aria-expanded': 'false', 'aria-controls': idDesglose, text: t.desgloseBoton }),
                    ]),
                    Util.el('div', { id: idDesglose, class: 's06__desglose', hidden: true }, [
                        Util.el('p', { class: 'dato', text: t.desglose }),
                        Util.el('p', { class: 'caption', text: t.maximo }),
                    ]),
                ]),
                telefono,
            ]);
        },

        cargar(ctx) {
            const s = ctx.seccion;
            ctx.escuchar(s.querySelector('.s06__simular'), 'click', () => this.enviar(ctx));
            ctx.escuchar(s.querySelector('.s06__ver-desglose'), 'click', (e) => {
                const b = e.currentTarget;
                const abierto = b.getAttribute('aria-expanded') !== 'true';
                b.setAttribute('aria-expanded', String(abierto));
                s.querySelector('#s06-desglose').hidden = !abierto;
            });
            const g = ctx.grupo3D('ciudadano');
            if (!g) { return; }
            ctx.ciudadano = crearCiudadano();
            ctx.paquete = crearSprite(0xc8d8e8);
            ctx.ack = crearSprite(0x4ade80);
            g.add(ctx.ciudadano, ctx.paquete, ctx.ack);
        },

        activar(ctx) {
            const s = ctx.seccion;
            const t = ctx.textos;
            const contenido = s.querySelector('.s06__contenido');
            const n = Number(s.querySelector('.s06__bytes').dataset.n);
            if (ctx.hay3D()) {
                const f = SARD.Faro.grupo.position;
                const c = ctx.ciudadano.position;
                // ancho: ciudadano y faro; alto: la torre entera (el paquete viaja hasta la linterna).
                SARD.Motor.encuadrar({ pos: [(c.x + f.x) / 2 + 4, 16, 34], objetivo: [(c.x + f.x) / 2, 8, (c.z + f.z) / 2], ancho: 22, alto: 20 }, { duracion: 1.6 });
                // El ciudadano «emite»: pulso suave del halo.
                SARD.Anim.loop(ctx, ctx.reducido() ? null : gsap.to(ctx.ciudadano.userData.halo.scale, { x: 5, y: 5, duration: 1.2, yoyo: true, repeat: -1, ease: 'sine.inOut' }));
            }

            const desplazarA = (sel) => -(s.querySelector(sel).offsetTop - 8);
            const texto = { n: 0 };
            const bytes = { n: 0 };
            SARD.Anim.entrar(ctx, () => gsap.timeline({ paused: true })
                .from(s.querySelector('.s06__panel'), { x: -24, autoAlpha: 0, duration: 0.6, ease: 'power3.out' }, 0)
                .from(s.querySelector('.s06__telefono'), { y: 40, autoAlpha: 0, duration: 0.7, ease: 'power3.out' }, 0)
                // Toque 1: el tipo.
                .from(s.querySelectorAll('.s06__tipo'), { scale: 0.6, autoAlpha: 0, duration: 0.3, ease: 'back.out(2)', stagger: 0.06 }, 0.7)
                .call(() => s.querySelector(`.s06__tipo[data-i="${t.pwa.tipoElegido}"]`).classList.add('elegido'), null, 1.5)
                // Banderas (opcional).
                .to(contenido, { y: () => desplazarA('.s06__bloque--banderas'), duration: 0.5, ease: 'power2.inOut' }, 1.8)
                .from(s.querySelectorAll('.s06__bandera'), { scale: 0.5, autoAlpha: 0, duration: 0.28, ease: 'back.out(2)', stagger: 0.035 }, 1.9)
                .call(() => t.pwa.banderasElegidas.forEach((i) => s.querySelector(`.s06__bandera[data-i="${i}"]`).classList.add('elegido')), null, 2.6)
                // Personas (opcional).
                .to(contenido, { y: () => desplazarA('.s06__personas'), duration: 0.5, ease: 'power2.inOut' }, 2.9)
                .call(() => { s.querySelector('.s06__personas-valor').textContent = String(t.pwa.personasElegidas); }, null, 3.4)
                // Nota: se escribe y el contador de bytes sube al valor real (UTF-8).
                .to(contenido, { y: () => desplazarA('.s06__bloque--nota'), duration: 0.5, ease: 'power2.inOut' }, 3.6)
                .to(texto, {
                    n: t.pwa.notaEjemplo.length, duration: 0.8, ease: 'none', snap: { n: 1 },
                    onUpdate: () => {
                        const parcial = t.pwa.notaEjemplo.slice(0, texto.n);
                        s.querySelector('.s06__nota-texto').textContent = parcial;
                        s.querySelector('.s06__nota-bytes').textContent = Util.plantilla(t.bytesNota, { n: bytesUtf8(parcial) });
                    },
                }, 4.0)
                .to(bytes, { n, duration: 0.8, ease: 'power1.out', snap: { n: 1 }, onUpdate: () => { s.querySelector('.s06__bytes').textContent = Util.plantilla(t.bytes, { n: bytes.n, total: 36 + bytes.n }); } }, 4.0)
                // Toque final: enviar → primero sin señal, luego vuelve.
                .call(() => this.enviar(ctx), null, 5.0));
            // Al volver a la escena el teléfono muestra el estado final (recibido).
            if (ctx.timeline.progress() === 1 && !ctx.envio) { this.enviar(ctx, true); }
        },

        /**
         * Simula el envío: sin señal → «Pendiente»; vuelve la señal → «Enviando…» → paquete al
         * faro → ACK verde de regreso → «Recibido por la central».
         * @param {boolean} [inmediato] aplica el estado final sin animación
         */
        enviar(ctx, inmediato = false) {
            const s = ctx.seccion;
            const P = ctx.textos.pwa;
            const senal = s.querySelector('.s06__senal');
            const pill = s.querySelector('.s06__pill');
            const relleno = s.querySelector('.s06__barra-relleno');
            const ponerSenal = (con) => {
                senal.dataset.estado = con ? 'con' : 'sin';
                senal.querySelector('.s06__senal-texto').textContent = con ? P.conSenal : P.sinRed;
            };
            const ponerEstado = (estado, texto) => {
                pill.dataset.estado = estado;
                pill.querySelector('.s06__pill-texto').textContent = texto;
                pill.querySelector('.s06__pill-icono').innerHTML = SARD.Iconos.html(estado === 'recibido' ? 'check' : (estado === 'pendiente' ? 'reloj' : 'envio'));
            };
            ctx.envio?.kill();

            if (inmediato || ctx.reducido()) {
                ponerSenal(true);
                ponerEstado('recibido', P.recibido);
                gsap.set(relleno, { scaleX: 1 });
                ctx.anunciar(ctx.textos.anuncioRecibido);
                return;
            }

            const hay3D = ctx.hay3D() && ctx.ciudadano;
            const tl = gsap.timeline();
            // 1. Sin señal: el reporte queda en el teléfono; la niebla envuelve al ciudadano.
            tl.call(() => { ponerSenal(false); ponerEstado('pendiente', P.pendiente); ctx.anunciar(ctx.textos.anuncioPendiente); }, null, 0)
                .set(relleno, { scaleX: 0 }, 0);
            if (hay3D) {
                tl.to(ctx.ciudadano.userData.halo.material, { opacity: 0.12, duration: 0.5, ease: 'power2.out' }, 0)
                    .call(() => SARD.Niebla.a(0.034, 0.6), null, 0);
            }
            // 2. Vuelve la señal (2 s después): se envía.
            tl.call(() => { ponerSenal(true); ponerEstado('enviando', P.enviando); }, null, 2.0)
                .to(relleno, { scaleX: 1, duration: 0.6, ease: 'power2.out' }, 2.1);
            if (hay3D) {
                const desde = ctx.ciudadano.position.clone();
                const hasta = new THREE.Vector3();
                SARD.Faro.linterna.getWorldPosition(hasta);
                const curva = new THREE.QuadraticBezierCurve3(desde, desde.clone().lerp(hasta, 0.5).add(new THREE.Vector3(0, 6, 0)), hasta);
                const viaje = { t: 0 };
                const regreso = { t: 1 };
                tl.call(() => SARD.Niebla.a(0.018, 0.7), null, 2.0)
                    .to(ctx.ciudadano.userData.halo.material, { opacity: 0.6, duration: 0.5 }, 2.0)
                    // El paquete viaja a velocidad constante (son datos): 900 ms.
                    .set(ctx.paquete.material, { opacity: 1 }, 2.1)
                    .to(viaje, { t: 1, duration: 0.9, ease: 'none', onUpdate: () => ctx.paquete.position.copy(curva.getPoint(viaje.t)) }, 2.1)
                    .to(ctx.paquete.material, { opacity: 0, duration: 0.2 }, 3.0)
                    // El ACK (verde) regresa: 600 ms.
                    .set(ctx.ack.material, { opacity: 1 }, 3.0)
                    .to(regreso, { t: 0, duration: 0.6, ease: 'power2.out', onUpdate: () => ctx.ack.position.copy(curva.getPoint(regreso.t)) }, 3.0)
                    .to(ctx.ack.material, { opacity: 0, duration: 0.25 }, 3.6);
            }
            // 3. Solo con el ACK, «Recibido por la central».
            tl.call(() => { ponerEstado('recibido', P.recibido); ctx.anunciar(ctx.textos.anuncioRecibido); }, null, hay3D ? 3.6 : 3.0);
            ctx.envio = tl;
        },

        estadoFinal(ctx) {
            ctx.timeline?.progress(1).pause();
            this.enviar(ctx, true);
        },

        pausar(ctx) { ctx.timeline?.pause(); ctx.envio?.pause(); },
        reanudar(ctx) { ctx.timeline?.resume(); ctx.envio?.resume(); },

        salir(ctx) { ctx.envio?.progress(1); },
    });
})(window.SARD = window.SARD || {});
