import Alpine from 'alpinejs';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { FileSource, PMTiles } from 'pmtiles';
import { leafletLayer } from 'protomaps-leaflet';
import { idiomaActual, instalarIdioma, locale, t, td } from '../i18n/index.js';

/**
 * Tablero de coordinación (T-009).
 *
 * - Polling a /api/tablero/estado cada POLLING_TABLERO_S.
 * - Tras 3 fallos seguidos prueba el otro nodo (B ↔ C) y lo dice en la barra.
 * - Mapa Leaflet servido desde el proyecto; sin teselas usa un lienzo neutro con cuadrícula.
 */
const CENTRO_MANIZALES = [5.058, -75.495];
const FALLOS_ANTES_DE_CAMBIAR = 3;

/** Cobertura del archivo PMTiles extraído (Manizales y Villamaría). */
const LIMITES_TESELAS = [[4.95, -75.65], [5.2, -75.35]];
const ZOOM_MAXIMO_DATOS = 15;

/**
 * T-017: íconos de las capas de demostración (trazo 1.75 px, 24×24). Siempre van con su
 * nombre en el tooltip; el estado de una unidad también va escrito, no solo en color.
 */
const ICONOS_CAPA = {
    Albergue: '<path d="M4 11 12 4l8 7"/><path d="M6 10v10h12V10"/><path d="M10 20v-5h4v5"/>',
    Acopio: '<path d="m12 3 8 4v10l-8 4-8-4V7z"/><path d="m4 7 8 4 8-4M12 11v10"/>',
    Sangre: '<path d="M12 3c3 4 6 7.5 6 11a6 6 0 0 1-12 0c0-3.5 3-7 6-11z"/>',
    ambulancia: '<path d="M9.5 4h5v5.5H20v5h-5.5V20h-5v-5.5H4v-5h5.5z"/>',
    bomberos: '<path d="M12 21c-3.9 0-6.5-2.6-6.5-6 0-3.5 2.7-5.6 3.5-9 2 1.3 3 3.2 3 5 .9-.6 1.6-1.6 1.9-2.8 2.3 1.9 4.6 4.4 4.6 6.8 0 3.4-2.6 6-6.5 6z"/>',
};

