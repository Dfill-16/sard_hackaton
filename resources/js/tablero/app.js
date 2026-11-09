import Alpine from 'alpinejs';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { FileSource, PMTiles } from 'pmtiles';
import { leafletLayer } from 'protomaps-leaflet';

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
    let archivoTeselas = null;
    let encuadreHecho = false;

    return {
        estado: null,
        detalle: null,
        seleccionado: null,
        contactando: null,
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
                this.errorDecision = 'Escribe tu nombre en «Operador» (arriba) antes de decidir.';
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
                    this.errorDecision = cuerpo.detalle ?? cuerpo.message ?? `No se pudo registrar (HTTP ${respuesta.status}).`;
                    return false;
                }

                await this.abrir(this.detalle.uuid);
                this.consultar();
                return true;
            } catch (error) {
                this.errorDecision = 'Sin conexión con el líder. La decisión no se registró; inténtalo de nuevo.';
                return false;
            } finally {
                this.enviandoDecision = false;
            }
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
            this.consultar();
            setInterval(() => this.consultar(), config.pollingMs);
            setInterval(() => (this.ahora = Date.now()), 15000);
        },

        iniciarMapa() {
            mapa = L.map(this.$refs.mapa, { zoomControl: true, minZoom: 11, maxZoom: 18 }).setView(CENTRO_MANIZALES, 14);
            mapa.attributionControl.setPrefix(false);
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
                lang: 'es',
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
                    ? `Conectado a ${this.nodoConectado}`
                    : `Conectado a ${this.nodoConectado} · ${config.nodo} no responde`;
            }
            if (this.conexion === 'reintentando') {
                return `Sin respuesta de ${this.nodoConectado} (${this.fallos}/${FALLOS_ANTES_DE_CAMBIAR})`;
            }
            if (this.conexion === 'caido') {
                return 'Sin conexión con B ni C';
            }

            return 'Conectando…';
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
            const conUbicacion = [...this.estado.cola, ...this.estado.baja_prioridad].filter((i) => i.lat !== null);
            const borde = variable('--sard-surface');

            for (const incidente of conUbicacion) {
                const color = variable(`--sard-${incidente.prioridad.toLowerCase()}`);
                const elegido = incidente.uuid === this.seleccionado;

                if (incidente.masivo) {
                    L.circleMarker([incidente.lat, incidente.lon], {
                        radius: 18, color, weight: 3, fill: false, interactive: false,
                    }).addTo(capaMarcadores);
                }

                const marcador = L.circleMarker([incidente.lat, incidente.lon], {
                    radius: (elegido ? 10 : 7) + Math.min(4, incidente.reportes_n - 1),
                    color: elegido ? variable('--sard-foreground') : borde,
                    weight: elegido ? 3 : 1.5,
                    fillColor: color,
                    fillOpacity: 0.9,
                });
                marcador.bindTooltip(`${incidente.prioridad} · ${incidente.tipo_nombre} · ${incidente.reportes_n} rep.`, { direction: 'top', offset: [0, -6] });
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
                this.detalle = await respuesta.json();
                if (this.detalle.lat !== null && mapa) {
                    mapa.panTo([this.detalle.lat, this.detalle.lon]);
                }
                this.$refs.detalle?.scrollTo({ top: 0 });
                if (window.matchMedia('(max-width: 1279px)').matches) {
                    this.$refs.detalle?.scrollIntoView({ block: 'start' });
                }
            } catch (error) {
                this.detalle = { error: 'No se pudo cargar el detalle. Se reintentará al elegirlo de nuevo.' };
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
        },

        clasePrioridad(prioridad) {
            return CLASES_PRIORIDAD[prioridad] ?? 'bg-surface-2 text-muted';
        },

        porcentaje(valor) {
            return `${Math.round((valor ?? 0) * 100)} %`;
        },

        hora(iso) {
            return iso ? new Date(iso).toLocaleTimeString('es-CO', { hour: '2-digit', minute: '2-digit', second: '2-digit' }) : '–';
        },

        haceCuanto(iso) {
            if (!iso) {
                return '';
            }
            const minutos = Math.floor((this.ahora - new Date(iso).getTime()) / 60000);
            if (minutos < 1) {
                return 'ahora';
            }
            if (minutos < 60) {
                return `hace ${minutos} min`;
            }

            return `hace ${Math.floor(minutos / 60)} h ${minutos % 60} min`;
        },
    };
}

window.tablero = tablero;
window.Alpine = Alpine;
Alpine.start();
