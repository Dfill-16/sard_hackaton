/*
 * app.js — orquesta el arranque: errores, iconos, preferencias, HUD, router y primera escena.
 */
(function (SARD) {
    'use strict';

    const { Estado } = SARD;

    function escucharReduccionMovimiento() {
        const consulta = window.matchMedia('(prefers-reduced-motion: reduce)');
        const aplicar = () => {
            Estado.set('reduccionMovimiento', consulta.matches);
            document.documentElement.classList.toggle('movimiento-reducido', consulta.matches);
        };
        aplicar();
        consulta.addEventListener('change', () => {
            aplicar();
            // La escena activa salta a su estado final en cuanto se activa la preferencia.
            const actual = SARD.Router.actual();
            if (consulta.matches && actual) { SARD.Escenas.llamar(actual, 'estadoFinal'); }
        });
    }

    /** Espera solo a lo que de verdad se descarga en P0: las 4 fuentes locales. Loader honesto. */
    async function esperarFuentes() {
        if (!document.fonts || !document.fonts.load) { return; }
        const pesos = [400, 500, 600, 700];
        let listas = 0;
        await Promise.all(pesos.map((p) => document.fonts.load(`${p} 1em "Instrument Sans"`)
            .catch(() => SARD.Errores.registrar('fuente', `Instrument Sans ${p}`))
            .finally(() => SARD.HUD.cargaInicial(++listas / pesos.length))));
    }

    /**
     * Arranca el núcleo 3D si hay WebGL. Sin WebGL (o con ?sin3d para probar el fallback)
     * la presentación sigue completa en modo texto, sin descargar Three.js.
     */
    async function iniciar3D() {
        const parametros = new URLSearchParams(location.search);
        const contenedor = document.getElementById('escena-3d');
        if (parametros.has('sin3d') || !SARD.Motor.hayWebGL()) {
            SARD.Motor.aModoTexto(SARD.contenido.ui.avisos.sinWebgl);
            return;
        }
        try {
            await SARD.Motor.cargarLibreria();
            const nivel = SARD.Calidad.nivelInicial();
            Estado.set('nivelCalidad', nivel);
            SARD.Motor.alRedimensionar = () => SARD.Escenas.reajustarZona();
            SARD.Motor.iniciar(contenedor);
            const escena = SARD.Motor.escena;
            SARD.Luces.crear(escena);
            SARD.Terreno.crear(escena, nivel);
            SARD.Faro.crear(escena, nivel);
            SARD.Niebla.crear(escena, nivel);
            SARD.Calidad.aplicar(nivel);
            if (parametros.has('depurar')) { SARD.Calidad.activarPanel(); }
        } catch (e) {
            SARD.Errores.registrar('webgl', e.message);
            SARD.Motor.aModoTexto(SARD.contenido.ui.avisos.sinWebgl);
        }
    }

    function escenaInicial() {
        const id = decodeURIComponent((location.hash || '').slice(1));
        return SARD.Escenas.obtener(id) ? id : 0;
    }

    const App = {
        async iniciar() {
            SARD.Errores.instalar();
            SARD.Iconos.inyectar();
            escucharReduccionMovimiento();

            if (window.gsap) {
                gsap.registerPlugin(...[window.ScrollTrigger, window.DrawSVGPlugin, window.SplitText].filter(Boolean));
                // Evita saltos de animación al volver de una pestaña oculta (INTERACTION_SPEC §8.5).
                gsap.ticker.lagSmoothing(500, 33);
            } else {
                SARD.Errores.registrar('vendor', 'GSAP no está disponible: las transiciones serán instantáneas.');
            }

            Estado.on('modoTexto', (activo) => {
                document.body.classList.toggle('modo-texto', activo);
                document.getElementById('anclas').hidden = activo;
            });

            SARD.Escenas.construir();
            SARD.HUD.iniciar();
            SARD.Router.iniciar();

            await Promise.all([esperarFuentes(), iniciar3D()]);
            await SARD.Router.irA(escenaInicial(), { historial: false });
            SARD.HUD.ocultarLoader();
        },
    };

    SARD.App = App;
})(window.SARD = window.SARD || {});
