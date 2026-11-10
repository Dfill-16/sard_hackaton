import Alpine from 'alpinejs';
import { instalarIdioma, locale, t } from '../i18n/index.js';

/**
 * Vista de campo del Nodo D (T-015): lista las alertas aprobadas y envía el acuse «Recibido».
 * El acuse viaja por el canal al líder; D lo reintenta hasta que la central lo confirma.
 */
function campo(config) {
    return {
        alertas: [],
        lider: null,
        epoca: null,
        conectado: true,
        enviando: null,
        error: null,
        ahora: Date.now(),

        iniciar() {
            this.consultar();
            setInterval(() => this.consultar(), config.pollingMs);
            setInterval(() => (this.ahora = Date.now()), 1000);
        },

        async consultar() {
            try {
                const respuesta = await fetch('/api/campo/alertas', { headers: { Accept: 'application/json' }, signal: AbortSignal.timeout(4000) });
                if (!respuesta.ok) {
                    throw new Error(`HTTP ${respuesta.status}`);
                }
                const datos = await respuesta.json();
                this.alertas = datos.alertas;
                this.lider = datos.lider;
                this.epoca = datos.epoca;
                this.conectado = true;
            } catch (error) {
                this.conectado = false;
            }
        },

        async recibido(uuid) {
            this.enviando = uuid;
            this.error = null;
            try {
                const respuesta = await fetch('/api/campo/ack', {
                    method: 'POST',
                    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify({ alerta_uuid: uuid }),
                });
                if (!respuesta.ok) {
                    throw new Error(`HTTP ${respuesta.status}`);
                }
                await this.consultar();
            } catch (error) {
                this.error = t('No se pudo registrar el acuse en este equipo. Toca «Recibido» de nuevo.');
            } finally {
                this.enviando = null;
            }
        },

        get sinConfirmar() {
            return this.alertas.filter((a) => !a.confirmado_en).length;
        },

        estado(alerta) {
            if (alerta.confirmado_en) {
                return 'confirmado';
            }

            return alerta.ack_en ? 'enviando' : 'nueva';
        },

        hora(iso) {
            return iso ? new Date(iso).toLocaleTimeString(locale(), { hour: '2-digit', minute: '2-digit', second: '2-digit' }) : '';
        },

        haceCuanto(iso) {
            const segundos = Math.max(0, Math.round((this.ahora - new Date(iso).getTime()) / 1000));
            if (segundos < 60) {
                return t('hace :s s', { s: segundos });
            }

            return t('hace :m min', { m: Math.floor(segundos / 60) });
        },
    };
}

window.campo = campo;
window.Alpine = Alpine;
instalarIdioma(Alpine, { titulo: 'Campo · SARD' });
Alpine.start();
