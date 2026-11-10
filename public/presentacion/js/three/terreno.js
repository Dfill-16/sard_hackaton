/*
 * terreno.js — relieve procedural de Manizales leído como costa (sin datos externos ni PMTiles).
 * PlaneGeometry(120, 120, 64, 64) desplazado con 3 octavas de ruido de valor determinista:
 * laderas que suben hacia los lados, una cresta cercana y una costa que baja hacia el mar.
 * El mar es un plano con oleaje procedural de baja amplitud (solo en calidad 0).
 */
(function (SARD) {
    'use strict';

    const LADO = 120;
    const SEGMENTOS = 64;

    /** Ruido de valor 2D con interpolación suave, sobre una rejilla de valores fija. */
    const ruido = (() => {
        let tabla = null; // se inicializa perezosamente (M3 se carga antes de usarse)
        const N = 256;
        function preparar() {
            const a = SARD.M3.aleatorio(42);
            tabla = new Float32Array(N * N);
            for (let i = 0; i < tabla.length; i++) { tabla[i] = a(); }
        }
        const v = (x, y) => tabla[((y & (N - 1)) * N) + (x & (N - 1))];
        const suave = (t) => t * t * (3 - 2 * t);
        return (x, y) => {
            if (!tabla) { preparar(); }
            const xi = Math.floor(x); const yi = Math.floor(y);
            const tx = suave(x - xi); const ty = suave(y - yi);
            const a = v(xi, yi) + (v(xi + 1, yi) - v(xi, yi)) * tx;
            const b = v(xi, yi + 1) + (v(xi + 1, yi + 1) - v(xi, yi + 1)) * tx;
            return a + (b - a) * ty; // 0..1
        };
    })();

    const suaveEntre = (a, b, x) => {
        const t = Math.min(1, Math.max(0, (x - a) / (b - a)));
        return t * t * (3 - 2 * t);
    };

    /** Punto donde se asienta el faro (centro-izquierda, sobre la roca de la costa). */
    const POS_FARO = { x: -8, z: -6 };

    const Terreno = {
        POS_FARO,

        /** Altura del relieve en (x, z) del mundo. Las escenas la usan para apoyar objetos. */
        altura(x, z) {
            const n = ruido(x * 0.05 + 10, z * 0.05 + 10) * 2.2
                + ruido(x * 0.13 + 3, z * 0.13 + 7) * 0.9
                + ruido(x * 0.31, z * 0.31) * 0.35;
            const ladera = Math.pow(Math.abs(x) / 60, 2) * 14;                  // valle: suben los lados
            const costa = 3 - 9 * suaveEntre(2, 34, -z);                        // baja hacia el mar (−z)
            const roca = 2.6 * Math.exp(-(((x - POS_FARO.x) ** 2) + ((z - POS_FARO.z) ** 2)) / 22); // promontorio del faro
            return costa + ladera + n * (1 + ladera * 0.08) + roca - 1.2;
        },

        crear(escena, nivel) {
            const grupo = new THREE.Group();
            grupo.name = 'terreno';

            // Relieve
            const geo = new THREE.PlaneGeometry(LADO, LADO, SEGMENTOS, SEGMENTOS);
            geo.rotateX(-Math.PI / 2);
            const p = geo.attributes.position;
            for (let i = 0; i < p.count; i++) {
                p.setY(i, Terreno.altura(p.getX(i), p.getZ(i)));
            }
            geo.computeVertexNormals();
            const suelo = new THREE.Mesh(geo, SARD.M3.mat('terreno', { color: 0x161615, roughness: 0.9, metalness: 0, flatShading: true }));
            suelo.name = 'relieve';
            suelo.receiveShadow = true;
            grupo.add(suelo);

            // Mar: plano oscuro con oleaje procedural de baja amplitud.
            const geoMar = new THREE.PlaneGeometry(400, 260, 40, 26);
            geoMar.rotateX(-Math.PI / 2);
            const mar = new THREE.Mesh(geoMar, SARD.M3.mat('agua', { color: 0x0d1418, roughness: 0.25, metalness: 0.4 }));
            mar.name = 'mar';
            mar.position.set(0, -0.6, -150);
            mar.receiveShadow = true;
            grupo.add(mar);
            const base = Float32Array.from(geoMar.attributes.position.array);

            Terreno.mar = mar;
            Terreno.oleaje = nivel === 0;
            Terreno._baja = SARD.Motor.alFrame((dt, tiempo) => {
                if (!Terreno.oleaje || SARD.Estado.get('reduccionMovimiento')) { return; }
                const arr = geoMar.attributes.position.array;
                for (let i = 0; i < arr.length; i += 3) {
                    arr[i + 1] = base[i + 1] + Math.sin(base[i] * 0.08 + tiempo * 0.6) * 0.18 + Math.cos(base[i + 2] * 0.06 + tiempo * 0.45) * 0.14;
                }
                geoMar.attributes.position.needsUpdate = true;
            });

            escena.add(grupo);
            Terreno.grupo = grupo;
            return grupo;
        },

        aplicarCalidad(nivel) { Terreno.oleaje = nivel === 0; },
    };

    SARD.Terreno = Terreno;
})(window.SARD = window.SARD || {});
