/*
 * router.js — navegación entre escenas, transiciones, historial y teclado.
 *
 * Secuencia de transición (INTERACTION_SPEC §1.3):
 *   bloquear input → origen.pausar → salida 450 ms → origen.desactivar → destino.cargar
 *   → destino.activar → entrada 600 ms (no bloquea) → HUD → anunciar → historial → LRU.
 * La navegación la dan las teclas; los botones visibles son su equivalente accesible.
 */
(function (SARD) {
    'use strict';

    const { Estado, Escenas, Util } = SARD;
    const HISTORIAL_MAX = 20;
    const historial = [];
    let escenaActual = null;

    const reducido = () => Estado.get('reduccionMovimiento');

    const DURACION_SALIDA = 0.45;

    /**
     * Salida de 450 ms. El tween avanza con requestAnimationFrame; si el navegador lo frena
     * (pestaña en segundo plano, máquina cargada), un temporizador de seguridad completa la
     * salida para que el input nunca quede bloqueado más de lo previsto (INTERACTION_SPEC §4.2).
     */
    function salida(seccion) {
        if (!seccion || reducido() || !window.gsap) { return Promise.resolve(); }
        return new Promise((ok) => {
            let hecho = false;
            const terminar = () => {
                if (hecho) { return; }
                hecho = true;
                tween.kill();
                gsap.set(seccion, { autoAlpha: 0 });
                ok();
            };
            const tween = gsap.to(seccion, { autoAlpha: 0, scale: 1.02, duration: DURACION_SALIDA, ease: 'power1.in', onComplete: terminar });
            setTimeout(terminar, DURACION_SALIDA * 1000 + 120);
        });
    }

    function entrada(seccion) {
        if (!window.gsap) { return; }
        gsap.killTweensOf(seccion);
        if (reducido()) {
            gsap.set(seccion, { autoAlpha: 1, clearProps: 'transform' });
            return;
        }
        gsap.fromTo(seccion,
            { autoAlpha: 0, y: 12, scale: 0.98 },
            { autoAlpha: 1, y: 0, scale: 1, duration: 0.6, ease: 'power2.out', clearProps: 'transform' });
    }

    function actualizarHash(id) {
        try { history.replaceState(null, '', `#${id}`); } catch (e) { /* file:// restringido: se ignora */ }
    }

    const Router = {
        actual: () => escenaActual,

        /**
         * Navega a una escena por id o índice.
         * @param {string|number} destino
         * @param {{historial?: boolean}} [opciones]
         */
        async irA(destino, opciones = {}) {
            if (Estado.get('navegando')) { return; }
            const siguiente = Escenas.obtener(destino);
            if (!siguiente || siguiente === escenaActual) { return; }

            Estado.set('navegando', true);
            const origen = escenaActual;
            const temporizadorOcupado = setTimeout(() => SARD.HUD.ocupado(true), 150);
            try {
                if (origen) {
                    Escenas.pausar(origen);
                    const focoDentro = origen.seccion.contains(document.activeElement);
                    Escenas.salir(origen);
                    await salida(origen.seccion);
                    Escenas.desactivar(origen);
                    Estado.get('completadas').add(origen.id);
                    if (focoDentro) { document.getElementById('contenido').focus({ preventScroll: true }); }
                    if (opciones.historial !== false) {
                        historial.push(origen.id);
                        if (historial.length > HISTORIAL_MAX) { historial.shift(); }
                    }
                }

                // Cada escena empieza sin pausa del presentador.
                Estado.set('pausado', false);

                await Escenas.cargar(siguiente);
                Escenas.activar(siguiente);
                escenaActual = siguiente;
                entrada(siguiente.seccion);

                Estado.set('escenaActual', siguiente.id);
                Estado.set('indiceActual', siguiente.orden);
                Estado.set('capituloActual', siguiente.capitulo);
                Estado.get('visitadas').add(siguiente.id);

                SARD.HUD.alEntrar(siguiente);
                const cap = SARD.contenido.capitulos[siguiente.capitulo];
                SARD.HUD.anunciar(Util.plantilla(SARD.contenido.ui.anuncio.escena, {
                    titulo: siguiente.titulo, n: cap.n, capitulo: cap.titulo,
                }));
                actualizarHash(siguiente.id);
                Escenas.aplicarPoliticaMemoria(siguiente.id);
            } catch (e) {
                SARD.Errores.registrar('router', `${String(destino)}: ${e.message}`);
            } finally {
                clearTimeout(temporizadorOcupado);
                SARD.HUD.ocupado(false);
                Estado.set('navegando', false);
            }
        },

        /** Siguiente: primero los pasos internos de la escena; en la última, abre la demo. */
        siguiente() {
            if (Estado.get('navegando') || !escenaActual) { return; }
            if (Escenas.llamar(escenaActual, 'avanzarPaso') === true) { return; }
            if (Escenas.llamar(escenaActual, 'alSiguiente') === true) { return; }
            const total = Escenas.todas().length;
            if (escenaActual.orden >= total - 1) {
                SARD.HUD.abrirDemo();
                return;
            }
            Router.irA(escenaActual.orden + 1);
        },

        anterior() {
            if (Estado.get('navegando') || !escenaActual) { return; }
            if (Escenas.llamar(escenaActual, 'retrocederPaso') === true) { return; }
            if (escenaActual.orden > 0) { Router.irA(escenaActual.orden - 1); }
        },

        /** Deshace el último salto (historial de 20). */
        volver() {
            const id = historial.pop();
            if (id) { Router.irA(id, { historial: false }); }
        },

        capitulo(n) {
            const e = Escenas.primeraDelCapitulo(n);
            if (e) { Router.irA(e.id); }
        },

        alternarPausa() {
            if (!escenaActual) { return; }
            const pausar = !Estado.get('pausado');
            Estado.set('pausado', pausar);
            if (pausar) { Escenas.pausar(escenaActual); } else { Escenas.reanudar(escenaActual); }
            SARD.HUD.anunciar(pausar ? SARD.contenido.ui.anuncio.pausa : SARD.contenido.ui.anuncio.reanudar);
        },

        alternarControles() {
            const ocultar = !Estado.get('controlesOcultos');
            Estado.set('controlesOcultos', ocultar);
            SARD.HUD.anunciar(ocultar ? SARD.contenido.ui.anuncio.controlesOcultos : SARD.contenido.ui.anuncio.controlesVisibles);
        },

        async pantallaCompleta() {
            try {
                if (document.fullscreenElement) { await document.exitFullscreen(); }
                else { await document.documentElement.requestFullscreen(); }
            } catch (e) {
                SARD.HUD.aviso(SARD.contenido.ui.avisos.pantallaCompleta);
            }
        },

        reiniciar() {
            SARD.HUD.confirmarReinicio(() => {
                Estado.get('completadas').clear();
                Estado.get('visitadas').clear();
                historial.length = 0;
                Router.irA(0, { historial: false });
            });
        },

        iniciar() {
            // La demo pausa la animación de la escena (no la oculta) y la reanuda al cerrar.
            Estado.on('demoAbierta', (abierta) => {
                if (!escenaActual || Estado.get('pausado')) { return; }
                if (abierta) { Escenas.pausar(escenaActual); } else { Escenas.reanudar(escenaActual); }
            });

            window.addEventListener('keydown', Router.teclado);

            // Ensayo: escribir #S07_canal en la barra de direcciones salta a esa escena.
            window.addEventListener('hashchange', () => {
                const id = decodeURIComponent(location.hash.slice(1));
                if (Escenas.obtener(id)) { Router.irA(id); }
            });
        },

        /** Mapa de atajos (INTERACTION_SPEC §2.4). */
        teclado(e) {
            if (e.defaultPrevented || e.ctrlKey || e.metaKey || e.altKey) { return; }
            // Con un diálogo abierto manda el diálogo (Esc lo cierra de forma nativa).
            if (SARD.HUD.hayDialogoAbierto()) { return; }
            if (Util.esCampoTexto(document.activeElement)) { return; }

            const activo = document.activeElement;
            const enControl = activo && activo !== document.body && /^(BUTTON|A|SUMMARY)$/.test(activo.tagName);
            const tecla = e.key;

            const acciones = {
                ArrowRight: () => Router.siguiente(),
                PageDown: () => Router.siguiente(),
                ' ': () => Router.siguiente(),
                ArrowLeft: () => Router.anterior(),
                PageUp: () => Router.anterior(),
                Home: () => Router.irA(0),
                End: () => Router.irA(Escenas.todas().length - 1),
                Escape: () => SARD.HUD.abrirIndice(),
                m: () => SARD.HUD.abrirIndice(),
                d: () => SARD.HUD.abrirDemo(),
                p: () => Router.alternarPausa(),
                h: () => Router.alternarControles(),
                f: () => Router.pantallaCompleta(),
                r: () => Router.reiniciar(),
                '?': () => SARD.HUD.abrirAtajos(),
            };

            // Espacio sobre un botón debe pulsar el botón, no avanzar la escena.
            if (tecla === ' ' && enControl) { return; }

            if (/^[1-8]$/.test(tecla)) {
                e.preventDefault();
                Router.capitulo(Number(tecla) - 1);
                return;
            }

            const accion = acciones[tecla] || acciones[tecla.toLowerCase?.()];
            if (accion) {
                e.preventDefault();
                accion();
            }
        },
    };

    SARD.Router = Router;
})(window.SARD = window.SARD || {});
