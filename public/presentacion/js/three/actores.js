/*
 * actores.js — actores no humanos y la silueta del operador (PRESENTATION_CONCEPT §5.4).
 *
 * Regla de la silueta: siempre en contraluz, nunca un rostro. Su única acción es la mano
 * sobre un panel. La persona es irremplazable; no es un personaje. Se reutiliza en S03, S12, S13.
 */
(function (SARD) {
    'use strict';

    /**
     * Topología compartida por S09, S10 y S13 (coordenadas x/z del mundo). B es el faro.
     * Se resuelve en tiempo de ejecución para usar la posición real del faro y la altura del relieve.
     */
    function topologia() {
        const alto = (x, z) => SARD.Terreno.altura(x, z);
        const f = SARD.Faro.grupo.position;
        const p = (x, z, extra = 0) => new THREE.Vector3(x, alto(x, z) + extra, z);
        return {
            A: p(-22, 5),
            CANAL: p(-15.5, -1, 3),
            B: new THREE.Vector3(f.x, f.y, f.z),
            C: p(6, -8),
            D: p(8, 8),
        };
    }

    const Actores = {
        topologia,

        /** Nodo A: cubo sobrio con anillo pulsante (ingesta y cola de salida). */
        crearNodoA() {
            const g = new THREE.Group();
            const cubo = new THREE.Mesh(SARD.M3.geo('Box', [1, 1, 1]), new THREE.MeshStandardMaterial({ color: 0x232322, emissive: 0xc8d8e8, emissiveIntensity: 0.12, roughness: 0.5, metalness: 0.3 }));
            cubo.scale.set(2, 2, 2);
            cubo.position.y = 1;
            cubo.castShadow = true;
            const anillo = new THREE.Mesh(SARD.M3.geo('Torus', [1.9, 0.05, 8, 48]), new THREE.MeshBasicMaterial({ color: 0xc8d8e8, transparent: true, opacity: 0.6 }));
            anillo.rotation.x = Math.PI / 2;
            anillo.position.y = 0.1;
            g.add(cubo, anillo);
            g.userData = { cubo, anillo };
            return g;
        },

        /** Unidad de campo (nodo D): caja baja con dos emisores. Se reutiliza en S13. */
        crearVehiculo() {
            const g = new THREE.Group();
            const cuerpo = new THREE.Mesh(SARD.M3.geo('Box', [1, 1, 1]), SARD.M3.mat('torre'));
            cuerpo.scale.set(2.4, 1, 1.2);
            cuerpo.position.y = 0.7;
            cuerpo.castShadow = true;
            const cabina = new THREE.Mesh(SARD.M3.geo('Box', [1, 1, 1]), SARD.M3.mat('torre'));
            cabina.scale.set(0.9, 0.6, 1.1);
            cabina.position.set(0.7, 1.45, 0);
            const emisores = [-0.4, 0.4].map((z) => {
                const e = new THREE.Mesh(SARD.M3.geo('Sphere', [0.14, 10, 8]), new THREE.MeshStandardMaterial({ color: 0x4ade80, emissive: 0x4ade80, emissiveIntensity: 1.2 }));
                e.position.set(0.7, 1.85, z);
                return e;
            });
            g.add(cuerpo, cabina, ...emisores);
            g.userData = { emisores };
            return g;
        },

        /**
         * Línea de conexión entre dos puntos con un arco suave (TubeGeometry sobre Catmull-Rom).
         * Devuelve la malla y la curva (para que viajen partículas por ella).
         */
        crearLinea(desde, hasta, color, { arco = 2, radio = 0.07, opacidad = 0.55 } = {}) {
            const medio = desde.clone().lerp(hasta, 0.5);
            medio.y += arco;
            const curva = new THREE.CatmullRomCurve3([desde.clone().setY(desde.y + 0.8), medio, hasta.clone().setY(hasta.y + 0.8)]);
            const tubo = new THREE.Mesh(new THREE.TubeGeometry(curva, 48, radio, 6, false),
                new THREE.MeshBasicMaterial({ color, transparent: true, opacity: opacidad, depthWrite: false }));
            return { tubo, curva };
        },

        /** Partícula luminosa que viaja por una curva (sprite aditivo). */
        crearPulso(color, escala = 1.2) {
            const s = new THREE.Sprite(new THREE.SpriteMaterial({ map: SARD.M3.textura('particula'), color, transparent: true, opacity: 0, blending: THREE.AdditiveBlending, depthWrite: false, fog: false }));
            s.scale.set(escala, escala, 1);
            return s;
        },
        /**
         * Silueta del operador frente a su panel. Devuelve el grupo y la cabeza (para el
         * microgesto de S03). El grupo mira hacia −z local; la escena lo orienta.
         */
        crearOperador() {
            const mat = SARD.M3.mat('silueta', { color: 0x101010, roughness: 1, metalness: 0 });
            const grupo = new THREE.Group();
            grupo.name = 'operador';

            const cuerpo = new THREE.Mesh(SARD.M3.geo('Capsule', [0.26, 0.85, 4, 12]), mat);
            cuerpo.position.y = 0.72;
            cuerpo.castShadow = true;

            const cuello = new THREE.Group();
            cuello.position.y = 1.32;
            const cabeza = new THREE.Mesh(SARD.M3.geo('Sphere', [0.19, 16, 12]), mat);
            cabeza.position.y = 0.16;
            cabeza.castShadow = true;
            cuello.add(cabeza);

            const brazo = new THREE.Mesh(SARD.M3.geo('Capsule', [0.07, 0.5, 4, 8]), mat);
            brazo.position.set(0.18, 0.95, -0.22);
            brazo.rotation.x = -1.0; // la mano sobre el panel

            // Panel: atril con la pantalla tenue (el texto real va en el DOM, nunca en el canvas).
            const atril = new THREE.Mesh(SARD.M3.geo('Box', [0.06, 0.9, 0.06]), mat);
            atril.position.set(0, 0.45, -0.55);
            const pantalla = new THREE.Mesh(SARD.M3.geo('Box', [0.62, 0.4, 0.04]),
                new THREE.MeshStandardMaterial({ color: 0x161615, emissive: 0xc8d8e8, emissiveIntensity: 0.35 }));
            pantalla.position.set(0, 0.98, -0.55);
            pantalla.rotation.x = -0.5;

            grupo.add(cuerpo, cuello, brazo, atril, pantalla);
            return { grupo, cabeza: cuello, pantalla };
        },
    };

    SARD.Actores = Actores;
})(window.SARD = window.SARD || {});
