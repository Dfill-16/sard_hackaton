/*
 * escenas.js — registro y ciclo de vida de las escenas (INTERACTION_SPEC §1).
 *
 * Contrato de una definición de escena (todos los métodos son opcionales):
 *   plantilla(textos, escena) → HTMLElement   DOM de la escena; se crea una sola vez y se conserva.
 *   cargar(ctx)      → Promise|void           crea timelines, objetos 3D y listeners (vía ctx.escuchar).
 *   activar(ctx)                              la escena pasa a pantalla: arranca su timeline.
 *   pausar(ctx) / reanudar(ctx)               pausa del presentador o de la demo.
 *   salir(ctx)                                al abandonarla: corre junto al fundido de salida.
 *   destruir(ctx)                             libera GPU, timelines y listeners.
 *   estadoFinal(ctx)                          aplica el estado final (reduced-motion / fallos).
 *   avanzarPaso(ctx) / retrocederPaso(ctx) → boolean   pasos internos (S04, S08, S13). true = consumido.
 *   alSiguiente(ctx) → boolean                comportamiento propio de «siguiente» (S16 → demo).
 *
 * Equivalencia con el prompt: init = cargar, enter = activar, pause = pausar, leave = salir,
 * destroy = destruir.
 *
 * Estados: NO_CARGADA → CARGANDO → CARGADA ⇄ ACTIVADA ⇄ PAUSADA → DESTRUIDA (→ CARGANDO).
 */
