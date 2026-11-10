/*
 * S01_ciudad — «Una ciudad que falla por partes» (STORYBOARD §S01 · 45 s).
 *
 * Objetivo: que el jurado diga «pueden pasar al mismo tiempo».
 *
 * Animación → qué explica:
 *   · La ciudad (plataformas escalonadas en las laderas) sube desde el suelo: es un lugar real.
 *   · Tres boyas aparecen escalonadas y laten DESFASADAS pero a la vez: tres emergencias
 *     simultáneas que no esperan turno.
 *   · «SIN SEÑAL» es gris y sin halo: la tercera falla no se ve, solo se nota por ausencia.
 *   · Cauce en azul frío con luz verde-agua tenue: la inundación, sin inventar un color de marca.
 *   · Órbita lenta (±6°); el presentador puede arrastrar (±25°).
 * Salida: las boyas se apagan en cascada (0,25 s entre ellas).
 * Sin contadores: no se inventan métricas.
 */
(function (SARD) {
    'use strict';

    const { Util } = SARD;

    /** Boyas: posición en el relieve y estilo. El color nunca va solo: cada una lleva su etiqueta. */
    const BOYAS = [
        { x: -10, z: 6, color: 0xef4444, halo: true },   // DESLIZAMIENTO (ladera)
        { x: 3, z: -13, color: 0xfbbf24, halo: true },   // INUNDACIÓN (sobre el cauce)
        { x: 16, z: 14, color: 0x57534e, halo: false },  // SIN SEÑAL (gris, sin halo)
    ];

    /** Recorrido del cauce por el fondo del valle hasta el mar. */
    const CAUCE = [[-4, 40], [-2, 22], [1, 6], [3, -10], [4, -24], [5, -34]];

    function crearCiudad() {
        const azar = SARD.M3.aleatorio(2026);
        const CANTIDAD = 150;
        const mat = new THREE.MeshStandardMaterial({ color: 0x232322, roughness: 0.85, metalness: 0.05, transparent: true, opacity: 0 });
        const malla = new THREE.InstancedMesh(SARD.M3.geo('Box', [1, 1, 1]), mat, CANTIDAD);
        const m = new THREE.Matrix4();
        const q = new THREE.Quaternion();
        let n = 0;
        for (let intentos = 0; n < CANTIDAD && intentos < 4000; intentos++) {
            const x = (azar() - 0.5) * 90;
            const z = -14 + azar() * 52;
            const y = SARD.Terreno.altura(x, z);
            // Plataformas escalonadas en las laderas (no en el fondo del cauce ni bajo el mar).
            if (y < 0.6 || Math.abs(x) < 4) { continue; }
            const ancho = 0.8 + azar() * 1.6;
            const alto = 0.8 + azar() * 2.8;
            q.setFromAxisAngle(new THREE.Vector3(0, 1, 0), azar() * Math.PI);
            m.compose(new THREE.Vector3(x, y + alto / 2 - 0.2, z), q, new THREE.Vector3(ancho, alto, ancho * (0.7 + azar() * 0.6)));
            malla.setMatrixAt(n++, m);
        }
        malla.count = n;
        malla.castShadow = true;
        malla.receiveShadow = true;
        malla.name = 'ciudad';
        return malla;
    }

    function crearCauce() {
        const puntos = CAUCE.map(([x, z]) => new THREE.Vector3(x, SARD.Terreno.altura(x, z) + 0.15, z));
        const curva = new THREE.CatmullRomCurve3(puntos);
        const tubo = new THREE.Mesh(new THREE.TubeGeometry(curva, 80, 0.22, 6, false),
            new THREE.MeshStandardMaterial({ color: 0x0d1418, emissive: 0x8fb8d8, emissiveIntensity: 0, roughness: 0.3 }));
        tubo.name = 'cauce';
        const luz = new THREE.PointLight(0x4ade80, 0, 22, 1.6);
        luz.position.copy(curva.getPoint(0.55)).add(new THREE.Vector3(0, 3, 0));
        return { tubo, luz };
    }

    function crearBoya(def) {
        const g = new THREE.Group();
        const y = SARD.Terreno.altura(def.x, def.z);
        g.position.set(def.x, y + 1.1, def.z);
        const esfera = new THREE.Mesh(SARD.M3.geo('Sphere', [0.55, 16, 12]),
            new THREE.MeshStandardMaterial({ color: def.color, emissive: def.color, emissiveIntensity: def.halo ? 1.1 : 0.15 }));
        const anillo = new THREE.Mesh(SARD.M3.geo('Ring', [0.9, 1.05, 40]),
            new THREE.MeshBasicMaterial({ color: def.color, transparent: true, opacity: def.halo ? 0.85 : 0.5, side: THREE.DoubleSide, depthWrite: false }));
        anillo.rotation.x = -Math.PI / 2;
        anillo.position.y = -1.0;
        g.add(esfera, anillo);
        if (def.halo) {
            const halo = new THREE.Sprite(new THREE.SpriteMaterial({ map: SARD.M3.textura('halo'), color: def.color, transparent: true, opacity: 0.45, blending: THREE.AdditiveBlending, depthWrite: false }));
            halo.scale.set(5, 5, 1);
            g.add(halo);
        }
        g.userData = { esfera, anillo };
        return g;
    }

    SARD.Escenas.definir({
        id: 'S01_ciudad',

        escenario: {
            // Centro de las tres boyas; ancho: las tres y sus etiquetas caben junto a la tarjeta.
            encuadre: { pos: [3, 30, 47], objetivo: [3, 2, 0], ancho: 36 },
            niebla: 0.018,      // baja: aquí el problema es visible
            linterna: 1.4,      // faro atenuado
            baliza: false,      // capítulo 1: todavía sin acento
            duracionCamara: 1.8,
            orbita: { azimut: radianes(25), polarMin: 0.6, polarMax: 1.25 },
        },

        plantilla(t) {
            return Util.el('section', { class: 's01' }, [
                Util.el('div', { class: 'panel panel--izquierda panel--compacto pila s01__panel' }, [
                    Util.el('h1', { class: 's01__h1', text: t.h1 }),
                    Util.el('p', { class: 'texto-l s01__cuerpo', text: t.cuerpo }),
                    Util.el('ul', { class: 's01__riesgos' }, t.riesgos.map((r, i) => Util.el('li', { class: 's01__riesgo', dataset: { i: String(i) } }, [
                        Util.el('span', { class: `chip chip--mono s01__chip s01__chip--${i}`, text: r.etiqueta }),
                        Util.el('span', { class: 'caption', text: r.linea }),
                    ]))),
                ]),
            ]);
        },

        cargar(ctx) {
            const g = ctx.grupo3D('ciudad');
            if (!g) { return; }
            ctx.ciudad = crearCiudad();
            const { tubo, luz } = crearCauce();
            ctx.cauce = tubo;
            ctx.luzCauce = luz;
            ctx.boyas = BOYAS.map(crearBoya);
            g.add(ctx.ciudad, tubo, luz, ...ctx.boyas);

            // Etiquetas flotantes: botones reales anclados a cada boya (hover/foco → línea del producto).
            ctx.chips = ctx.textos.riesgos.map((r, i) => {
                const b = Util.el('button', { type: 'button', class: `ancla ancla--chip s01__ancla s01__chip--${i}`, text: r.etiqueta, 'aria-label': `${r.etiqueta}: ${r.linea}` });
                SARD.Tooltip.adjuntar(b, r.linea, ctx.senal);
                return b;
            });
        },

        activar(ctx) {
            const s = ctx.seccion;
            if (ctx.hay3D()) {
                // La salida las apaga: al volver, cada boya recupera su brillo.
                ctx.boyas.forEach((boya, i) => {
                    const mat = boya.userData.esfera.material;
                    gsap.killTweensOf(mat); // un fundido de salida pendiente no debe pisar el estado restaurado
                    mat.emissiveIntensity = BOYAS[i].halo ? 1.1 : 0.15;
                });
                ctx.boyas.forEach((boya, i) => ctx.anclar(boya, ctx.chips[i], { desplazamientoY: 1.6 }));
                // Órbita lenta ±6° cuando termina el encuadre (1,8 s); se detiene si el presentador arrastra.
                SARD.Anim.loop(ctx, SARD.Motor.derivaOrbital(6, 16, 1.9));
                // Latidos desfasados (0,4 s), a la vez: tres emergencias simultáneas.
                if (!ctx.reducido()) {
                    ctx.boyas.forEach((boya, i) => {
                        SARD.Anim.loop(ctx, gsap.fromTo(boya.userData.anillo.scale, { x: 1, y: 1 },
                            { x: 1.9, y: 1.9, duration: 1.2, ease: 'power2.out', yoyo: true, repeat: -1, delay: 2 + i * 0.4 }));
                    });
                    // «Sin señal» parpadea en gris.
                    SARD.Anim.loop(ctx, gsap.to(ctx.boyas[2].userData.esfera.material, { emissiveIntensity: 0.45, duration: 0.6, yoyo: true, repeat: -1, ease: 'steps(1)', delay: 2.4 }));
                }
            }

            SARD.Anim.entrar(ctx, () => {
                const tl = gsap.timeline({ paused: true });
                if (ctx.hay3D()) {
                    // 1. La ciudad emerge del suelo (0–1,2 s).
                    tl.fromTo(ctx.ciudad.position, { y: -6 }, { y: 0, duration: 1.0, ease: 'power2.out' }, 0)
                        .to(ctx.ciudad.material, { opacity: 1, duration: 0.8, ease: 'power1.out' }, 0)
                        .to(ctx.cauce.material, { emissiveIntensity: 0.45, duration: 0.8, ease: 'power1.out' }, 0.4)
                        .to(ctx.luzCauce, { intensity: SARD.Luces.puntual(0.5), duration: 0.8 }, 0.4)
                        // 2. Las boyas aparecen escalonadas (1,0–2,0 s), con su etiqueta en el mismo frame.
                        .from(ctx.boyas.map((b) => b.scale), { x: 0.001, y: 0.001, z: 0.001, duration: 0.5, ease: 'back.out(2)', stagger: 0.22 }, 1.0)
                        // opacity (no autoAlpha): la visibilidad de las anclas la gobierna la proyección.
                        .from(ctx.chips, { opacity: 0, duration: 0.3, stagger: 0.22 }, 1.0);
                }
                // 3. Texto (1,6–2,6 s).
                return tl.from(s.querySelector('.s01__h1'), { x: -24, autoAlpha: 0, duration: 0.7, ease: 'power2.out' }, 1.2)
                    .from(s.querySelector('.s01__cuerpo'), { x: -18, autoAlpha: 0, duration: 0.6, ease: 'power2.out' }, 1.45)
                    .from(s.querySelectorAll('.s01__riesgo'), { autoAlpha: 0, duration: 0.5, ease: 'power1.out', stagger: 0.12 }, 1.7);
            });
        },

        estadoFinal(ctx) { ctx.timeline?.progress(1).pause(); },

        /** Salida: las boyas se apagan en cascada (0,12 s entre ellas, para caber en el fundido de 450 ms). */
        salir(ctx) {
            if (!ctx.hay3D() || ctx.reducido()) { return; }
            ctx.boyas.forEach((b, i) => gsap.to(b.userData.esfera.material, { emissiveIntensity: 0, duration: 0.2, delay: i * 0.12 }));
        },
    });

    /** Grados → radianes sin depender de THREE al definir (puede no estar cargado aún). */
    function radianes(g) { return (g * Math.PI) / 180; }
})(window.SARD = window.SARD || {});
