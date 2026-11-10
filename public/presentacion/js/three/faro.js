/*
 * faro.js — el faro (nodo líder) sobre su roca, y la fábrica de torres para la torre gemela
 * (S09, S10). Todo procedural (ASSETS.md #46–52).
 *
 * Sin postprocesado (TECH_PLAN §6.6): el halo son dos sprites con textura radial, uno aditivo
 * (resplandor) y otro normal (núcleo). El haz es un cono aditivo, solo en calidad 0.
 */
(function (SARD) {
    'use strict';

    const ALTO_TORRE = 12;

    /** Roca basáltica irregular (cilindro bajo con vértices desplazados de forma determinista). */
    function crearRoca() {
        const geo = new THREE.CylinderGeometry(2.6, 3.6, 1.6, 18, 2);
        const azar = SARD.M3.aleatorio(11);
        const p = geo.attributes.position;
        for (let i = 0; i < p.count; i++) {
            const f = 0.85 + azar() * 0.3;
            p.setX(i, p.getX(i) * f);
            p.setZ(i, p.getZ(i) * f);
            if (p.getY(i) > 0) { p.setY(i, p.getY(i) + (azar() - 0.5) * 0.4); }
        }
        geo.computeVertexNormals();
        const roca = new THREE.Mesh(geo, SARD.M3.mat('roca', { color: 0x1a1a19, roughness: 0.95, metalness: 0, flatShading: true }));
        roca.name = 'roca';
        roca.castShadow = true;
        roca.receiveShadow = true;
        return roca;
    }

    /**
     * Torre genérica (cuerpo + galería + linterna + halo). Reutilizada por el faro y la gemela.
     * @param {{baliza: number, nombre: string}} opciones
     */
    function construirTorre({ nombre, baliza }) {
        const torre = new THREE.Group();
        torre.name = nombre;

        const cuerpo = new THREE.Mesh(SARD.M3.geo('Cylinder', [0.8, 1.4, ALTO_TORRE, 24]),
            SARD.M3.mat('torre', { color: 0x232322, roughness: 0.55, metalness: 0.25 }));
        cuerpo.position.y = ALTO_TORRE / 2;
        cuerpo.castShadow = true;
        cuerpo.receiveShadow = true;
        cuerpo.name = 'cuerpo';
        torre.add(cuerpo);

        const galeria = new THREE.Mesh(SARD.M3.geo('Cylinder', [1.15, 1.15, 0.18, 24]), SARD.M3.mat('torre'));
        galeria.position.y = ALTO_TORRE + 0.05;
        torre.add(galeria);

        const techo = new THREE.Mesh(SARD.M3.geo('Cone', [0.95, 1.0, 24]), SARD.M3.mat('torre'));
        techo.position.y = ALTO_TORRE + 1.75;
        torre.add(techo);

        // Linterna: material propio por torre (cada una se enciende o apaga por separado).
        const linterna = new THREE.Mesh(SARD.M3.geo('Sphere', [0.6, 16, 12]),
            new THREE.MeshStandardMaterial({ color: 0xfffaed, emissive: 0xfffaed, emissiveIntensity: 2.2, roughness: 0.4 }));
        linterna.position.y = ALTO_TORRE + 0.85;
        linterna.name = 'linterna';
        torre.add(linterna);

        const resplandor = new THREE.Sprite(new THREE.SpriteMaterial({
            map: SARD.M3.textura('halo'), color: 0xfff6e8, transparent: true, opacity: 0.35,
            blending: THREE.AdditiveBlending, depthWrite: false,
            fog: false, // «luz propia»: el destello atraviesa la niebla aunque la torre se pierda
        }));
        resplandor.scale.set(9, 9, 1);
        resplandor.position.copy(linterna.position);
        resplandor.renderOrder = 5;
        resplandor.name = 'halo-resplandor';
        torre.add(resplandor);

        const nucleo = new THREE.Sprite(new THREE.SpriteMaterial({
            map: SARD.M3.textura('halo'), color: 0xfffaed, transparent: true, opacity: 0.9, depthWrite: false, fog: false,
        }));
        nucleo.scale.set(2.2, 2.2, 1);
        nucleo.position.copy(linterna.position);
        nucleo.renderOrder = 6;
        nucleo.name = 'halo-nucleo';
        torre.add(nucleo);

        // Baliza visible en la base (el color de la luz lo pone quien la crea).
        const balizaMesh = new THREE.Mesh(SARD.M3.geo('Sphere', [0.16, 12, 8]),
            new THREE.MeshStandardMaterial({ color: baliza, emissive: baliza, emissiveIntensity: 1.4 }));
        balizaMesh.position.set(0, 0.9, 1.25);
        balizaMesh.name = 'baliza';
        torre.add(balizaMesh);

        return { torre, linterna, resplandor, nucleo, balizaMesh };
    }

    const Faro = {
        ALTO_TORRE,

        crear(escena, nivel) {
            const { x, z } = SARD.Terreno.POS_FARO;
            const suelo = SARD.Terreno.altura(x, z);

            const grupo = new THREE.Group();
            grupo.name = 'faro';
            grupo.position.set(x, suelo, z);

            const roca = crearRoca();
            roca.position.y = 0.4;
            grupo.add(roca);

            const partes = construirTorre({ nombre: 'torre-lider', baliza: 0xff4433 });
            partes.torre.position.y = 1.2;
            grupo.add(partes.torre);
            Object.assign(Faro, partes, { grupo });

            // Luz de la linterna y baliza de acento (luces compartidas de luces.js).
            const posLinterna = new THREE.Vector3();
            partes.torre.updateMatrixWorld(true);
            grupo.updateMatrixWorld(true);
            partes.linterna.getWorldPosition(posLinterna);
            SARD.Luces.linterna.position.copy(posLinterna).sub(grupo.position);
            SARD.Luces.linterna.position.y += 0.9; // por encima de la linterna: la torre no se tapa a sí misma
            grupo.add(SARD.Luces.linterna);
            SARD.Luces.baliza.position.set(0, 2.3, 2.2);
            grupo.add(SARD.Luces.baliza);

            // Haz: cono aditivo muy tenue (solo calidad 0). Oculto hasta que una escena lo use.
            const haz = new THREE.Mesh(new THREE.ConeGeometry(4.5, 34, 24, 1, true),
                new THREE.MeshBasicMaterial({ color: 0xfff6e8, transparent: true, opacity: 0.05, blending: THREE.AdditiveBlending, depthWrite: false, side: THREE.DoubleSide }));
            haz.geometry.translate(0, -17, 0);
            haz.geometry.rotateZ(Math.PI / 2);
            const pivote = new THREE.Group();
            pivote.position.copy(partes.linterna.position).add(partes.torre.position);
            pivote.add(haz);
            pivote.visible = false;
            pivote.name = 'haz';
            grupo.add(pivote);
            Faro.haz = pivote;
            Faro.hazPermitido = nivel === 0;

            escena.add(grupo);
            return grupo;
        },

        /** Torre gemela (nodo C / seguidor) con baliza #ff750f. La crea S09/S10; la libera quien la crea. */
        crearGemela(posicion) {
            const partes = construirTorre({ nombre: 'torre-gemela', baliza: 0xff750f });
            partes.torre.position.set(posicion.x, SARD.Terreno.altura(posicion.x, posicion.z), posicion.z);
            return partes;
        },

        /**
         * Lleva la linterna a una intensidad de diseño (2.0 – 3.2 en el storyboard) con GSAP:
         * material emisivo, luz puntual y halo cambian juntos.
         */
        intensidad(diseno, duracion = 1.2, ease = 'power2.inOut') {
            const luz = SARD.Luces.linterna;
            const objetivo = { e: diseno, l: SARD.Luces.puntual(diseno), h: Math.min(0.7, 0.17 * diseno) };
            // El último en pedir manda: un tween anterior (p. ej. el del escenario) no debe pisarlo.
            gsap.killTweensOf([Faro.linterna.material, luz, Faro.resplandor.material]);
            if (SARD.Estado.get('reduccionMovimiento') || duracion === 0) {
                Faro.linterna.material.emissiveIntensity = objetivo.e;
                luz.intensity = objetivo.l;
                Faro.resplandor.material.opacity = objetivo.h;
                return null;
            }
            return gsap.timeline()
                .to(Faro.linterna.material, { emissiveIntensity: objetivo.e, duration: duracion, ease }, 0)
                .to(luz, { intensity: objetivo.l, duration: duracion, ease }, 0)
                .to(Faro.resplandor.material, { opacity: objetivo.h, duration: duracion, ease }, 0);
        },

        /** Respiración de la linterna (loop 3 s, 1.8 ↔ 2.4). La escena lo registra como loop. */
        respirar() {
            if (SARD.Estado.get('reduccionMovimiento')) { return null; }
            return gsap.fromTo(Faro.linterna.material, { emissiveIntensity: 1.8 },
                { emissiveIntensity: 2.4, duration: 1.5, ease: 'sine.inOut', yoyo: true, repeat: -1 });
        },

        /**
         * Enciende o apaga la baliza de acento #ff4433 (luz + emisivo). Los capítulos 0–1 la
         * llevan apagada: el acento aparece por primera vez con la solución (S03).
         */
        baliza(encendida, duracion = 0.6) {
            const luz = SARD.Luces.baliza;
            const destino = { l: encendida ? SARD.Luces.puntual(1.2) : 0, e: encendida ? 1.4 : 0 };
            const mat = Faro.balizaMesh.material;
            gsap.killTweensOf([luz, mat]);
            if (SARD.Estado.get('reduccionMovimiento') || duracion === 0) {
                luz.intensity = destino.l;
                mat.emissiveIntensity = destino.e;
                mat.color.setHex(encendida ? 0xff4433 : 0x1d1d1b);
                return null;
            }
            mat.color.setHex(encendida ? 0xff4433 : 0x1d1d1b);
            return gsap.timeline()
                .to(luz, { intensity: destino.l, duration: duracion, ease: 'power2.out' }, 0)
                .to(mat, { emissiveIntensity: destino.e, duration: duracion, ease: 'power2.out' }, 0);
        },

        /** Muestra u oculta el haz (si la calidad lo permite). */
        mostrarHaz(visible) { Faro.haz.visible = visible && Faro.hazPermitido; },

        aplicarCalidad(nivel) {
            Faro.hazPermitido = nivel === 0;
            if (!Faro.hazPermitido) { Faro.haz.visible = false; }
        },
    };

    SARD.Faro = Faro;
})(window.SARD = window.SARD || {});
