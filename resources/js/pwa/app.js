import Alpine from 'alpinejs';
import { encode } from '../protocol/payload.js';
import * as almacen from './almacen.js';
import {
    ESTADO, buscarRepetido, bytesJsonEquivalente, bytesTexto, esAckDe, esperaMs,
    nuevoDeviceId, recortarTexto, recuperarTrasRecarga, uuidV7, vencidos,
} from './cola.js';
import { idiomaActual, instalarIdioma, locale, t } from '../i18n/index.js';
import {
    VIBRACION, dictar, hablar, idiomaDeVoz, mensajeErrorDictado, reconocedorDisponible, resumenHablado, unirDictado, vibrar, vozDisponible,
} from './accesibilidad.js';

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
    let detenerDictado = () => {};

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
        /** Texto para lectores de pantalla cuando la central confirma un reporte (región aria-live). */
        anuncio: '',
        /** Ventana de confirmación tras guardar: { uuid, repetido } del reporte recién guardado. */
        confirmacion: null,
        puedeDictar: reconocedorDisponible() !== null,
        puedeHablar: vozDisponible(),
        dictando: false,
        errorDictado: null,
        leerEnVoz: false,

        async iniciar() {
            this.deviceId = await almacen.leerAjuste('device_id');
            if (!this.deviceId) {
                this.deviceId = nuevoDeviceId();
                await almacen.guardarAjuste('device_id', this.deviceId);
            }
            this.simularSinRed = await almacen.leerAjuste('simular_sin_red', false);
            this.leerEnVoz = this.puedeHablar && await almacen.leerAjuste('leer_en_voz', false);

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

            this.avisar(repetido
                ? t('Ya tenías este reporte pendiente: lo actualizamos en lugar de duplicarlo.')
                : this.sinConexion()
                    ? t('Guardado en tu teléfono. Se enviará solo cuando haya señal.')
                    : t('Guardado. Enviando a la central…'));
            vibrar(VIBRACION.guardado);
            this.tipo = null;
            this.marcadas = 0;
            this.personas = 0;
            this.texto = '';
            this.verDetalles = false;
            await this.recargar();
            this.abrirConfirmacion(uuid, Boolean(repetido));
            this.ciclo();
        },

        sinConexion() {
            return this.simularSinRed || !this.enLinea;
        },

        /** Muestra un aviso y, si la persona lo activó, lo lee en voz alta. */
        avisar(texto) {
            this.aviso = texto;
            if (this.leerEnVoz) {
                hablar(texto, idiomaDeVoz(idiomaActual()));
            }
        },

        /** La central confirmó un reporte: vibra, lo anuncia al lector de pantalla y, si aplica, lo lee. */
        confirmado(registro) {
            const texto = t('Tu reporte de :tipo fue recibido por la central.', { tipo: this.nombreTipo(registro.tipo) });
            vibrar(VIBRACION.confirmado);
            this.anuncio = '';
            this.$nextTick(() => (this.anuncio = texto));
            if (this.leerEnVoz) {
                hablar(texto, idiomaDeVoz(idiomaActual()));
            }
        },

        /** Lee el reporte más reciente: lo pidió la persona, así que habla aunque la lectura automática esté apagada. */
        leerUltimoReporte() {
            this.leerReporte(this.registros[0]?.uuid);
        },

        /**
         * Confirmación grande en el centro: el aviso de arriba queda fuera de la vista cuando la persona
         * está junto al botón «Enviar». El reporte ya está guardado; cerrarla no cancela nada.
         */
        abrirConfirmacion(uuid, repetido) {
            this.confirmacion = { uuid, repetido };
            this.$nextTick(() => {
                const dialogo = this.$refs.dialogoConfirmacion;
                if (dialogo && !dialogo.open) {
                    dialogo.showModal();
                }
            });
        },

        cerrarConfirmacion() {
            this.$refs.dialogoConfirmacion?.close();
        },

        /** El reporte de la confirmación, con su estado al día (pendiente → enviando → recibido). */
        get registroConfirmado() {
            return this.confirmacion ? this.registros.find((r) => r.uuid === this.confirmacion.uuid) ?? null : null;
        },

        leerReporte(uuid) {
            const registro = this.registros.find((r) => r.uuid === uuid);
            if (!registro) {
                return;
            }
            const banderas = this.banderas
                .filter((bandera) => (registro.banderas & (1 << bandera.bit)) !== 0)
                .map((bandera) => t(bandera.nombre));

            hablar(resumenHablado(registro, {
                tipo: this.nombreTipo(registro.tipo),
                banderas,
                estado: this.textoEstado(registro),
                hora: this.hora(registro.creado_en),
            }, t), idiomaDeVoz(idiomaActual()));
        },

        async alternarVoz() {
            this.leerEnVoz = !this.leerEnVoz;
            await almacen.guardarAjuste('leer_en_voz', this.leerEnVoz);
            if (this.leerEnVoz) {
                hablar(t('Lectura en voz alta activada.'), idiomaDeVoz(idiomaActual()));
            }
        },

        /**
         * Dicta la nota corta. Lo dictado se suma a lo ya escrito y respeta el límite de 140 B.
         * Sin señal no se intenta: el reconocedor del navegador transcribe en línea.
         */
        alternarDictado() {
            if (this.dictando) {
                detenerDictado();
                return;
            }
            this.errorDictado = null;
            this.verDetalles = true;
            if (this.sinConexion()) {
                this.errorDictado = t(mensajeErrorDictado('sin-red'));
                this.$nextTick(() => this.$refs.nota.focus());
                return;
            }

            const base = this.texto;
            this.dictando = true;
            detenerDictado = dictar({
                lang: idiomaDeVoz(idiomaActual()),
                alTexto: (dictado) => {
                    this.texto = unirDictado(base, dictado);
                    this.limitarTexto();
                },
                alTerminar: (error) => {
                    this.dictando = false;
                    detenerDictado = () => {};
                    const mensaje = mensajeErrorDictado(error);
                    this.errorDictado = mensaje ? t(mensaje) : null;
                    this.$nextTick(() => this.$refs.nota.focus());
                },
            });
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
                    this.confirmado(registro);
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
            return t(this.tipos.find((tipo) => tipo.valor === valor)?.nombre ?? 'Otra emergencia');
        },

        hora(milisegundos) {
            return new Date(milisegundos).toLocaleTimeString(locale(), { hour: '2-digit', minute: '2-digit' });
        },

        textoEstado(registro) {
            if (registro.estado === ESTADO.CONFIRMADO) {
                return t('Recibido por la central');
            }
            if (registro.estado === ESTADO.ENVIADO) {
                return t('Enviando…');
            }
            if (this.sinConexion()) {
                return t('Pendiente · sin señal, se guarda aquí');
            }
            const segundos = Math.max(0, Math.ceil((registro.proximo_intento - this.ahora) / 1000));

            return registro.intentos === 0 || segundos === 0
                ? t('Pendiente · enviando')
                : t('Pendiente · reintento en :s s', { s: segundos });
        },

        get pendientes() {
            return this.registros.filter((r) => r.estado !== ESTADO.CONFIRMADO).length;
        },
    };
}

window.pwa = pwa;
window.Alpine = Alpine;
instalarIdioma(Alpine, { titulo: 'Reportar emergencia · SARD' });
Alpine.start();