function iconoCapa(clave, clase) {
    return L.divIcon({
        className: `icono-capa ${clase}`,
        html: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${ICONOS_CAPA[clave] ?? ''}</svg>`,
        iconSize: [24, 24],
        iconAnchor: [12, 12],
    });
}

/**
 * Lista de incidentes redimensionable (pantallas ≥ 1024 px). Por debajo del umbral se pliega
 * a un riel; el ancho y el plegado se recuerdan en el navegador.
 */
const ANCHO_LISTA_INICIAL = 380;
const ANCHO_LISTA_MINIMO = 280;
const UMBRAL_PLEGAR = 200;
const PASO_TECLADO = 24;
const ANCHO_MAPA_MINIMO = 320;

/** T-014: trazo punteado para lo dudoso y opacidad mínima del marcador (confianza 0). */
const TRAZO_DUDOSO = '4 4';
const OPACIDAD_MINIMA = 0.35;

/** Clases de la etiqueta de prioridad: relleno + texto con contraste (nunca solo color: siempre lleva «P1»…). */
const CLASES_PRIORIDAD = {
    P1: 'bg-p1 text-p1-ink',
    P2: 'bg-p2 text-p2-ink',
    P3: 'bg-p3 text-p3-ink',
    P4: 'bg-p4 text-p4-ink',
};

function leerLocal(clave) {
    try {
        return localStorage.getItem(clave);
    } catch (error) {
        return null;
    }
}

function escribirLocal(clave, valor) {
    try {
        localStorage.setItem(clave, valor);
    } catch (error) {
        // Sin almacenamiento local: el valor dura solo esta sesión.
    }
}

function variable(nombre) {
    return getComputedStyle(document.documentElement).getPropertyValue(nombre).trim();
}

function tablero(config) {
    let mapa = null;
    let capaMarcadores = null;
    let capaFondo = null;
    let capaDemo = null;
    let archivoTeselas = null;
    let encuadreHecho = false;

    return {
        estado: null,
        detalle: null,
        seleccionado: null,
        contactando: null,
        reporteResaltado: null,
        verRecursos: leerLocal('sard-capa-recursos') === '1',
        verUnidades: leerLocal('sard-capa-unidades') !== '0',
        anchoLista: Number(leerLocal('sard-ancho-lista')) || ANCHO_LISTA_INICIAL,
        listaPlegada: leerLocal('sard-lista-plegada') === '1',
        arrastrando: false,

        /** Ancho máximo: la lista nunca deja el mapa por debajo de ANCHO_MAPA_MINIMO. */
        anchoMaximo() {
            const detalle = this.detalle ? Math.min(460, Math.max(340, window.innerWidth * 0.32)) : 0;

            return Math.max(ANCHO_LISTA_MINIMO, Math.round(window.innerWidth - detalle - ANCHO_MAPA_MINIMO));
        },

        fijarAnchoLista(ancho) {
            if (ancho < UMBRAL_PLEGAR) {
                this.plegarLista(true);
                return;
            }
            this.listaPlegada = false;
            this.anchoLista = Math.round(Math.min(this.anchoMaximo(), Math.max(ANCHO_LISTA_MINIMO, ancho)));
            this.ajustarMapa();
        },

        plegarLista(plegada) {
            this.listaPlegada = plegada;
            if (!plegada && this.anchoLista < ANCHO_LISTA_MINIMO) {
                this.anchoLista = ANCHO_LISTA_INICIAL;
            }
            this.guardarLista();
            this.ajustarMapa();
        },

        guardarLista() {
            escribirLocal('sard-ancho-lista', String(this.anchoLista));
            escribirLocal('sard-lista-plegada', this.listaPlegada ? '1' : '0');
        },

        /** Arrastre del separador: el ancho sigue al puntero desde el borde izquierdo de la ventana. */
        empezarArrastre(evento) {
            if (evento.button !== 0) {
                return;
            }
            evento.preventDefault();
            const separador = evento.currentTarget;
            separador.setPointerCapture(evento.pointerId);
            this.arrastrando = true;

            const mover = (e) => this.fijarAnchoLista(e.clientX);
            const soltar = () => {
                this.arrastrando = false;
                separador.removeEventListener('pointermove', mover);
                separador.removeEventListener('pointerup', soltar);
                separador.removeEventListener('pointercancel', soltar);
                this.guardarLista();
                this.ajustarMapa();
            };
            separador.addEventListener('pointermove', mover);
            separador.addEventListener('pointerup', soltar);
            separador.addEventListener('pointercancel', soltar);
        },

        teclaSeparador(evento) {
            if (evento.key === 'ArrowLeft') {
                // Por debajo del mínimo, la siguiente pulsación pliega la lista.
                const siguiente = this.anchoLista - PASO_TECLADO;
                if (!this.listaPlegada) {
                    this.fijarAnchoLista(siguiente < ANCHO_LISTA_MINIMO ? 0 : siguiente);
                }
            } else if (evento.key === 'ArrowRight') {
                this.fijarAnchoLista(this.listaPlegada ? ANCHO_LISTA_MINIMO : this.anchoLista + PASO_TECLADO);
            } else if (evento.key === 'Enter' || evento.key === ' ') {
                this.plegarLista(!this.listaPlegada);
            } else {
                return;
            }
            evento.preventDefault();
            this.guardarLista();
        },

        /** Leaflet necesita recalcular su tamaño cuando cambia el ancho de su contenedor. */
        ajustarMapa() {
            requestAnimationFrame(() => mapa?.invalidateSize({ pan: false }));
        },

        alternarCapa(capa) {
            if (capa === 'recursos') {
                this.verRecursos = !this.verRecursos;
                escribirLocal('sard-capa-recursos', this.verRecursos ? '1' : '0');
            } else {
                this.verUnidades = !this.verUnidades;
                escribirLocal('sard-capa-unidades', this.verUnidades ? '1' : '0');
            }
            this.pintarCapas();
        },

        /** Capas de demostración (T-017): recursos de semilla y unidades con su estado. */
        pintarCapas() {
            if (!capaDemo || !this.estado) {
                return;
            }

            capaDemo.clearLayers();
            if (this.verRecursos) {
                for (const recurso of this.estado.recursos ?? []) {
                    L.marker([recurso.lat, recurso.lon], { icon: iconoCapa(recurso.tipo, 'recurso'), keyboard: false })
                        .bindTooltip(`${td(recurso.tipo)} · ${recurso.nombre} (${recurso.zona}) · ${t('datos de demostración')}`, { direction: 'top', offset: [0, -10] })
                        .addTo(capaDemo);
                }
            }
            if (this.verUnidades) {
                for (const unidad of this.estado.unidades ?? []) {
                    L.marker([unidad.lat, unidad.lon], { icon: iconoCapa(unidad.tipo, `unidad unidad-${unidad.estado}`), keyboard: false })
                        .bindTooltip(`${unidad.nombre} · ${td(unidad.estado)} · ${t('datos de demostración')}`, { direction: 'top', offset: [0, -10] })
                        .addTo(capaDemo);
                }
            }
        },

        /** T-015: «Acuse: pendiente · hace N s» o «Acuse: recibido en N s» (de AlertaCampoEnviada a AckCampo). */
        textoAcuse(alerta) {
            const enviada = new Date(alerta.enviada_en).getTime();
            if (alerta.ack_en) {
                return t('Acuse: recibido en :s s', { s: Math.max(0, Math.round((new Date(alerta.ack_en).getTime() - enviada) / 1000)) });
            }

            return t('Acuse: pendiente · hace :s s', { s: Math.max(0, Math.round((this.ahora - enviada) / 1000)) });
        },

        esBrecha(uuid) {
            return (this.estado?.brechas ?? []).some((b) => b.incidente_uuid === uuid);
        },

        textoBrecha(brecha) {
            const cercana = brecha.unidad_mas_cercana;
            const unidad = td(brecha.unidad_requerida === 'ambulancia' ? 'ambulancia' : 'bomberos');

            return cercana
                ? t('Sin :unidad disponible a ≤ 3 km · la más cercana: :nombre a :km km', { unidad, nombre: cercana.nombre, km: (cercana.distancia_m / 1000).toFixed(1) })
                : t('Sin :unidad disponible en la ciudad', { unidad });
        },

        /** Lleva la vista al reporte original citado en una contradicción y lo resalta un momento. */
        resaltarReporte(uuid) {
            this.reporteResaltado = uuid;
            document.getElementById(`reporte-${uuid}`)?.scrollIntoView({ block: 'center', behavior: 'smooth' });
            setTimeout(() => {
                if (this.reporteResaltado === uuid) {
                    this.reporteResaltado = null;
                }
            }, 2500);
        },
        verTodos: false,
        fallos: 0,
        nodoConectado: config.nodo,
        base: window.location.origin,
        conexion: 'conectando',
        ahora: Date.now(),
        oscuro: document.documentElement.classList.contains('dark'),
        fondo: config.teselas ? 'cargando' : 'neutro',
        operador: leerLocal('sard-operador') ?? '',
        motivoDescarte: '',
        errorDecision: null,
        enviandoDecision: false,

        guardarOperador() {
            escribirLocal('sard-operador', this.operador.trim());
        },

        async decidir(accion, motivo = null) {
            this.errorDecision = null;
            if (!this.operador.trim()) {
                this.errorDecision = t('Escribe tu nombre en «Operador» (arriba) antes de decidir.');
                return false;
            }

            this.enviandoDecision = true;
            try {
                const respuesta = await fetch(`${this.base}/api/decisiones`, {
                    method: 'POST',
                    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        recomendacion_uuid: this.detalle.recomendacion.uuid,
                        accion,
                        motivo,
                        operador: this.operador.trim(),
                    }),
                });
                const cuerpo = await respuesta.json().catch(() => ({}));
                if (!respuesta.ok) {
                    if (cuerpo.error === 'unidad_no_disponible') {
                        await this.abrir(this.detalle.uuid);
                    }
                    this.errorDecision = cuerpo.detalle ?? cuerpo.message ?? t('No se pudo registrar (HTTP :status).', { status: respuesta.status });
                    return false;
                }

                await this.abrir(this.detalle.uuid);
                this.consultar();
                return true;
            } catch (error) {
                this.errorDecision = t('Sin conexión con el líder. La decisión no se registró; inténtalo de nuevo.');
                return false;
            } finally {
                this.enviandoDecision = false;
            }
        },

        /**
         * ¿Este operador ya tomó esta acción sobre la recomendación, desde este nodo? Repetirla no
         * cambia nada (el servidor es idempotente), así que el botón se desactiva y lo dice.
         */
        yaDecidi(accion) {
            const operador = this.operador.trim().toLowerCase();

            const recomendacion = this.detalle?.recomendacion?.uuid;

            return operador !== '' && (this.detalle?.decisiones ?? []).some((d) => d.accion === accion
                && d.recomendacion_uuid === recomendacion
                && d.nodo === this.nodoConectado
                && d.operador.trim().toLowerCase() === operador);
        },

        abrirDescarte() {
            this.errorDecision = null;
            this.motivoDescarte = '';
            this.$refs.dialogoDescarte.showModal();
        },

        async confirmarDescarte() {
            if (await this.decidir('descartar', this.motivoDescarte.trim())) {
                this.$refs.dialogoDescarte.close();
            }
        },

        iniciar() {
            this.iniciarMapa();
            // Si la ventana se angosta, la lista no puede dejar el mapa sin espacio.
            window.addEventListener('resize', () => {
                if (!this.listaPlegada && this.anchoLista > this.anchoMaximo()) {
                    this.anchoLista = this.anchoMaximo();
                }
            });
            this.consultar();
            setInterval(() => this.consultar(), config.pollingMs);
            setInterval(() => (this.ahora = Date.now()), 1000);
            // Los textos del mapa (tooltips y nombres de calles) no son reactivos: se repintan al cambiar de idioma.
            window.addEventListener('sard-idioma', () => {
                this.ponerFondo();
                this.pintarMarcadores();
                this.pintarCapas();
            });
        },

        iniciarMapa() {
            mapa = L.map(this.$refs.mapa, { zoomControl: true, minZoom: 11, maxZoom: 18 }).setView(CENTRO_MANIZALES, 14);
            mapa.attributionControl.setPrefix(false);
            capaDemo = L.layerGroup().addTo(mapa);
            capaMarcadores = L.layerGroup().addTo(mapa);
            this.cargarTeselas();
        },

        /**
         * Descarga el PMTiles local una sola vez y lo lee desde memoria: el servidor de PHP
         * no atiende peticiones Range, y así el mapa sigue funcionando si la red se cae después.
         */
        async cargarTeselas() {
            if (!config.teselas) {
                return;
            }

            try {
                const respuesta = await fetch(config.teselas);
                if (!respuesta.ok) {
                    throw new Error(`HTTP ${respuesta.status}`);
                }
                const archivo = new File([await respuesta.blob()], 'manizales.pmtiles');
                archivoTeselas = new PMTiles(new FileSource(archivo));
                mapa.setMaxBounds(L.latLngBounds(LIMITES_TESELAS).pad(0.1));
                this.ponerFondo();
                this.fondo = 'listo';
            } catch (error) {
                this.fondo = 'fallo';
            }
        },

        ponerFondo() {
            if (!archivoTeselas) {
                return;
            }
            if (capaFondo) {
                mapa.removeLayer(capaFondo);
            }

            capaFondo = leafletLayer({
                url: archivoTeselas,
                flavor: this.oscuro ? 'dark' : 'light',
                lang: idiomaActual(),
                maxDataZoom: ZOOM_MAXIMO_DATOS,
                attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> · Protomaps',
            }).addTo(mapa);
            this.$refs.mapa.classList.add('con-teselas');
        },

        async consultar() {
            try {
                const respuesta = await fetch(`${this.base}/api/tablero/estado?todos=${this.verTodos ? 1 : 0}`, {
                    headers: { Accept: 'application/json' },
                    signal: AbortSignal.timeout(Math.max(1500, config.pollingMs)),
                });
                if (!respuesta.ok) {
                    throw new Error(`HTTP ${respuesta.status}`);
                }

                this.estado = await respuesta.json();
                this.nodoConectado = this.estado.nodo;
                this.fallos = 0;
                this.conexion = 'conectado';
                this.ahora = Date.now();
                this.pintarMarcadores();
                this.pintarCapas();
            } catch (error) {
                this.fallos++;
                this.conexion = 'reintentando';
                if (this.fallos >= FALLOS_ANTES_DE_CAMBIAR) {
                    this.cambiarDeNodo();
                }
            }
        },

        cambiarDeNodo() {
            const otro = this.nodoConectado === 'B' ? 'C' : 'B';
            const url = config.urls[otro];
            if (!url) {
                this.conexion = 'caido';
                return;
            }

            this.base = url.replace(/\/$/, '');
            this.nodoConectado = otro;
            this.fallos = 0;
        },

        textoConexion() {
            if (this.conexion === 'conectado') {
                return this.nodoConectado === config.nodo
                    ? t('Conectado a :nodo', { nodo: this.nodoConectado })
                    : t('Conectado a :nodo · :caido no responde', { nodo: this.nodoConectado, caido: config.nodo });
            }
            if (this.conexion === 'reintentando') {
                return t('Sin respuesta de :nodo (:fallos/:maximo)', { nodo: this.nodoConectado, fallos: this.fallos, maximo: FALLOS_ANTES_DE_CAMBIAR });
            }
            if (this.conexion === 'caido') {
                return t('Sin conexión con B ni C');
            }

            return t('Conectando…');
        },

        claseConexion() {
            if (this.conexion === 'conectado') {
                return this.nodoConectado === config.nodo ? 'bg-healthy' : 'bg-degraded';
            }

            return this.conexion === 'caido' ? 'bg-down' : 'bg-degraded';
        },

        alternarTodos() {
            this.verTodos = !this.verTodos;
            this.consultar();
        },

        alternarTema() {
            this.oscuro = !this.oscuro;
            document.documentElement.classList.toggle('dark', this.oscuro);
            try {
                localStorage.setItem('sard-tema', this.oscuro ? 'oscuro' : 'claro');
            } catch (error) {
                // Sin almacenamiento local: el cambio dura solo esta sesión.
            }
            this.ponerFondo();
            this.pintarMarcadores();
        },

        pintarMarcadores() {
            if (!capaMarcadores || !this.estado) {
                return;
            }

            capaMarcadores.clearLayers();
            // `mapa` trae todos los incidentes con ubicación; la lista «Atender primero» puede venir recortada al top 10.
            const conUbicacion = (this.estado.mapa ?? [...this.estado.cola, ...this.estado.baja_prioridad]).filter((i) => i.lat !== null);
            const borde = variable('--sard-surface');

            for (const incidente of conUbicacion) {
                const color = variable(`--sard-${incidente.prioridad.toLowerCase()}`);
                const elegido = incidente.uuid === this.seleccionado;
                const trazo = incidente.corroborado ? null : TRAZO_DUDOSO;

                // Halo de incertidumbre: radio aproximado en metros; punteado si el incidente es dudoso.
                L.circle([incidente.lat, incidente.lon], {
                    radius: incidente.radio_m ?? 60,
                    color,
                    weight: 1,
                    opacity: 0.7,
                    dashArray: trazo,
                    fillColor: color,
                    fillOpacity: 0.08,
                    interactive: false,
                }).addTo(capaMarcadores);

                if (this.esBrecha(incidente.uuid)) {
                    L.circleMarker([incidente.lat, incidente.lon], {
                        radius: 24, color: variable('--sard-down'), weight: 2, dashArray: '2 5', fill: false, interactive: false,
                    }).addTo(capaMarcadores);
                }

                if (incidente.masivo) {
                    L.circleMarker([incidente.lat, incidente.lon], {
                        radius: 18, color, weight: 3, fill: false, interactive: false,
                    }).addTo(capaMarcadores);
                }

                const marcador = L.circleMarker([incidente.lat, incidente.lon], {
                    radius: (elegido ? 10 : 7) + Math.min(4, incidente.reportes_n - 1),
                    color: elegido ? variable('--sard-foreground') : (incidente.corroborado ? borde : color),
                    weight: elegido ? 3 : 2,
                    dashArray: elegido ? null : trazo,
                    fillColor: color,
                    // Opacidad según la confianza: lo dudoso se ve más tenue, pero nunca desaparece.
                    fillOpacity: OPACIDAD_MINIMA + (1 - OPACIDAD_MINIMA) * Math.min(1, incidente.confianza ?? 0),
                });
                marcador.bindTooltip(
                    `${incidente.prioridad} · ${td(incidente.tipo_nombre)} · ${t(':n rep.', { n: incidente.reportes_n })} · ${this.porcentaje(incidente.confianza)} ${incidente.corroborado ? t('corroborado') : t('dudoso')}`,
                    { direction: 'top', offset: [0, -6] },
                );
                marcador.on('click', () => this.abrir(incidente.uuid));
                marcador.addTo(capaMarcadores);
            }

            if (!encuadreHecho && conUbicacion.length > 0) {
                mapa.fitBounds(L.latLngBounds(conUbicacion.map((i) => [i.lat, i.lon])), { padding: [40, 40], maxZoom: 15 });
                encuadreHecho = true;
            }
        },

        async abrir(uuid) {
            this.seleccionado = uuid;
            this.pintarMarcadores();

            try {
                const respuesta = await fetch(`${this.base}/api/incidentes/${uuid}`, { headers: { Accept: 'application/json' } });
                if (!respuesta.ok) {
                    throw new Error(`HTTP ${respuesta.status}`);
                }
                const estabaAbierto = this.detalle !== null;
                this.detalle = await respuesta.json();
                // El panel de detalle aparece y le quita ancho al mapa: Leaflet debe medirse de nuevo.
                if (!estabaAbierto) {
                    await this.$nextTick();
                    mapa?.invalidateSize({ pan: false });
                }
                if (this.detalle.lat !== null && mapa) {
                    mapa.panTo([this.detalle.lat, this.detalle.lon]);
                }
                this.$refs.detalle?.scrollTo({ top: 0 });
                if (window.matchMedia('(max-width: 1023px)').matches) {
                    this.$refs.detalle?.scrollIntoView({ block: 'start' });
                }
            } catch (error) {
                this.detalle = { error: t('No se pudo cargar el detalle. Se reintentará al elegirlo de nuevo.') };
            }
        },

        contactarCentral(uuid) {
            this.contactando = uuid;
            this.abrir(uuid);
        },

        cerrar() {
            this.seleccionado = null;
            this.detalle = null;
            this.contactando = null;
            this.pintarMarcadores();
            this.ajustarMapa();
        },

        clasePrioridad(prioridad) {
            return CLASES_PRIORIDAD[prioridad] ?? 'bg-surface-2 text-muted';
        },

        porcentaje(valor) {
            return `${Math.round((valor ?? 0) * 100)} %`;
        },

        hora(iso) {
            return iso ? new Date(iso).toLocaleTimeString(locale(), { hour: '2-digit', minute: '2-digit', second: '2-digit' }) : '–';
        },

        haceCuanto(iso) {
            if (!iso) {
                return '';
            }
            const minutos = Math.floor((this.ahora - new Date(iso).getTime()) / 60000);
            if (minutos < 1) {
                return t('ahora');
            }
            if (minutos < 60) {
                return t('hace :m min', { m: minutos });
            }

            return t('hace :h h :m min', { h: Math.floor(minutos / 60), m: minutos % 60 });
        },
    };
}

window.tablero = tablero;
window.Alpine = Alpine;
instalarIdioma(Alpine, { titulo: 'Coordinación · SARD (:nodo)', paramsTitulo: { nodo: document.title.match(/\((\w+)\)/)?.[1] ?? '' } });
Alpine.start();
