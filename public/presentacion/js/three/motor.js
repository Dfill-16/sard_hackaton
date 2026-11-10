/*
 * motor.js — un solo WebGLRenderer y una sola Scene para toda la presentación (TECH_PLAN §1.3).
 *
 * Sincronización GSAP ↔ Three.js: el render se cuelga de gsap.ticker, de modo que en cada
 * tick GSAP actualiza primero sus tweens (cámara, materiales, luces) y después se dibuja el
 * frame. Ningún objeto 3D se anima con un requestAnimationFrame propio; solo lo continuo
 * (deriva de la niebla, oleaje) usa los suscriptores de alFrame().
 *
 * El render se detiene: con la pestaña oculta, en modo texto, con el panel de demo abierto
 * y cuando no hay escena activa (INTERACTION_SPEC §1.5 y §8.5).
 */
(function (SARD) {
    'use strict';

    const { Estado } = SARD;

    /** FOV y multiplicador de distancia por tamaño de pantalla (INTERACTION_SPEC §6.3). */
    const PANTALLA = {
        desktop: { fov: 45, distancia: 1.0 },
        laptop: { fov: 48, distancia: 1.08 },
        tablet: { fov: 52, distancia: 1.18 },
        movil: { fov: 60, distancia: 1.35 },
    };

    /**
     * Encuadre por defecto: plano general del faro sobre la costa. Apunta al faro; la zona
     * libre lo coloca junto a la tarjeta y alto: 20 garantiza que la torre entra completa.
     */
    const PLANO_GENERAL = { pos: [-4, 11, 32], objetivo: [-8, 9, -6], alto: 20 };

    let renderer = null;
    let escena = null;
    let camara = null;
    let controles = null;
    let contenedor = null;
    let activo = false;
    let ancho = 1;
    let alto = 1;
    let ultimoEncuadre = PLANO_GENERAL;
    let tweenCamara = null;
    /** Zona libre de tarjetas, en px CSS: el 3D se centra y se ajusta dentro de ella. */
    let zona = null;
    const suscriptores = new Set();
    const objetivo = { x: 0, y: 0, z: 0 };

    function pantalla() {
        const w = window.innerWidth;
        if (w < 768) { return 'movil'; }
        if (w < 1024) { return 'tablet'; }
        if (w < 1280) { return 'laptop'; }
        return 'desktop';
    }

    function puedeOrbitar() {
        const p = pantalla();
        return !Estado.get('reduccionMovimiento') && (p === 'desktop' || p === 'laptop');
    }

    let pantallaPrevia = null;

    function redimensionar() {
        if (!renderer) { return; }
        const actual = pantalla();
        const cambioDePantalla = pantallaPrevia !== null && pantallaPrevia !== actual;
        pantallaPrevia = actual;
        ancho = contenedor.clientWidth || window.innerWidth;
        alto = contenedor.clientHeight || window.innerHeight;
        camara.aspect = ancho / alto;
        camara.fov = PANTALLA[pantalla()].fov;
        renderer.setSize(ancho, alto, false);
        // La escena activa vuelve a medir su tarjeta (la zona libre cambia con el tamaño).
        if (typeof Motor.alRedimensionar === 'function') { Motor.alRedimensionar(); }
        aplicarZona();
        if (controles) { controles.enabled = controles.enabled && puedeOrbitar(); }
        // Al cruzar un punto de corte cambia el multiplicador de distancia: se re-encuadra.
        if (cambioDePantalla) { Motor.encuadrar(ultimoEncuadre, { duracion: 0 }); }
    }

    /**
     * Desplaza el centro óptico de la cámara al centro de la zona libre (setViewOffset): el
     * objetivo del encuadre se ve en el hueco que deja la tarjeta, no detrás de ella.
     */
    function aplicarZona() {
        if (!camara) { return; }
        if (zona) {
            const dx = (zona.x + zona.w / 2) - ancho / 2;
            const dy = (zona.y + zona.h / 2) - alto / 2;
            camara.setViewOffset(ancho, alto, -dx, -dy, ancho, alto);
        } else {
            camara.clearViewOffset();
        }
        camara.updateProjectionMatrix();
    }

    /**
     * Distancia mínima para que un ancho/alto del mundo (en el plano del objetivo) quepa en la
     * zona libre, con un 10 % de margen.
     */
    function distanciaMinima(encuadre) {
        if (!encuadre.ancho && !encuadre.alto) { return 0; }
        const tanMedio = Math.tan(THREE.MathUtils.degToRad(camara.fov / 2));
        const fraccionAncho = zona ? zona.w / ancho : 1;
        const fraccionAlto = zona ? zona.h / alto : 1;
        const porAncho = encuadre.ancho ? (encuadre.ancho * 1.1) / (2 * tanMedio * camara.aspect * fraccionAncho) : 0;
        const porAlto = encuadre.alto ? (encuadre.alto * 1.1) / (2 * tanMedio * fraccionAlto) : 0;
        return Math.max(porAncho, porAlto);
    }

    function renderizar(tiempo, deltaMs) {
        if (!activo || !renderer || document.hidden) { return; }
        if (Estado.get('modoTexto') || Estado.get('demoAbierta')) { return; }
        const dt = Math.min(0.05, deltaMs / 1000);
        if (controles && controles.enabled) { controles.update(); }
        suscriptores.forEach((fn) => {
            try { fn(dt, tiempo); } catch (e) { SARD.Errores.registrar('frame', e.message); suscriptores.delete(fn); }
        });
        renderer.render(escena, camara);
        SARD.Calidad?.medir(deltaMs);
    }

    /** Libera geometrías, materiales y texturas propios (no los compartidos de M3). */
    function liberar(objeto) {
        objeto.traverse((o) => {
            if (o.geometry && !SARD.M3.esCompartido(o.geometry)) { o.geometry.dispose(); }
            const mats = o.material ? [].concat(o.material) : [];
            mats.forEach((m) => {
                if (SARD.M3.esCompartido(m)) { return; }
                Object.values(m).forEach((v) => { if (v && v.isTexture && !SARD.M3.esCompartido(v)) { v.dispose(); } });
                m.dispose();
            });
            if (typeof o.dispose === 'function' && !o.isScene) { o.dispose(); }
        });
    }

    const Motor = {
        PLANO_GENERAL,

        /** Detección de WebGL (INTERACTION_SPEC §7.5). */
        hayWebGL() {
            try {
                const c = document.createElement('canvas');
                return !!((window.WebGL2RenderingContext && c.getContext('webgl2'))
                    || (window.WebGLRenderingContext && c.getContext('webgl')));
            } catch (e) {
                return false;
            }
        },

        /** Carga Three.js solo si hay WebGL (en modo texto no se descarga). */
        cargarLibreria() {
            if (window.THREE) { return Promise.resolve(); }
            return new Promise((ok, falla) => {
                const s = document.createElement('script');
                s.src = 'vendor/three.iife.min.js';
                s.onload = () => (window.THREE ? ok() : falla(new Error('THREE no quedó definido')));
                s.onerror = () => falla(new Error('No se pudo cargar vendor/three.iife.min.js'));
                document.head.append(s);
            });
        },

        iniciar(nodo) {
            contenedor = nodo;
            const nivel = Estado.get('nivelCalidad');
            renderer = new THREE.WebGLRenderer({ antialias: nivel === 0, powerPreference: 'high-performance', alpha: false });
            renderer.setPixelRatio(SARD.Calidad.ratioPixeles(nivel));
            renderer.outputColorSpace = THREE.SRGBColorSpace;
            renderer.toneMapping = THREE.ACESFilmicToneMapping;
            renderer.toneMappingExposure = 1.05;
            renderer.shadowMap.enabled = nivel < 2;
            renderer.shadowMap.type = THREE.PCFSoftShadowMap;
            renderer.setClearColor(0x0a0a0a, 1);

            const canvas = renderer.domElement;
            canvas.setAttribute('aria-hidden', 'true');
            canvas.setAttribute('tabindex', '-1');
            canvas.addEventListener('webglcontextlost', (e) => {
                e.preventDefault();
                SARD.Errores.registrar('webgl', 'contexto perdido');
                Motor.aModoTexto(SARD.contenido.ui.avisos.contextoPerdido);
            });
            contenedor.append(canvas);
            contenedor.classList.add('con-3d');

            escena = new THREE.Scene();
            escena.background = new THREE.Color(0x0a0a0a);
            escena.fog = new THREE.FogExp2(0x0a0a0a, 0.022);

            camara = new THREE.PerspectiveCamera(PANTALLA[pantalla()].fov, 1, 0.1, 1000);

            let espera = null;
            new ResizeObserver(() => {
                clearTimeout(espera);
                espera = setTimeout(redimensionar, 120); // debounce
            }).observe(contenedor);
            redimensionar();
            Motor.encuadrar(PLANO_GENERAL, { duracion: 0 });

            gsap.ticker.add(renderizar);
            activo = true;
        },

        get escena() { return escena; },
        get camara() { return camara; },
        get renderer() { return renderer; },
        get activo() { return activo; },
        get ancho() { return ancho; },
        get alto() { return alto; },
        pantalla,

        /** Suscribe una función continua (dt, tiempo) al frame. Devuelve la baja. */
        alFrame(fn) {
            suscriptores.add(fn);
            return () => suscriptores.delete(fn);
        },

        /** Crea un grupo propio de una escena dentro de la escena raíz. */
        crearGrupo(nombre) {
            const g = new THREE.Group();
            g.name = nombre;
            escena.add(g);
            return g;
        },

        /** Quita un grupo de la escena y libera sus recursos no compartidos. */
        retirar(grupo) {
            if (!grupo) { return; }
            grupo.parent?.remove(grupo);
            liberar(grupo);
        },

        /**
         * Mueve la cámara con GSAP (dolly / órbita lenta). lookAt se actualiza en onUpdate
         * porque no es interpolable. Con reduced-motion el cambio es inmediato.
         * @param {{pos: number[], objetivo: number[]}} encuadre
         * @param {{duracion?: number, ease?: string}} [opciones]
         * @returns {gsap.core.Tween|null}
         */
        encuadrar(encuadre, opciones = {}) {
            if (!camara) { return null; }
            ultimoEncuadre = encuadre;
            const [ox, oy, oz] = encuadre.objetivo;
            // Distancia: la del encuadre × el multiplicador de pantalla, o más si la figura
            // declarada (encuadre.ancho / encuadre.alto) no cabría en la zona libre.
            const base = Math.hypot(encuadre.pos[0] - ox, encuadre.pos[1] - oy, encuadre.pos[2] - oz);
            const distancia = Math.max(base * PANTALLA[pantalla()].distancia, distanciaMinima(encuadre));
            const mult = distancia / base;
            const destino = {
                x: ox + (encuadre.pos[0] - ox) * mult,
                y: oy + (encuadre.pos[1] - oy) * mult,
                z: oz + (encuadre.pos[2] - oz) * mult,
            };
            const duracion = Estado.get('reduccionMovimiento') ? 0 : (opciones.duracion ?? 1.6);
            tweenCamara?.kill();
            Motor.detenerOrbita(); // la cámara la mueve GSAP: los controles no deben pelear con el tween
            const aplicar = () => {
                camara.lookAt(objetivo.x, objetivo.y, objetivo.z);
                if (controles) { controles.target.set(objetivo.x, objetivo.y, objetivo.z); }
            };
            if (duracion === 0) {
                camara.position.set(destino.x, destino.y, destino.z);
                Object.assign(objetivo, { x: ox, y: oy, z: oz });
                aplicar();
                tweenCamara = null;
                return null;
            }
            const estadoTween = { t: 0 };
            const desdePos = camara.position.clone();
            const desdeObj = { ...objetivo };
            tweenCamara = gsap.to(estadoTween, {
                t: 1,
                duration: duracion,
                ease: opciones.ease || 'power2.inOut',
                onUpdate: () => {
                    const t = estadoTween.t;
                    camara.position.set(
                        desdePos.x + (destino.x - desdePos.x) * t,
                        desdePos.y + (destino.y - desdePos.y) * t,
                        desdePos.z + (destino.z - desdePos.z) * t,
                    );
                    objetivo.x = desdeObj.x + (ox - desdeObj.x) * t;
                    objetivo.y = desdeObj.y + (oy - desdeObj.y) * t;
                    objetivo.z = desdeObj.z + (oz - desdeObj.z) * t;
                    aplicar();
                },
            });
            return tweenCamara;
        },

        /**
         * Activa la órbita manual con límites (solo desktop/laptop y sin reduced-motion).
         * @param {{azimut?: number, polarMin?: number, polarMax?: number, distanciaMin?: number, distanciaMax?: number}} lim
         */
        orbitar(lim = {}) {
            if (!puedeOrbitar() || !THREE.OrbitControls) { return; }
            if (!controles) {
                controles = new THREE.OrbitControls(camara, renderer.domElement);
                controles.enableDamping = true;
                controles.dampingFactor = 0.06;
                controles.enablePan = false;
                controles.enableZoom = true;
            }
            const azimutBase = Math.atan2(camara.position.x - objetivo.x, camara.position.z - objetivo.z);
            const az = lim.azimut ?? THREE.MathUtils.degToRad(25);
            controles.minAzimuthAngle = azimutBase - az;
            controles.maxAzimuthAngle = azimutBase + az;
            controles.minPolarAngle = lim.polarMin ?? 0.5;
            controles.maxPolarAngle = lim.polarMax ?? 1.4;
            const d = camara.position.distanceTo(new THREE.Vector3(objetivo.x, objetivo.y, objetivo.z));
            controles.minDistance = lim.distanciaMin ?? d * 0.6;
            controles.maxDistance = lim.distanciaMax ?? d * 1.4;
            controles.target.set(objetivo.x, objetivo.y, objetivo.z);
            controles.enabled = true;
            renderer.domElement.style.cursor = 'grab';
        },

        detenerOrbita() {
            if (controles) { controles.enabled = false; }
            if (renderer) { renderer.domElement.style.cursor = ''; }
        },

        /**
         * Deriva orbital lenta alrededor del objetivo (± grados, ida y vuelta). Se detiene sola
         * en cuanto el presentador arrastra la cámara. Devuelve el tween (la escena lo registra
         * como loop). Sin movimiento con reduced-motion.
         */
        derivaOrbital(grados = 6, periodo = 14, retardo = 0) {
            if (!camara || Estado.get('reduccionMovimiento')) { return null; }
            const centro = new THREE.Vector3();
            const estado = { a: 0 };
            let radio = 0;
            let base = 0;
            const mover = () => {
                const ang = base + THREE.MathUtils.degToRad(grados) * estado.a;
                camara.position.set(centro.x + Math.sin(ang) * radio, camara.position.y, centro.z + Math.cos(ang) * radio);
                camara.lookAt(centro);
            };
            // Un solo timeline (lo mata la escena al salir): cuarto inicial 0 → +g y luego vaivén ±g.
            const tl = gsap.timeline({
                delay: retardo,
                // La geometría se toma al arrancar: para entonces el encuadre ya terminó.
                onStart: () => {
                    centro.set(objetivo.x, objetivo.y, objetivo.z);
                    const rel = camara.position.clone().sub(centro);
                    radio = Math.hypot(rel.x, rel.z);
                    base = Math.atan2(rel.x, rel.z);
                },
            });
            tl.to(estado, { a: 1, duration: periodo / 4, ease: 'sine.out', onUpdate: mover })
                .to(estado, { a: -1, duration: periodo / 2, ease: 'sine.inOut', yoyo: true, repeat: -1, onUpdate: mover });
            if (controles) {
                const alArrastrar = () => { tl.kill(); controles.removeEventListener('start', alArrastrar); };
                controles.addEventListener('start', alArrastrar);
            }
            return tl;
        },

        /**
         * Fija la zona libre de tarjetas ({x, y, w, h} en px CSS) o la quita (null).
         * Se aplica antes de encuadrar: el encuadre usa su tamaño para calcular la distancia.
         */
        zonaLibre(rect) {
            zona = rect && rect.w > 80 && rect.h > 80 ? rect : null;
            aplicarZona();
        },

        get zona() { return zona; },

        /** Re-encuadra tras un cambio de breakpoint. */
        reencuadrar() { Motor.encuadrar(ultimoEncuadre, { duracion: 0 }); },

        /** Aplica un nivel de calidad al renderer ya creado. */
        aplicarCalidad(nivel) {
            if (!renderer) { return; }
            renderer.setPixelRatio(SARD.Calidad.ratioPixeles(nivel));
            renderer.shadowMap.enabled = nivel < 2;
            renderer.setSize(ancho, alto, false);
            escena.traverse((o) => { if (o.material) { [].concat(o.material).forEach((m) => { m.needsUpdate = true; }); } });
        },

        /** Detiene el 3D y pasa a modo texto (sin WebGL, contexto perdido o nivel 3). */
        aModoTexto(mensaje) {
            activo = false;
            if (gsap && gsap.ticker) { gsap.ticker.remove(renderizar); }
            Motor.detenerOrbita();
            if (contenedor) {
                contenedor.classList.remove('con-3d');
                contenedor.querySelector('canvas')?.remove();
            }
            Estado.set('modoTexto', true);
            if (mensaje) { SARD.HUD.aviso(mensaje); }
        },

        /** Datos para el panel de depuración y la QA. */
        info() {
            if (!renderer) { return null; }
            const r = renderer.info.render;
            return { llamadas: r.calls, triangulos: r.triangles, geometrias: renderer.info.memory.geometries, texturas: renderer.info.memory.textures };
        },
    };

    SARD.Motor = Motor;
})(window.SARD = window.SARD || {});
