/*
 * materiales.js — caché de geometrías, materiales y texturas procedurales (CanvasTexture).
 * Lo que sale de aquí es COMPARTIDO: Motor.retirar() nunca lo libera; solo liberarTodo().
 * Presupuesto: ≤ 18 materiales (INTERACTION_SPEC §8.2).
 */
(function (SARD) {
    'use strict';

    const geometrias = new Map();
    const materiales = new Map();
    const texturas = new Map();
    const compartidos = new WeakSet();

    function marcar(recurso) { compartidos.add(recurso); return recurso; }

    /** Lienzo 2D auxiliar. */
    function lienzo(lado, dibujar) {
        const c = document.createElement('canvas');
        c.width = c.height = lado;
        dibujar(c.getContext('2d'), lado);
        return c;
    }

    const FABRICAS_TEXTURA = {
        /** Halo radial 256×256 (linterna, boyas). */
        halo: () => lienzo(256, (g, l) => {
            const r = g.createRadialGradient(l / 2, l / 2, 0, l / 2, l / 2, l / 2);
            r.addColorStop(0, 'rgba(255,250,237,1)');
            r.addColorStop(0.18, 'rgba(255,246,232,0.55)');
            r.addColorStop(0.45, 'rgba(255,246,232,0.12)');
            r.addColorStop(1, 'rgba(255,246,232,0)');
            g.fillStyle = r;
            g.fillRect(0, 0, l, l);
        }),
        /** Partícula suave 64×64 (niebla y mensajes). */
        particula: () => lienzo(64, (g, l) => {
            const r = g.createRadialGradient(l / 2, l / 2, 0, l / 2, l / 2, l / 2);
            r.addColorStop(0, 'rgba(255,255,255,1)');
            r.addColorStop(0.4, 'rgba(255,255,255,0.35)');
            r.addColorStop(1, 'rgba(255,255,255,0)');
            g.fillStyle = r;
            g.fillRect(0, 0, l, l);
        }),
        /** Rejilla tileable 256×256 con el color de --sard-map-grid. */
        rejilla: () => lienzo(256, (g, l) => {
            g.fillStyle = '#0d0d0c';
            g.fillRect(0, 0, l, l);
            g.strokeStyle = 'rgba(255,250,237,0.09)';
            g.lineWidth = 2;
            g.strokeRect(0, 0, l, l);
        }),
    };

    const M3 = {
        /**
         * Geometría compartida por tipo + argumentos.
         * @param {string} tipo  Box | Cylinder | Sphere | Torus | Plane | Cone | Circle | Ring | Capsule | Icosahedron
         * @param {Array} args
         */
        geo(tipo, args = []) {
            const clave = `${tipo}:${JSON.stringify(args)}`;
            if (!geometrias.has(clave)) {
                const Clase = THREE[`${tipo}Geometry`];
                if (!Clase) { throw new Error(`Geometría desconocida: ${tipo}`); }
                geometrias.set(clave, marcar(new Clase(...args)));
            }
            return geometrias.get(clave);
        },

        /**
         * Material compartido por clave. tipo: standard | basic | sprite | points.
         * La primera llamada con una clave fija sus opciones.
         */
        mat(clave, opciones = {}, tipo = 'standard') {
            if (!materiales.has(clave)) {
                const Clases = {
                    standard: THREE.MeshStandardMaterial,
                    basic: THREE.MeshBasicMaterial,
                    sprite: THREE.SpriteMaterial,
                    points: THREE.PointsMaterial,
                };
                materiales.set(clave, marcar(new Clases[tipo](opciones)));
            }
            return materiales.get(clave);
        },

        /** Textura procedural compartida (halo | particula | rejilla). */
        textura(clave) {
            if (!texturas.has(clave)) {
                const t = new THREE.CanvasTexture(FABRICAS_TEXTURA[clave]());
                t.colorSpace = THREE.SRGBColorSpace;
                if (clave === 'rejilla') {
                    t.wrapS = t.wrapT = THREE.RepeatWrapping;
                    t.anisotropy = 4;
                }
                texturas.set(clave, marcar(t));
            }
            return texturas.get(clave);
        },

        esCompartido: (recurso) => compartidos.has(recurso),

        conteo: () => ({ geometrias: geometrias.size, materiales: materiales.size, texturas: texturas.size }),

        liberarTodo() {
            geometrias.forEach((g) => g.dispose());
            materiales.forEach((m) => m.dispose());
            texturas.forEach((t) => t.dispose());
            geometrias.clear();
            materiales.clear();
            texturas.clear();
        },
    };

    /** Generador pseudoaleatorio determinista (LCG) para que el relieve sea siempre el mismo. */
    M3.aleatorio = function (semilla = 42) {
        let s = semilla >>> 0;
        return () => {
            s = (Math.imul(1664525, s) + 1013904223) >>> 0;
            return s / 4294967296;
        };
    };

    SARD.M3 = M3;
})(window.SARD = window.SARD || {});