(function (SARD) {
    'use strict';

    const ESTADOS = Object.freeze({
        NO_CARGADA: 'NO_CARGADA',
        CARGANDO: 'CARGANDO',
        CARGADA: 'CARGADA',
        ACTIVADA: 'ACTIVADA',
        PAUSADA: 'PAUSADA',
        DESTRUIDA: 'DESTRUIDA',
    });

    /** Máximo de escenas con recursos materializados (INTERACTION_SPEC §1.4). */
    const MAX_MATERIALIZADAS = 3;

    const definiciones = new Map();
    /** @type {Array<object>} */
    let lista = [];
    const usoReciente = []; // ids, el más reciente al final

    const reducido = () => SARD.Estado.get('reduccionMovimiento');

    function crearContexto(escena) {
        const abortar = new AbortController();
        const ctx = {
            escena,
            textos: escena.textos,
            contenido: SARD.contenido,
            seccion: escena.seccion,
            timeline: null,
            loops: [],
            modoFallback: false,
            senal: abortar.signal,
            abortar,
            reducido,
            /** Duración efectiva: 0 con reduced-motion. */
            dur: (s) => (reducido() ? 0 : s),
            /** Registra un tween/timeline en bucle para matarlo al salir (evita tweens zombis). */
            registrarLoop(tween) { ctx.loops.push(tween); return tween; },
            /** addEventListener que se elimina solo al destruir la escena. */
            escuchar(objetivo, evento, fn, opciones = {}) {
                objetivo.addEventListener(evento, fn, { ...opciones, signal: abortar.signal });
            },
            anunciar: (texto) => SARD.HUD.anunciar(texto),
            avisar: (texto, tipo) => SARD.HUD.aviso(texto, tipo),

            // ---- 3D (null / no-op en modo texto) ----
            grupos: [],
            anclas: [],
            /** true si hay motor 3D funcionando. */
            hay3D: () => !!(SARD.Motor && SARD.Motor.activo && !SARD.Estado.get('modoTexto')),
            /** Grupo 3D propio de la escena: se oculta al salir y se libera al destruir. */
            grupo3D(nombre = escena.id) {
                if (!ctx.hay3D()) { return null; }
                const g = SARD.Motor.crearGrupo(`${escena.id}:${nombre}`);
                g.visible = escena.estado === ESTADOS.ACTIVADA || escena.estado === ESTADOS.PAUSADA;
                ctx.grupos.push(g);
                return g;
            },
            /** Ancla un botón HTML a un objeto 3D; se suelta al salir de la escena. */
            anclar(objeto, boton, opciones) {
                if (!ctx.hay3D() || !objeto) { return; }
                ctx.anclas.push(SARD.Anclas.anclar(objeto, boton, opciones));
            },
        };
        return ctx;
    }

    /** Escenario 3D por defecto mientras una escena no declare el suyo (def.escenario). */
    const ESCENARIO_BASE = { niebla: 0.022, linterna: 2.2, baliza: true, duracionCamara: 1.6 };

    /**
     * Aplica cámara, niebla, linterna, baliza y órbita declaradas por la escena (o las de base).
     * encuadre: null deja la cámara a cargo de la propia escena (p. ej. el dolly de S00).
     * La órbita manual se habilita cuando termina el movimiento de cámara.
     */
    /** Alto reservado arriba (HUD) y abajo (controles), en px. */
    const RESERVA = { arriba: 64, abajo: 88, margen: 24 };

    /**
     * Mide la tarjeta principal de la escena y devuelve el hueco libre para el 3D ({x,y,w,h}):
     * a la derecha de una tarjeta izquierda, a la izquierda de una derecha, o encima de una
     * franja inferior (tablet). En móvil el 3D es solo decorativo: sin zona.
     */
    function medirZonaLibre(escena) {
        const seccion = escena.seccion;
        const W = window.innerWidth;
        const H = window.innerHeight;
        if (W < 768) { return null; }
        const alto = H - RESERVA.arriba - RESERVA.abajo;
        // Escenas con dos columnas declaran su hueco (p. ej. entre la tarjeta y el teléfono).
        if (typeof escena.def.zonaLibre === 'function' && W >= 1024) {
            return escena.def.zonaLibre(seccion, { arriba: RESERVA.arriba, alto, margen: RESERVA.margen });
        }
        const panel = seccion.querySelector(':scope > .panel, :scope > .pila');
        if (!panel) { return { x: 0, y: RESERVA.arriba, w: W, h: alto }; }
        const r = panel.getBoundingClientRect();
        if (!r.width || !r.height) { return null; }
        if (r.top > H * 0.35) {                              // franja inferior
            return { x: 0, y: RESERVA.arriba, w: W, h: r.top - RESERVA.arriba - RESERVA.margen };
        }
        if (r.left < W * 0.25 && r.right < W * 0.7) {        // tarjeta a la izquierda
            const x = r.right + RESERVA.margen;
            return { x, y: RESERVA.arriba, w: W - x - RESERVA.margen, h: alto };
        }
        if (r.right > W * 0.75 && r.left > W * 0.3) {        // tarjeta a la derecha
            return { x: RESERVA.margen, y: RESERVA.arriba, w: r.left - 2 * RESERVA.margen, h: alto };
        }
        return { x: 0, y: RESERVA.arriba, w: W, h: alto };   // tarjeta centrada o sin hueco claro
    }

    function aplicarEscenario(escena) {
        if (!escena.ctx.hay3D()) { return; }
        const e = { ...ESCENARIO_BASE, ...(escena.def.escenario || {}) };
        SARD.Motor.zonaLibre(medirZonaLibre(escena));
        SARD.Motor.detenerOrbita();
        const tween = e.encuadre === null
            ? null
            : SARD.Motor.encuadrar(e.encuadre || SARD.Motor.PLANO_GENERAL, { duracion: e.duracionCamara });
        // duracionAmbiente: cuánto tarda la niebla/linterna en llegar a su valor (S03: 2,2 s).
        SARD.Niebla.a(e.niebla, e.duracionAmbiente ?? 1.2);
        SARD.Faro.intensidad(e.linterna, e.duracionAmbiente ?? 1.2);
        SARD.Faro.baliza(e.baliza, 0.6);
        SARD.Faro.mostrarHaz(!!e.haz);
        document.getElementById('escena-3d').dataset.atenuado = e.atenuado ? 'si' : 'no';
        if (e.orbita) {
            const habilitar = () => {
                if (escena.estado === ESTADOS.ACTIVADA || escena.estado === ESTADOS.PAUSADA) { SARD.Motor.orbitar(e.orbita); }
            };
            if (tween) { tween.eventCallback('onComplete', habilitar); } else { habilitar(); }
        }
        SARD.Calidad.reiniciarMedicion();
    }

    function matarLoops(ctx) {
        ctx.loops.forEach((t) => { try { t.kill(); } catch (e) { /* ya muerto */ } });
        ctx.loops = [];
    }

    function llamar(escena, metodo) {
        const fn = escena.def[metodo];
        if (typeof fn !== 'function') { return undefined; }
        try {
            return fn.call(escena.def, escena.ctx);
        } catch (e) {
            SARD.Errores.registrar('escena', `${escena.id}.${metodo}: ${e.message}`);
            // Si una animación falla, se aplica el estado final para no dejar nada a medias.
            if (metodo !== 'estadoFinal') { llamar(escena, 'estadoFinal'); }
            return undefined;
        }
    }

    function tocarUso(id) {
        const i = usoReciente.indexOf(id);
        if (i >= 0) { usoReciente.splice(i, 1); }
        usoReciente.push(id);
    }

    const Escenas = {
        ESTADOS,

        /** Registra (o reemplaza) la definición de una escena. Las de H3 sustituyen a los esqueletos. */
        definir(def) {
            if (!def || !def.id) { throw new Error('Escena sin id'); }
            definiciones.set(def.id, def);
        },

        /** Construye la lista ordenada a partir de content/es.js (el orden del storyboard). */
        construir() {
            let acumulado = 0;
            lista = SARD.contenido.escenas.map((c, orden) => {
                const def = definiciones.get(c.id) || { id: c.id };
                const escena = {
                    id: c.id,
                    orden,
                    capitulo: c.capitulo,
                    titulo: c.titulo,
                    duracion: c.duracion,
                    inicio: acumulado,
                    descartable: c.descartable !== false,
                    textos: c.textos || {},
                    nota: c.nota || '',
                    def,
                    estado: ESTADOS.NO_CARGADA,
                    seccion: null,
                    ctx: null,
                    reloj: 0,
                };
                acumulado += c.duracion;
                return escena;
            });
            Escenas.total = acumulado;
            return lista;
        },

        total: 0,
        todas: () => lista,
        obtener: (idOIndice) => (typeof idOIndice === 'number' ? lista[idOIndice] : lista.find((e) => e.id === idOIndice)),
        indiceDe: (id) => lista.findIndex((e) => e.id === id),
        primeraDelCapitulo: (n) => lista.find((e) => e.capitulo === n),

        /** Crea el DOM (una vez) y los recursos de la escena. Nunca lanza: ante un fallo, modo fallback. */
        async cargar(escena) {
            if (escena.estado !== ESTADOS.NO_CARGADA && escena.estado !== ESTADOS.DESTRUIDA) { return; }
            escena.estado = ESTADOS.CARGANDO;

            if (!escena.seccion) {
                const plantilla = escena.def.plantilla || SARD.Esqueleto.plantilla;
                let seccion;
                try {
                    seccion = plantilla.call(escena.def, escena.textos, escena);
                } catch (e) {
                    SARD.Errores.registrar('plantilla', `${escena.id}: ${e.message}`);
                    seccion = SARD.Esqueleto.plantilla(escena.textos, escena);
                }
                seccion.classList.add('escena');
                seccion.id = `escena-${escena.id}`;
                seccion.dataset.escena = escena.id;
                seccion.dataset.capitulo = String(escena.capitulo);
                const h1 = seccion.querySelector('h1');
                if (h1) {
                    h1.id = h1.id || `${escena.id}-titulo`;
                    seccion.setAttribute('aria-labelledby', h1.id);
                }
                seccion.hidden = true;
                document.getElementById('contenido').append(seccion);
                escena.seccion = seccion;
            }

            escena.ctx = crearContexto(escena);
            try {
                await llamar(escena, 'cargar');
            } catch (e) {
                SARD.Errores.registrar('assets', `${escena.id}: ${e.message}`);
                escena.ctx.modoFallback = true;
                SARD.HUD.aviso(SARD.contenido.ui.avisos.cargaFallida, 'error');
            }
            escena.estado = ESTADOS.CARGADA;
        },

        activar(escena) {
            escena.seccion.hidden = false;
            escena.reloj = 0;
            escena.estado = ESTADOS.ACTIVADA;
            tocarUso(escena.id);
            escena.ctx.grupos.forEach((g) => { g.visible = true; });
            aplicarEscenario(escena);
            llamar(escena, 'activar');
            if (reducido()) { llamar(escena, 'estadoFinal'); }
        },

        pausar(escena) {
            if (!escena || escena.estado !== ESTADOS.ACTIVADA) { return; }
            if (typeof escena.def.pausar === 'function') { llamar(escena, 'pausar'); }
            else { escena.ctx.timeline?.pause(); }
            escena.estado = ESTADOS.PAUSADA;
        },

        reanudar(escena) {
            if (!escena || escena.estado !== ESTADOS.PAUSADA) { return; }
            if (typeof escena.def.reanudar === 'function') { llamar(escena, 'reanudar'); }
            else { escena.ctx.timeline?.resume(); }
            escena.estado = ESTADOS.ACTIVADA;
        },

        /**
         * Animación de salida propia de la escena: corre A LA VEZ que el fundido de 450 ms del
         * router (antes de ocultar la sección). El router no espera más allá del fundido.
         */
        salir(escena) {
            llamar(escena, 'salir');
        },

        /** Sale de pantalla: la escena queda CARGADA (DOM oculto, sin loops ni render). */
        desactivar(escena) {
            matarLoops(escena.ctx);
            escena.ctx.timeline?.pause();
            escena.ctx.anclas.forEach((soltar) => soltar());
            escena.ctx.anclas = [];
            escena.ctx.grupos.forEach((g) => { g.visible = false; });
            escena.seccion.hidden = true;
            escena.estado = ESTADOS.CARGADA;
        },

        /** Libera timelines, loops, listeners y (en H2) GPU. El DOM se conserva: es texto, ~2 KB. */
        destruir(escena) {
            if (escena.estado === ESTADOS.NO_CARGADA || escena.estado === ESTADOS.DESTRUIDA) { return; }
            llamar(escena, 'destruir');
            matarLoops(escena.ctx);
            if (escena.ctx.timeline) {
                escena.ctx.timeline.kill();
                escena.ctx.timeline = null;
            }
            escena.ctx.abortar.abort();
            escena.ctx.anclas.forEach((soltar) => soltar());
            // Libera la GPU: geometrías, materiales y texturas propios (los de M3 se conservan).
            escena.ctx.grupos.forEach((g) => SARD.Motor?.retirar(g));
            escena.ctx.grupos = [];
            escena.estado = ESTADOS.DESTRUIDA;
            const i = usoReciente.indexOf(escena.id);
            if (i >= 0) { usoReciente.splice(i, 1); }
        },

        /** Política LRU: como máximo 3 escenas materializadas; S09 y S13 no se descartan. */
        aplicarPoliticaMemoria(idActiva) {
            const vivas = usoReciente.filter((id) => {
                const e = Escenas.obtener(id);
                return e && e.estado !== ESTADOS.DESTRUIDA && e.estado !== ESTADOS.NO_CARGADA;
            });
            let sobrantes = vivas.length - MAX_MATERIALIZADAS;
            for (const id of vivas) {
                if (sobrantes <= 0) { break; }
                const e = Escenas.obtener(id);
                if (id === idActiva || !e.descartable) { continue; }
                Escenas.destruir(e);
                sobrantes--;
            }
        },

        /** Llama a un método opcional de la escena; devuelve su resultado. */
        llamar,

        /**
         * Tras un redimensionado: vuelve a medir la tarjeta de la escena activa y re-encuadra
         * para que la figura siga entera en el hueco libre. Lo invoca el motor.
         */
        reajustarZona() {
            const activa = lista.find((e) => e.estado === ESTADOS.ACTIVADA || e.estado === ESTADOS.PAUSADA);
            if (!activa || !activa.ctx?.hay3D()) { return; }
            SARD.Motor.zonaLibre(medirZonaLibre(activa));
            SARD.Motor.reencuadrar();
        },
    };

    SARD.Escenas = Escenas;
})(window.SARD = window.SARD || {});
