/*
 * luces.js — iluminación base compartida (TECH_PLAN §6.2). Las luces se REUTILIZAN:
 * las escenas las mueven o cambian su intensidad, nunca crean otras de su tipo.
 *
 * Recalibración (desviación C6): Three.js r170 usa unidades físicas (candelas) en luces
 * puntuales. Se conservan las PROPORCIONES de los documentos (linterna 2.0 → 3.2,
 * baliza 1.2) escaladas por FACTOR_PUNTUAL para que se vean igual que lo diseñado.
 */
(function (SARD) {
    'use strict';

    /** Escala de intensidad de diseño (adimensional) → candelas en r170 con decay 1.4. */
    const FACTOR_PUNTUAL = 45;

    const Luces = {
        FACTOR_PUNTUAL,
        hemisferio: null,
        linterna: null,
        baliza: null,
        contraluz: null,
        estado: null,

        /** Convierte una intensidad de diseño (p. ej. 3.2 del storyboard) a la del motor. */
        puntual: (diseno) => diseno * FACTOR_PUNTUAL,

        crear(escena) {
            // 0.35 en el concepto; 0.6 para que el relieve se lea sin postprocesado (desviación C6).
            Luces.hemisferio = new THREE.HemisphereLight(0x1b2430, 0x0a0a0a, 0.6);
            escena.add(Luces.hemisferio);

            Luces.contraluz = new THREE.DirectionalLight(0x3a4a5c, 0.25);
            Luces.contraluz.position.set(30, 25, -60); // atrás-derecha: separa la silueta de la niebla
            escena.add(Luces.contraluz);

            // Linterna del faro: la única luz fuerte, cálida, con sombras.
            Luces.linterna = new THREE.PointLight(0xfff6e8, Luces.puntual(2.2), 60, 1.4);
            Luces.linterna.castShadow = true;
            Luces.linterna.shadow.mapSize.set(1024, 1024);
            Luces.linterna.shadow.bias = -0.002;
            Luces.linterna.shadow.camera.near = 0.5;
            Luces.linterna.shadow.camera.far = 60;

            // Baliza de acento #ff4433 en la base de la torre (emergencia del producto).
            // Alcance corto: el acento se reserva a un solo elemento y no debe teñir el relieve.
            Luces.baliza = new THREE.PointLight(0xff4433, Luces.puntual(1.2), 7, 1.8);

            // Estado (acuse #4ade80): apagada hasta que una escena la use.
            Luces.estado = new THREE.PointLight(0x4ade80, 0, 20, 1.4);
            escena.add(Luces.estado);
        },

        /** Sombras por nivel de calidad: 1024² · 512² · sin sombras. */
        aplicarCalidad(nivel) {
            if (!Luces.linterna) { return; }
            Luces.linterna.castShadow = nivel < 2;
            const lado = nivel === 0 ? 1024 : 512;
            if (Luces.linterna.shadow.mapSize.x !== lado) {
                Luces.linterna.shadow.mapSize.set(lado, lado);
                Luces.linterna.shadow.map?.dispose();
                Luces.linterna.shadow.map = null;
            }
        },
    };

    SARD.Luces = Luces;
})(window.SARD = window.SARD || {});
