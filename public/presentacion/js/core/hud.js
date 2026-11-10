/*
 * hud.js — barra de progreso, miga, estado, controles visibles y diálogos
 * (índice, atajos, demo en vivo, confirmar reinicio), avisos y anuncios accesibles.
 */
(function (SARD) {
    'use strict';

    const { Util, Estado } = SARD;
    const $ = (id) => document.getElementById(id);
    let C; // SARD.contenido.ui
    let ultimoValorAria = -1;
    let focoPrevio = null;

    /** Rellena textos y aria-labels declarados con data-i18n / data-i18n-aria. */
    function traducir(raiz = document) {
        Util.$$('[data-i18n]', raiz).forEach((n) => { n.textContent = Util.ruta(C, n.dataset.i18n) ?? n.textContent; });
        Util.$$('[data-i18n-aria]', raiz).forEach((n) => { n.setAttribute('aria-label', Util.ruta(C, n.dataset.i18nAria) ?? ''); });
    }

    function cabeceraDialogo(idTitulo, titulo, textoCerrar) {
        return Util.el('div', { class: 'dialogo__cabecera' }, [
            Util.el('h2', { id: idTitulo, text: titulo }),
            Util.el('button', {
                type: 'button', class: 'btn btn--fantasma btn--icono', 'aria-label': textoCerrar,
                html: SARD.Iconos.html('cerrar'),
                on: { click: (e) => e.currentTarget.closest('dialog').close() },
            }),
        ]);
    }

    /** Abre un diálogo modal recordando el foco previo para devolverlo al cerrar. */
    function abrir(dialogo) {
        if (dialogo.open) { return; }
        Util.$$('dialog[open]').forEach((d) => d.close());
        focoPrevio = document.activeElement;
        dialogo.showModal();
    }

    function alCerrarDialogo() {
        if (focoPrevio && document.contains(focoPrevio) && !focoPrevio.closest('[hidden]')) {
            focoPrevio.focus({ preventScroll: true });
        }
        focoPrevio = null;
    }

    // ---------------------------------------------------------------- Índice
    function construirIndice() {
        const dlg = $('dlg-indice');
        const actual = Estado.get('escenaActual');
        const completadas = Estado.get('completadas');
        const escenas = SARD.Escenas.todas();

        const capitulos = SARD.contenido.capitulos.map((cap) => {
            const suyas = escenas.filter((e) => e.capitulo === cap.n);
            const segundos = suyas.reduce((s, e) => s + e.duracion, 0);
            const items = suyas.map((e) => {
                const esActual = e.id === actual;
                const estado = esActual ? 'actual' : (completadas.has(e.id) ? 'completada' : 'pendiente');
                const etiqueta = estado === 'completada'
                    ? Util.plantilla(C.indice.completada, { titulo: e.titulo })
                    : (esActual ? Util.plantilla(C.indice.actual, { titulo: e.titulo }) : e.titulo);
                return Util.el('li', {}, Util.el('button', {
                    type: 'button',
                    class: 'indice__escena',
                    'aria-current': esActual ? 'page' : false,
                    'aria-label': etiqueta,
                    dataset: { estado },
                    on: { click: () => { dlg.close(); SARD.Router.irA(e.id); } },
                }, [
                    Util.el('span', { class: 'indice__marca', html: estado === 'completada' ? SARD.Iconos.html('check') : '' }),
                    Util.el('span', { text: e.titulo }),
                    Util.el('span', { class: 'caption dato', text: Util.plantilla(C.indice.minutos, { m: Util.minutos(e.duracion) }) }),
                ]));
            });
            return Util.el('section', { class: 'indice__capitulo', 'aria-labelledby': `cap-${cap.n}` }, [
                Util.el('div', { class: 'indice__cap-titulo' }, [
                    Util.el('h3', { id: `cap-${cap.n}`, class: 'label', text: `${Util.plantilla(C.indice.capitulo, { n: cap.n })} · ${cap.titulo}` }),
                    Util.el('span', { class: 'caption dato', text: Util.plantilla(C.indice.minutos, { m: Util.minutos(segundos) }) }),
                ]),
                Util.el('ol', { class: 'indice__escenas' }, items),
            ]);
        });

        const meta = SARD.contenido.meta;
        dlg.replaceChildren(
            cabeceraDialogo('dlg-indice-titulo', C.indice.titulo, C.indice.cerrar),
            Util.el('div', { class: 'dialogo__cuerpo' }, [
                Util.el('p', { class: 'caption', text: Util.plantilla(C.indice.total, { m: meta.minutosPresentacion, d: meta.minutosDemo }) }),
                Util.el('nav', { 'aria-labelledby': 'dlg-indice-titulo' }, capitulos),
                selectorCalidad(),
            ]),
        );
    }

    /** Selector manual de calidad (INTERACTION_SPEC §8.6): Auto / Alta / Media / Baja. */
    function selectorCalidad() {
        const I = C.indice;
        if (Estado.get('modoTexto')) {
            return Util.el('p', { class: 'caption', text: I.calidadSin3d });
        }
        const actual = Estado.get('calidad');
        const nivel = Util.el('p', { class: 'caption dato', text: Util.plantilla(I.calidadNivel, { nivel: I.calidadNiveles[Estado.get('nivelCalidad')] }) });
        const opciones = I.calidadModos.map(([valor, etiqueta]) => Util.el('label', { class: 'calidad__opcion' }, [
            Util.el('input', {
                type: 'radio', name: 'calidad', value: valor, checked: valor === actual,
                on: {
                    change: () => {
                        SARD.Calidad.fijar(valor);
                        nivel.textContent = Util.plantilla(I.calidadNivel, { nivel: I.calidadNiveles[Estado.get('nivelCalidad')] });
                    },
                },
            }),
            Util.el('span', { text: etiqueta }),
        ]));
        return Util.el('fieldset', { class: 'calidad' }, [
            Util.el('legend', { class: 'label', text: I.calidad }),
            Util.el('div', { class: 'calidad__opciones' }, opciones),
            Util.el('p', { class: 'caption', text: I.calidadAyuda }),
            nivel,
        ]);
    }

    // ---------------------------------------------------------------- Atajos
    function construirAtajos() {
        const dlg = $('dlg-atajos');
        const filas = C.atajos.lista.flatMap(([teclas, descripcion]) => [
            Util.el('dt', {}, teclas.map((t) => (t === '…' ? Util.el('span', { class: 'muted', text: '…' }) : Util.el('kbd', { text: t })))),
            Util.el('dd', { text: descripcion }),
        ]);
        const errores = SARD.Errores.lista();
        const tablaErrores = errores.length
            ? Util.el('table', { class: 'tabla' }, [
                Util.el('caption', { class: 'label', text: C.atajos.errores }),
                Util.el('tbody', {}, errores.map((e) => Util.el('tr', {}, [
                    Util.el('td', { class: 'dato', text: e.hora }),
                    Util.el('td', { class: 'dato', text: e.origen }),
                    Util.el('td', { text: e.mensaje }),
                ]))),
            ])
            : Util.el('p', { class: 'caption', text: C.atajos.sinErrores });

        dlg.replaceChildren(
            cabeceraDialogo('dlg-atajos-titulo', C.atajos.titulo, C.atajos.cerrar),
            Util.el('div', { class: 'dialogo__cuerpo' }, [Util.el('dl', { class: 'atajos' }, filas), tablaErrores]),
        );
    }

    // ---------------------------------------------------------------- Demo
    function botonCopiar(texto) {
        return Util.el('button', {
            type: 'button', class: 'btn btn--fantasma', text: C.demo.copiar,
            on: {
                click: async (e) => {
                    const boton = e.currentTarget;
                    const ok = await Util.copiar(texto);
                    if (!ok) { HUD.aviso(C.avisos.copiaFallida, 'error'); return; }
                    boton.textContent = C.demo.copiado;
                    HUD.anunciar(C.demo.copiado);
                    setTimeout(() => { boton.textContent = C.demo.copiar; }, 1600);
                },
            },
        });
    }

    function construirDemo() {
        const dlg = $('dlg-demo');
        const D = C.demo;
        const enlaces = Util.el('ul', { class: 'pila' }, D.enlaces.map((v) => Util.el('li', { class: 'tarjeta tarjeta--2 demo__vista' }, [
            Util.el('div', {}, [
                Util.el('p', { class: 'label', text: v.nodo }),
                Util.el('p', { text: v.nombre }),
                Util.el('p', { class: 'dato caption', text: v.url }),
            ]),
            Util.el('a', { class: 'btn btn--secundario', href: v.url, target: '_blank', rel: 'noopener', text: D.abrir, 'aria-label': `${D.abrir}: ${v.nombre}` }),
        ])));
        const guion = Util.el('ol', { class: 'lista-num' }, D.guion.map((p) => Util.el('li', {}, Util.el('span', { text: p }))));
        const comandos = Util.el('ul', { class: 'pila' }, D.lineasComando.map((c) => Util.el('li', { class: 'pila', style: 'gap:4px' }, [
            Util.el('span', { class: 'label', text: c.etiqueta }),
            Util.el('div', { class: 'codigo' }, [Util.el('code', { text: c.comando }), botonCopiar(c.comando)]),
        ])));

        dlg.replaceChildren(
            cabeceraDialogo('dlg-demo-titulo', D.titulo, D.cerrar),
            Util.el('div', { class: 'dialogo__cuerpo demo' }, [
                Util.el('p', { class: 'caption', text: D.intro }),
                Util.el('section', { class: 'pila', 'aria-labelledby': 'demo-vistas' }, [Util.el('h3', { id: 'demo-vistas', class: 'label', text: D.vistas }), enlaces]),
                Util.el('section', { class: 'pila', 'aria-labelledby': 'demo-guion' }, [Util.el('h3', { id: 'demo-guion', class: 'label', text: D.pasos }), guion]),
                Util.el('section', { class: 'pila', 'aria-labelledby': 'demo-comandos' }, [Util.el('h3', { id: 'demo-comandos', class: 'label', text: D.comandos }), comandos]),
            ]),
            Util.el('div', { class: 'dialogo__pie' }, Util.el('button', {
                type: 'button', class: 'btn btn--primario', text: D.cerrar,
                on: { click: () => dlg.close() },
            })),
        );
    }

    // ---------------------------------------------------------------- Confirmar
    function construirConfirmar(alAceptar) {
        const dlg = $('dlg-confirmar');
        const K = C.confirmar;
        dlg.replaceChildren(
            cabeceraDialogo('dlg-confirmar-titulo', K.titulo, K.no),
            Util.el('div', { class: 'dialogo__cuerpo' }, Util.el('p', { text: K.texto })),
            Util.el('div', { class: 'dialogo__pie' }, [
                Util.el('button', { type: 'button', class: 'btn btn--secundario', text: K.no, autofocus: true, on: { click: () => dlg.close() } }),
                Util.el('button', { type: 'button', class: 'btn btn--primario', text: K.si, on: { click: () => { dlg.close(); alAceptar(); } } }),
            ]),
        );
    }

    // ---------------------------------------------------------------- Progreso
    function bucleProgreso() {
        let ultimo = performance.now();
        const relleno = $('progreso-relleno');
        const barra = $('progreso');
        const frame = (ahora) => {
            requestAnimationFrame(frame);
            const dt = Math.min(0.1, (ahora - ultimo) / 1000);
            ultimo = ahora;
            const escena = SARD.Escenas.obtener(Estado.get('escenaActual') || '');
            if (!escena || document.hidden) { return; }
            const corre = escena.estado === SARD.Escenas.ESTADOS.ACTIVADA && !Estado.get('pausado') && !Estado.get('demoAbierta');
            if (corre) { escena.reloj = Math.min(escena.duracion, escena.reloj + dt); }
            const p = (escena.inicio + escena.reloj) / SARD.Escenas.total;
            relleno.style.transform = `scaleX(${p.toFixed(4)})`;
            const entero = Math.round(p * 100);
            if (entero !== ultimoValorAria) {
                ultimoValorAria = entero;
                barra.setAttribute('aria-valuenow', String(entero));
            }
        };
        requestAnimationFrame(frame);
    }

    function pintarEstado() {
        const badge = $('hud-estado');
        let clase = '';
        let texto = '';
        let pulso = 'no';
        if (Estado.get('demoAbierta')) { clase = 'badge--degradado'; texto = C.estados.demo; pulso = 'si'; }
        else if (Estado.get('pausado')) { clase = 'badge--degradado'; texto = C.estados.pausa; }
        else if (Estado.get('modoTexto')) { clase = 'badge--degradado'; texto = C.estados.modoTexto; }
        badge.hidden = !texto;
        badge.className = `badge ${clase}`;
        badge.dataset.pulso = pulso;
        badge.textContent = texto;
        $('hud').dataset.estado = Estado.get('demoAbierta') ? 'demo' : (Estado.get('pausado') ? 'pausado' : 'activo');
    }

    const HUD = {
        iniciar() {
            C = SARD.contenido.ui;
            traducir();
            $('progreso').setAttribute('aria-label', C.hud.progreso);
            $('controles').setAttribute('aria-label', C.nav.etiqueta);

            $('btn-indice').addEventListener('click', () => HUD.abrirIndice());
            $('btn-ayuda').addEventListener('click', () => HUD.abrirAtajos());
            $('btn-anterior').addEventListener('click', () => SARD.Router.anterior());
            $('btn-siguiente').addEventListener('click', () => SARD.Router.siguiente());

            Util.$$('dialog').forEach((d) => d.addEventListener('close', alCerrarDialogo));
            $('dlg-demo').addEventListener('close', () => Estado.set('demoAbierta', false));

            ['pausado', 'demoAbierta', 'modoTexto'].forEach((k) => Estado.on(k, pintarEstado));
            Estado.on('controlesOcultos', (v) => document.body.classList.toggle('controles-ocultos', v));
            pintarEstado();
            bucleProgreso();
        },

        /** Actualiza miga, modo de HUD y botones tras entrar en una escena. */
        alEntrar(escena) {
            const cap = SARD.contenido.capitulos[escena.capitulo];
            $('miga').textContent = Util.plantilla(C.hud.miga, { n: cap.n, capitulo: cap.titulo, titulo: escena.titulo });
            $('hud').dataset.modo = escena.orden === 0 ? 'minimo' : 'completo';
            const ultima = escena.orden === SARD.Escenas.todas().length - 1;
            $('btn-anterior').disabled = escena.orden === 0;
            $('btn-siguiente').textContent = ultima ? C.nav.aDemo : C.nav.siguiente;
        },

        /** Feedback visible mientras una escena prepara sus recursos. */
        ocupado(si) {
            const btn = $('btn-siguiente');
            btn.setAttribute('aria-busy', si ? 'true' : 'false');
            document.getElementById('contenido').setAttribute('aria-busy', si ? 'true' : 'false');
        },

        abrirIndice() { construirIndice(); abrir($('dlg-indice')); },
        abrirAtajos() { construirAtajos(); abrir($('dlg-atajos')); },
        abrirDemo() {
            construirDemo();
            Estado.set('demoAbierta', true);
            abrir($('dlg-demo'));
        },
        confirmarReinicio(alAceptar) { construirConfirmar(alAceptar); abrir($('dlg-confirmar')); },
        hayDialogoAbierto: () => Util.$$('dialog[open]').length > 0,

        anunciar(texto) {
            const nodo = $('anuncio');
            nodo.textContent = '';
            // Un frame de separación para que el lector de pantalla note el cambio.
            requestAnimationFrame(() => { nodo.textContent = texto; });
        },

        aviso(texto, tipo = 'info') {
            const caja = Util.el('div', { class: `aviso${tipo === 'error' ? ' aviso--error' : ''}` }, [
                SARD.Iconos.html('alerta') ? Util.el('span', { html: SARD.Iconos.html('alerta') }) : null,
                Util.el('p', { text: texto }),
            ]);
            $('avisos').append(caja);
            setTimeout(() => caja.remove(), 6000);
        },

        cargaInicial(p) {
            $('loader-relleno').style.width = `${Math.round(Util.clamp(p, 0, 1) * 100)}%`;
        },

        ocultarLoader() {
            const l = $('loader');
            l.classList.add('listo');
            setTimeout(() => l.remove(), 500);
        },
    };

    SARD.HUD = HUD;
})(window.SARD = window.SARD || {});
