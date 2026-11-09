import Alpine from 'alpinejs';
import { encode } from '../protocol/payload.js';
import * as almacen from './almacen.js';
import {
    ESTADO, buscarRepetido, bytesJsonEquivalente, bytesTexto, esAckDe, esperaMs,
    nuevoDeviceId, recortarTexto, recuperarTrasRecarga, uuidV7, vencidos,
} from './cola.js';

/**
 * PWA ciudadana (T-005): reporte en 2 a 4 toques que funciona sin red.
 * Guarda en IndexedDB, envía el binario a POST /api/reportes y solo borra el original al recibir el ACK.
 */
const INTERVALO_ENVIO_MS = 1000;
const TIMEOUT_ENVIO_MS = 5000;
const GPS = { enableHighAccuracy: true, timeout: 5000, maximumAge: 60000 };
const MAXIMO_TEXTO = 140;

function pwa(config) {
    let enviando = false;

    return {
        tipos: config.tipos,
        banderas: config.banderas,
        deviceId: null,
        registros: [],
        simularSinRed: false,
        enLinea: navigator.onLine,
        ahora: Date.now(),

        tipo: null,
        marcadas: 0,
        personas: 0,
        texto: '',
        verDetalles: false,

        gps: { estado: 'buscando', lat: null, lon: null, precision: null },
        aviso: null,

        async iniciar() {
            this.deviceId = await almacen.leerAjuste('device_id');
            if (!this.deviceId) {
                this.deviceId = nuevoDeviceId();
                await almacen.guardarAjuste('device_id', this.deviceId);
            }
            this.simularSinRed = await almacen.leerAjuste('simular_sin_red', false);

            for (const registro of await almacen.todos()) {
                const recuperado = recuperarTrasRecarga(registro);
                if (recuperado !== registro) {
                    await almacen.guardar(recuperado);
                }
            }
            await this.recargar();

            window.addEventListener('online', () => (this.enLinea = true));
            window.addEventListener('offline', () => (this.enLinea = false));
            this.ubicar();
            setInterval(() => this.ciclo(), INTERVALO_ENVIO_MS);

            if ('serviceWorker' in navigator) {
                navigator.serviceWorker.register('/sw.js').catch(() => {});
            }
        },

        async recargar() {
            this.registros = (await almacen.todos()).sort((a, b) => b.creado_en - a.creado_en);
        },

        ubicar() {
            if (!('geolocation' in navigator)) {
                this.gps = { estado: 'sin', lat: null, lon: null, precision: null };
                return;
            }

            this.gps.estado = 'buscando';
            navigator.geolocation.getCurrentPosition(
                (posicion) => {
                    this.gps = {
                        estado: 'lista',
                        lat: Math.round(posicion.coords.latitude * 1e7) / 1e7,
                        lon: Math.round(posicion.coords.longitude * 1e7) / 1e7,
                        precision: Math.round(posicion.coords.accuracy),
                    };
                },
                () => (this.gps = { estado: 'sin', lat: null, lon: null, precision: null }),
                GPS,
            );
        },

        elegirTipo(valor) {
            this.tipo = valor;
            this.aviso = null;
        },

        alternarBandera(bit) {
            this.marcadas ^= 1 << bit;
        },

        tieneBandera(bit) {
            return (this.marcadas & (1 << bit)) !== 0;
        },

        cambiarPersonas(delta) {
            this.personas = Math.min(255, Math.max(0, this.personas + delta));
        },

        get bytesDelTexto() {
            return bytesTexto(this.texto);
        },

        limitarTexto() {
            if (bytesTexto(this.texto) > MAXIMO_TEXTO) {
                this.texto = recortarTexto(this.texto, MAXIMO_TEXTO);
            }
        },

        async enviar() {
            if (this.tipo === null) {
                return;
            }

            const ahora = Date.now();
            const conUbicacion = this.gps.estado === 'lista';
            const repetido = buscarRepetido(this.registros, { tipo: this.tipo, banderas: this.marcadas }, config.ventanaRepetidoMin, ahora);
            const uuid = repetido ? repetido.uuid : uuidV7(ahora);

            const reporte = {
                version: 1,
                uuid,
                deviceId: this.deviceId,
                lat: conUbicacion ? this.gps.lat : null,
                lon: conUbicacion ? this.gps.lon : null,
                tipo: this.tipo,
                banderas: this.marcadas,
                personas: this.personas,
                texto: this.texto.trim(),
            };
            const bytes = encode(reporte);

            await almacen.guardar({
                uuid,
                bytes,
                tipo: reporte.tipo,
                banderas: reporte.banderas,
                personas: reporte.personas,
                texto: reporte.texto,
                sin_ubicacion: !conUbicacion,
                estado: ESTADO.PENDIENTE,
                intentos: 0,
                proximo_intento: ahora,
                creado_en: repetido ? repetido.creado_en : ahora,
                actualizado_en: ahora,
                repeticiones: repetido ? (repetido.repeticiones ?? 0) + 1 : 0,
                bytes_n: bytes.length,
                json_n: bytesJsonEquivalente(reporte),
            });

            this.aviso = repetido
                ? 'Ya tenías este reporte pendiente: lo actualizamos en lugar de duplicarlo.'
                : this.sinConexion()
                    ? 'Guardado en tu teléfono. Se enviará solo cuando haya señal.'
                    : 'Guardado. Enviando a la central…';
            this.tipo = null;
            this.marcadas = 0;
            this.personas = 0;
            this.texto = '';
            this.verDetalles = false;
            await this.recargar();
            this.ciclo();
        },

        sinConexion() {
            return this.simularSinRed || !this.enLinea;
        },

        async alternarSinRed() {
            this.simularSinRed = !this.simularSinRed;
            await almacen.guardarAjuste('simular_sin_red', this.simularSinRed);
        },

        async ciclo() {
            this.ahora = Date.now();
            if (enviando || this.sinConexion()) {
                return;
            }

            const listos = vencidos(this.registros, this.ahora);
            if (listos.length === 0) {
                return;
            }

            enviando = true;
            try {
                for (const registro of listos) {
                    if (this.sinConexion()) {
                        break;
                    }
                    await this.enviarUno(registro);
                }
            } finally {
                enviando = false;
                await this.recargar();
            }
        },

        async enviarUno(registroReactivo) {
            const registro = { ...Alpine.raw(registroReactivo) };
            await almacen.guardar({ ...registro, estado: ESTADO.ENVIADO });

            try {
                const respuesta = await fetch(config.urlReportes, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/octet-stream' },
                    body: registro.bytes,
                    signal: AbortSignal.timeout(TIMEOUT_ENVIO_MS),
                });
                const cuerpo = new Uint8Array(await respuesta.arrayBuffer());

                if (respuesta.ok && esAckDe(cuerpo, registro.uuid)) {
                    await almacen.guardar({ ...registro, bytes: null, estado: ESTADO.CONFIRMADO, confirmado_en: Date.now() });
                    return;
                }
                throw new Error(`HTTP ${respuesta.status}`);
            } catch (error) {
                const intentos = registro.intentos + 1;
                await almacen.guardar({
                    ...registro,
                    estado: ESTADO.PENDIENTE,
                    intentos,
                    proximo_intento: Date.now() + esperaMs(intentos),
                });
            }
        },

        nombreTipo(valor) {
            return this.tipos.find((t) => t.valor === valor)?.nombre ?? 'Otro';
        },

        hora(milisegundos) {
            return new Date(milisegundos).toLocaleTimeString('es-CO', { hour: '2-digit', minute: '2-digit' });
        },

        textoEstado(registro) {
            if (registro.estado === ESTADO.CONFIRMADO) {
                return 'Recibido por la central';
            }
            if (registro.estado === ESTADO.ENVIADO) {
                return 'Enviando…';
            }
            if (this.sinConexion()) {
                return 'Pendiente · sin señal, se guarda aquí';
            }
            const segundos = Math.max(0, Math.ceil((registro.proximo_intento - this.ahora) / 1000));

            return registro.intentos === 0 || segundos === 0
                ? 'Pendiente · enviando'
                : `Pendiente · reintento en ${segundos} s`;
        },

        get pendientes() {
            return this.registros.filter((r) => r.estado !== ESTADO.CONFIRMADO).length;
        },
    };
}

window.pwa = pwa;
window.Alpine = Alpine;
Alpine.start();
