/*
 * niebla.js — la niebla ES la incertidumbre (PRESENTATION_CONCEPT §3).
 *
 * Un solo control (desviación C7): Niebla.a(densidad) anima a la vez
 *   · la densidad de FogExp2 (lo que se ve lejos), y
 *   · la opacidad de una capa de partículas cercanas (0–8 m), proporcional a la densidad.
 * Densidades del storyboard: 0.030 máxima (S00) … 0.008 despejada (S13 final).
 * Las partículas derivan lento (0.02–0.05 u/s): indican niebla, nunca datos.
 */
(function (SARD) {
    'use strict';

    const DENSIDAD_MAX = 0.03;
    const OPACIDAD_MAX = 0.09;
    const CANTIDAD = { 0: 520, 1: 0, 2: 0 }; // nivel ≥ 1: sin partículas ambientales (INTERACTION_SPEC §8.6)

    let puntos = null;
    let velocidades = null;
    let baja = null;
    const control = { densidad: 0.022 };
    let tween = null;

    function opacidadPara(densidad) {
        return Math.min(1, densidad / DENSIDAD_MAX) * OPACIDAD_MAX;
    }

    function crearParticulas(cantidad) {
        const azar = SARD.M3.aleatorio(7);
        const pos = new Float32Array(cantidad * 3);
        velocidades = new Float32Array(cantidad);
        for (let i = 0; i < cantidad; i++) {
            // Banda cercana a la cámara y a lo largo de la costa.
            pos[i * 3] = (azar() - 0.5) * 90;
            pos[i * 3 + 1] = 0.5 + azar() * 9;
            pos[i * 3 + 2] = -30 + azar() * 70;
            velocidades[i] = 0.02 + azar() * 0.03;
        }
        const geo = new THREE.BufferGeometry();
        geo.setAttribute('position', new THREE.BufferAttribute(pos, 3));
        const mat = new THREE.PointsMaterial({
            map: SARD.M3.textura('particula'),
            color: 0xc8d8e8,
            size: 14,
            sizeAttenuation: true,
            transparent: true,
            opacity: opacidadPara(control.densidad),
            depthWrite: false,
            blending: THREE.AdditiveBlending,
            fog: false,
        });
        const p = new THREE.Points(geo, mat);
        p.name = 'niebla-particulas';
        p.renderOrder = 10;
        p.frustumCulled = false;
        return p;
    }

    function derivar(dt, tiempo) {
        if (!puntos || SARD.Estado.get('reduccionMovimiento')) { return; }
        const arr = puntos.geometry.attributes.position.array;
        for (let i = 0; i < velocidades.length; i++) {
            const k = i * 3;
            arr[k] += velocidades[i] * dt * 6;
            arr[k + 1] += Math.sin(tiempo * 0.3 + i) * 0.004;
            if (arr[k] > 45) { arr[k] = -45; }
        }
        puntos.geometry.attributes.position.needsUpdate = true;
    }

    const Niebla = {
        crear(escena, nivel) {
            Niebla.aplicarCalidad(nivel, escena);
            baja = SARD.Motor.alFrame(derivar);
        },

        get densidad() { return control.densidad; },

        /**
         * Lleva la niebla a una densidad (= nivel de incertidumbre de la escena).
         * @returns {gsap.core.Tween|null}
         */
        a(densidad, duracion = 1.2, ease = 'sine.inOut') {
            const fog = SARD.Motor.escena?.fog;
            if (!fog) { return null; }
            tween?.kill();
            const aplicar = () => {
                fog.density = control.densidad;
                if (puntos) { puntos.material.opacity = opacidadPara(control.densidad); }
            };
            if (SARD.Estado.get('reduccionMovimiento') || duracion === 0) {
                control.densidad = densidad;
                aplicar();
                return null;
            }
            tween = gsap.to(control, { densidad, duration: duracion, ease, onUpdate: aplicar });
            return tween;
        },

        aplicarCalidad(nivel, escena = SARD.Motor.escena) {
            const cantidad = CANTIDAD[nivel] ?? 0;
            if (puntos && (cantidad === 0 || puntos.geometry.attributes.position.count !== cantidad)) {
                SARD.Motor.retirar(puntos);
                puntos = null;
            }
            if (!puntos && cantidad > 0) {
                puntos = crearParticulas(cantidad);
                escena.add(puntos);
            }
        },

        destruir() {
            baja?.();
            SARD.Motor.retirar(puntos);
            puntos = null;
        },
    };

    SARD.Niebla = Niebla;
})(window.SARD = window.SARD || {});
